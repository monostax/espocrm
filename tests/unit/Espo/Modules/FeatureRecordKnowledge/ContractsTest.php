<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureRecordKnowledge;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\File\Manager;
use Espo\Core\Utils\File\Unifier;
use Espo\Core\Utils\File\UnifierObj;
use Espo\Core\Utils\Metadata\Builder;
use Espo\Core\Utils\Module;
use Espo\Core\Utils\Module\PathProvider as ModulePathProvider;
use Espo\Core\Utils\Resource\PathProvider;
use Espo\Core\Utils\Resource\Reader;
use Espo\ORM\BaseEntity;
use Espo\Modules\FeatureRecordKnowledge\Tools\Evidence;
use Espo\Modules\FeatureRecordKnowledge\Tools\Markdown;
use Espo\Modules\FeatureRecordKnowledge\Tools\Predicates;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;
use Espo\Modules\FeatureRecordKnowledge\Classes\Metadata\RecordKnowledge;
use Espo\Modules\FeatureRecordKnowledge\Hooks\Document\MarkdownSource;
use Espo\Modules\FeatureRecordKnowledge\Hooks\DocumentRevision\Immutable;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;
use Espo\Modules\FeatureDocumentPages\Hooks\Document\PrepareContent;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

class ContractsTest extends TestCase
{
    public function testMergedMetadataCoversCustomScopesAndTechnicalEntitiesStayExcluded(): void
    {
        $files = new Manager();
        $module = new Module($files);
        $paths = new PathProvider(new ModulePathProvider($module));
        $reader = new Reader(new Unifier($files, $module, $paths), new UnifierObj($files, $module, $paths));
        $data = (new Builder($reader))->build();
        $data->scopes->CustomProject = (object) ['entity' => true, 'object' => true, 'tab' => true];
        $data->entityDefs->CustomProject = (object) ['fields' => (object) ['name' => (object) ['type' => 'varchar']]];
        (new RecordKnowledge())->build($data);
        foreach (['Account', 'Contact', 'Opportunity', 'Document', 'CustomProject'] as $type) {
            $this->assertContains($type, $data->app->recordKnowledge->supportedScopes);
            $this->assertTrue($data->entityDefs->$type->transactionalSave);
            $this->assertCount(1, array_filter($data->clientDefs->$type->bottomPanels->detail, fn ($p) => $p->name === 'overview'));
        }
        foreach (['RecordDocument', 'RecordRelation', 'DocumentRevision', 'EditorReferenceIndex', 'AgentMode', 'AgentSkill', 'ChatwootSyncState', 'PushNotificationQueue', 'UserApiKey'] as $type) {
            $this->assertNotContains($type, Scopes::discover($data));
        }
        $this->assertTrue($data->entityDefs->RecordDocument->indexes->record->unique);
        $this->assertTrue($data->entityDefs->DocumentRevision->indexes->revision->unique);
        $this->assertTrue($data->entityDefs->RecordRelation->indexes->submission->unique);
    }

    public function testMarkdownIsPreservedThroughNormalizationSearchProjectionAndArtifactRoundTrip(): void
    {
        $body = "---\ntitle: Test\n---\n\n<!-- comment -->\n| A | B |\n| - | - |\n| x | y |\n\n[Acme](#crm-reference/v1/record/Account/acme)  \n\n";
        $this->assertSame($body, Markdown::source($body));
        $this->assertSame($body, Markdown::import(Markdown::export($body, 'Account', 'acme', 'doc'), 'Account', 'acme', 'doc'));
        $entity = new BaseEntity('Document', ['attributes' => array_fill_keys([
            'contentType', 'body', 'bodyFormat', 'bodyEditorState', 'bodyAuthoringMode', 'bodyPlain',
        ], ['type' => 'text'])]);
        $entity->set(['contentType' => 'Page', 'body' => $body, 'bodyFormat' => 'Html', 'bodyEditorState' => 'stale', 'bodyAuthoringMode' => 'Markdown']);
        (new MarkdownSource())->beforeSave($entity, []);
        (new PrepareContent())->beforeSave($entity, SaveOptions::fromAssoc([]));
        $this->assertSame($body, $entity->get('body'));
        $this->assertSame('Markdown', $entity->get('bodyFormat'));
        $this->assertNull($entity->get('bodyEditorState'));
        $this->assertStringContainsString('Acme', $entity->get('bodyPlain'));
    }

    public function testArtifactCannotOverwriteAnotherRecord(): void
    {
        $this->expectException(BadRequest::class);
        Markdown::import(Markdown::export('body', 'Account', 'acme', 'doc'), 'Account', 'other', 'doc');
    }

    public function testMarkdownReferenceExtractionUsesParsedLinksAndCanonicalSource(): void
    {
        $body = "[Acme](#crm-reference/v1/record/Account/acme)\n\n[Custom][project]\n\n[project]: #crm-reference/v1/record/CustomProject/project1\n\n" .
            "`[Inline](#crm-reference/v1/record/Account/inline)`\n\n~~~md\n[Fenced](#crm-reference/v1/record/Account/fenced)\n~~~\n\n" .
            "    [Indented](#crm-reference/v1/record/Account/indented)\n\n\\[Escaped](#crm-reference/v1/record/Account/escaped)\n";
        $refs = References::fromMarkdown($body);
        $this->assertSame(['acme', 'project1'], array_column($refs, 'recordId'));
        $entity = new BaseEntity('Document', ['attributes' => array_fill_keys(['body', 'bodyFormat', 'bodyEditorState', 'bodyAuthoringMode'], ['type' => 'text'])]);
        $entity->set(['body' => $body, 'bodyFormat' => 'Markdown', 'bodyAuthoringMode' => 'Markdown',
            'bodyEditorState' => json_encode(['root' => ['children' => []]])]);
        $this->assertSame($refs, References::fromEntity($entity));
    }

    public function testEvidenceUsesExactUtf8ByteSpansAndAmbiguousQuotesNeedASpan(): void
    {
        $body = "João works at Acme. João works at Acme.";
        $quote = 'João works at Acme.';
        $this->assertSame([0, strlen($quote)], Evidence::validate($body, $quote, 0, strlen($quote)));
        $this->assertNull(Evidence::anchor($body, $quote));
        $this->assertSame([7, 11], Evidence::anchor('prefix Acme suffix', 'Acme'));
        $this->expectException(BadRequest::class);
        Evidence::validate($body, 'Fabricated evidence', 0, 19);
    }

    public function testPredicateAliasesAndDatesAreCanonical(): void
    {
        [$name, $qualifiers] = Predicates::validate('employed_by', 'Contact', 'Account', (object) ['role' => 'CTO', 'since' => '2026-10-02']);
        $this->assertSame('works_at', $name);
        $this->assertSame(['role' => 'CTO', 'since' => '2026-10-02'], (array) $qualifiers);
        $this->assertCount(11, Predicates::schema());
    }

    public function testInvalidPredicatesEndpointsQualifiersAndDatesAreRejected(): void
    {
        foreach ([['unknown', 'Contact', 'Account', []], ['works_at', 'Account', 'Contact', []],
            ['works_at', 'Contact', 'Account', ['invented' => 'x']], ['works_at', 'Contact', 'Account', ['since' => '2026-02-30']],
            ['works_at', 'Contact', 'Account', ['since' => '2026-10-02', 'until' => '2020-01-01']]] as $input) {
            try { Predicates::validate(...$input); $this->fail('Invalid claim accepted.'); } catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }

    public function testImmutableSnapshotsCannotBeUpdated(): void
    {
        $entity = new BaseEntity('DocumentRevision', []);
        $entity->setAsNotNew();
        $this->expectException(Forbidden::class);
        (new Immutable())->beforeSave($entity, []);
    }
}
