<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage;

use Espo\Core\Utils\File\{Manager, Unifier, UnifierObj};
use Espo\Core\Utils\Database\Orm\LinkConverters\BelongsTo;
use Espo\Core\Utils\Metadata\Builder;
use Espo\Core\Utils\Module;
use Espo\Core\Utils\Module\PathProvider as ModulePathProvider;
use Espo\Core\Utils\Resource\{PathProvider, Reader};
use Espo\Core\Utils\Util;
use Espo\ORM\BaseEntity;
use Espo\ORM\Defs\RelationDefs;
use Espo\ORM\EntityFactory;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use PDO;
use PHPUnit\Framework\TestCase;

class MetadataTest extends TestCase
{
    private function metadata(): \stdClass
    {
        $files = new Manager();
        $module = new Module($files);
        $paths = new PathProvider(new ModulePathProvider($module));
        $reader = new Reader(new Unifier($files, $module, $paths), new UnifierObj($files, $module, $paths));
        return (new Builder($reader))->build();
    }

    public function testRouteScopeAssetsAndRateModelMergeIntoTheActualModuleStack(): void
    {
        $metadata = $this->metadata();
        $this->assertFalse($metadata->scopes->AiUsage->entity);
        $this->assertSame('feature-ai-usage:controllers/ai-usage', $metadata->clientDefs->AiUsage->controller);
        $this->assertFalse($metadata->scopes->AiUsage->tab);
        $this->assertContains('client/custom/modules/feature-ai-usage/css/ai-usage.css', $metadata->app->client->cssList);
        $rate = $metadata->entityDefs->TenantAiBillingRate;
        $this->assertSame(['', 'pack199', 'extra049', 'credit'], $rate->fields->billingModel->options);
        $this->assertSame('Tenant', $rate->links->tenant->entity);
        $this->assertTrue($metadata->entityAcl->TenantAiBillingRate->fields->billingModel->onlyAdmin);
        $outcome = $metadata->entityDefs->ChatwootAiAgentRun->fields->runOutcome;
        $this->assertTrue($outcome->notStorable);
        $this->assertSame('AI_RUN_OUTCOME:modelUsage', $outcome->select->select);
        foreach (['Mysql', 'Postgresql'] as $platform) {
            $class = $metadata->app->orm->platforms->{$platform}->functionConverterClassNameMap->AI_RUN_OUTCOME;
            $this->assertTrue(is_a($class, \Espo\ORM\QueryComposer\Part\FunctionConverter::class, true));
        }
    }

    public function testDetailRunLookupWorksWithAndWithoutSourceNote(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for the disposable query dataset.');
        }
        $link = Util::objectToArray($this->metadata()->entityDefs->ChatwootAiAgentRun->links->sourceNote);
        $defs = (new BelongsTo())->convert(RelationDefs::fromRaw($link, 'sourceNote'), 'ChatwootAiAgentRun')->toAssoc();
        $defs['attributes']['id'] = ['type' => 'varchar'];
        $defs['attributes']['deleted'] = ['type' => 'bool'];
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn([
            'ChatwootAiAgentRun' => $defs,
            'Note' => ['attributes' => ['id' => ['type' => 'varchar'], 'deleted' => ['type' => 'bool']]],
        ]);
        $metadata = new Metadata($provider);
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $metadata->get($type)));
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE chatwoot_ai_agent_run (id TEXT, source_note_id TEXT, deleted INTEGER DEFAULT 0)');
        $pdo->exec('CREATE TABLE note (id TEXT, deleted INTEGER DEFAULT 0)');
        $pdo->exec("INSERT INTO note (id) VALUES ('note')");
        $pdo->exec("INSERT INTO chatwoot_ai_agent_run (id, source_note_id) VALUES ('linked', 'note'), ('unlinked', NULL)");
        $composer = new MysqlQueryComposer($pdo, $factory, $metadata);

        foreach (['linked' => 'note', 'unlinked' => null] as $id => $noteId) {
            $query = SelectBuilder::create()->from('ChatwootAiAgentRun')->where(['id' => $id])->build();
            $row = $pdo->query($composer->composeSelect($query))->fetch(PDO::FETCH_ASSOC);
            $this->assertSame($id, $row['id']);
            $this->assertSame($noteId, $row['sourceNoteId']);
        }
    }
}
