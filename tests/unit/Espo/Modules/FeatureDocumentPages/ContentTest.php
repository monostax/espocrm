<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureDocumentPages;

use Espo\Core\FieldValidation\Exceptions\ValidationError;
use Espo\Core\Record\Input\Data;
use Espo\Modules\FeatureDocumentPages\Classes\Record\NormalizeContentInput;
use Espo\Modules\FeatureDocumentPages\Hooks\Document\PrepareContent;
use Espo\ORM\BaseEntity;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

class ContentTest extends TestCase
{
    private const STATE = '{"root":{"type":"root","children":[{"type":"paragraph","children":[]}]}}';

    private function document(array $values, bool $existing = false): BaseEntity
    {
        $entity = new BaseEntity('Document', ['attributes' => array_fill_keys([
            'contentType', 'fileId', 'body', 'bodyFormat', 'bodyPlain', 'bodyEditorState', 'name',
        ], ['type' => 'text'])]);
        $entity->set($values);

        if ($existing) {
            $entity->setAsNotNew();
            $entity->updateFetchedValues();
        }

        return $entity;
    }

    private function save(BaseEntity $entity, bool $api = false): void
    {
        (new PrepareContent())->beforeSave($entity, SaveOptions::fromAssoc(['api' => $api]));
    }

    public function testLegacyUploadDefaultsToFileAndKeepsAttachment(): void
    {
        $entity = $this->document(['fileId' => 'attachment-1']);
        $this->save($entity);
        $this->assertSame('File', $entity->get('contentType'));
        $this->assertSame('attachment-1', $entity->get('fileId'));
    }

    public function testFileCannotBeSavedWithoutUpload(): void
    {
        $this->expectException(ValidationError::class);
        $this->save($this->document(['contentType' => 'File']));
    }

    public function testRemovingExistingFileIsValidated(): void
    {
        $entity = $this->document(['contentType' => 'File', 'fileId' => 'attachment-1'], true);
        $entity->set('fileId', null);
        $this->expectException(ValidationError::class);
        $this->save($entity);
    }

    public function testEmptyPageCanBeCreatedWithoutFile(): void
    {
        $entity = $this->document(['contentType' => 'Page']);
        $this->save($entity);
        $this->assertSame('Page', $entity->get('contentType'));
        $this->assertNull($entity->get('fileId'));
        $this->assertNull($entity->get('bodyPlain'));
    }

    public function testHtmlPageStoresSearchTextAndEditorState(): void
    {
        $entity = $this->document([
            'contentType' => 'Page', 'body' => '<h1>Guide</h1><p>Hello <strong>world</strong>.</p>',
            'bodyEditorState' => self::STATE,
        ]);
        $this->save($entity);
        $this->assertStringContainsString('Guide', $entity->get('bodyPlain'));
        $this->assertStringContainsString('Hello world.', $entity->get('bodyPlain'));
        $this->assertStringNotContainsString('<', $entity->get('bodyPlain'));
        $this->assertSame(self::STATE, $entity->get('bodyEditorState'));
    }

    public function testMarkdownPageIsConvertedBeforeSearchTextExtraction(): void
    {
        $entity = $this->document([
            'contentType' => 'Page', 'bodyFormat' => 'Markdown',
            'body' => "# Guide\n\n**Welcome** to the [CRM](https://example.com).",
        ]);
        $this->save($entity);
        $this->assertStringContainsString('Welcome to the CRM', $entity->get('bodyPlain'));
        $this->assertStringNotContainsString('**', $entity->get('bodyPlain'));
        $this->assertStringNotContainsString('# Guide', $entity->get('bodyPlain'));
    }

    public function testApiBodyAndFormatUpdateInvalidatesOldSnapshot(): void
    {
        $entity = $this->document([
            'contentType' => 'Page', 'bodyFormat' => 'Html', 'body' => '<p>Old</p>',
            'bodyEditorState' => self::STATE,
        ], true);
        $raw = (object) ['body' => '**New**', 'bodyFormat' => 'Markdown'];
        (new NormalizeContentInput())->filter(new Data($raw));
        $entity->setMultiple($raw);
        $this->save($entity);
        $this->assertNull($entity->get('bodyEditorState'));
        $this->assertSame('New', trim($entity->get('bodyPlain')));
    }

    public function testFormatProjectionFromEditorPreservesUnchangedCanonicalState(): void
    {
        $entity = $this->document([
            'contentType' => 'Page', 'bodyFormat' => 'Html', 'body' => '<p>Content</p>',
            'bodyEditorState' => self::STATE,
        ], true);
        $raw = (object) ['body' => 'Content', 'bodyFormat' => 'Markdown', 'bodyEditorState' => self::STATE];
        (new NormalizeContentInput())->filter(new Data($raw));
        $entity->setMultiple($raw);
        $this->save($entity);
        $this->assertSame(self::STATE, $entity->get('bodyEditorState'));
    }

    public function testMetadataOnlyEditPreservesBodyAndSnapshot(): void
    {
        $entity = $this->document([
            'contentType' => 'Page', 'bodyFormat' => 'Html', 'body' => '<p>Content</p>',
            'bodyPlain' => 'Content', 'bodyEditorState' => self::STATE,
        ], true);
        $raw = (object) ['name' => 'Renamed'];
        (new NormalizeContentInput())->filter(new Data($raw));
        $entity->setMultiple($raw);
        $this->save($entity);
        $this->assertSame(self::STATE, $entity->get('bodyEditorState'));
        $this->assertSame('<p>Content</p>', $entity->get('body'));
    }

    public function testClearingPageClearsSnapshotAndSearchText(): void
    {
        $entity = $this->document([
            'contentType' => 'Page', 'bodyFormat' => 'Html', 'body' => '<p>Content</p>',
            'bodyPlain' => 'Content', 'bodyEditorState' => self::STATE,
        ], true);
        $entity->set('body', null);
        $this->save($entity);
        $this->assertNull($entity->get('bodyEditorState'));
        $this->assertNull($entity->get('bodyPlain'));
    }

    public function testInvalidSnapshotIsRejected(): void
    {
        $this->expectException(ValidationError::class);
        $this->save($this->document(['contentType' => 'Page', 'bodyEditorState' => '{broken']));
    }

    public function testFormatOnlyApiMutationConvertsExistingContent(): void
    {
        $entity = $this->document([
            'contentType' => 'Page', 'bodyFormat' => 'Html', 'body' => '<p><strong>Content</strong></p>',
            'bodyEditorState' => self::STATE,
        ], true);
        $raw = (object) ['bodyFormat' => 'Markdown'];
        (new NormalizeContentInput())->filter(new Data($raw));
        $entity->setMultiple($raw);
        $this->save($entity, true);
        $this->assertSame('**Content**', trim($entity->get('body')));
        $this->assertSame('Content', trim($entity->get('bodyPlain')));
        $this->assertSame(self::STATE, $entity->get('bodyEditorState'));
    }

    public function testResubmittedStateSurvivesHtmlProjectionNormalization(): void
    {
        $entity = $this->document([
            'contentType' => 'Page', 'bodyFormat' => 'Html', 'body' => '<p>Content</p>',
            'bodyEditorState' => self::STATE,
        ], true);
        $raw = (object) [
            'body' => '<p dir="ltr">Content</p>', 'bodyFormat' => 'Html', 'bodyEditorState' => self::STATE,
        ];
        (new NormalizeContentInput())->filter(new Data($raw));
        $entity->setMultiple($raw);
        $this->save($entity, true);
        $this->assertSame(self::STATE, $entity->get('bodyEditorState'));
    }

    public function testUnknownContentTypeCannotBypassFileRequirement(): void
    {
        $this->expectException(ValidationError::class);
        $this->save($this->document(['contentType' => 'Other']));
    }
}
