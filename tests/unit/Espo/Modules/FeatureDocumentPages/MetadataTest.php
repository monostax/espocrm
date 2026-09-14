<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureDocumentPages;

use Espo\Core\Utils\File\Manager;
use Espo\Core\Utils\File\Unifier;
use Espo\Core\Utils\File\UnifierObj;
use Espo\Core\Utils\Metadata\Builder;
use Espo\Core\Utils\Module;
use Espo\Core\Utils\Module\PathProvider as ModulePathProvider;
use Espo\Core\Utils\Resource\PathProvider;
use Espo\Core\Utils\Resource\Reader;
use Espo\Modules\FeatureDocumentPages\Classes\RebuildActions\BackfillContentType;
use Espo\Modules\FeatureDocumentPages\Classes\Record\NormalizeContentInput;
use PHPUnit\Framework\TestCase;

class MetadataTest extends TestCase
{
    public function testFeatureMergesWithCoreAndExistingCrmExtensions(): void
    {
        $files = new Manager();
        $module = new Module($files);
        $paths = new PathProvider(new ModulePathProvider($module));
        $reader = new Reader(new Unifier($files, $module, $paths), new UnifierObj($files, $module, $paths));
        $metadata = (new Builder($reader))->build();
        $document = $metadata->entityDefs->Document;

        $this->assertFalse($document->fields->file->required);
        $this->assertTrue($document->fields->teams->required);
        $this->assertSame('File', $document->fields->contentType->default);
        $this->assertTrue($metadata->scopes->Document->acl);
        $this->assertTrue($document->optimisticConcurrencyControl);
        foreach (['accounts', 'contacts', 'leads', 'opportunities', 'chatwootConversations', 'folder'] as $link) {
            $this->assertNotEmpty($document->links->$link);
        }
        $this->assertSame('feature-document-pages:views/document/list', $metadata->clientDefs->Document->views->list);
        $this->assertNotEmpty($metadata->clientDefs->Document->viewSetupHandlers->list);
        $this->assertSame('feature-document-pages:views/document/modals/select-files',
            $metadata->clientDefs->Attachment->sourceDefs->Document->insertModalView);
        $this->assertContains(NormalizeContentInput::class, $metadata->recordDefs->Document->updateInputFilterClassNameList);
        $this->assertContains(BackfillContentType::class, $metadata->app->rebuild->actionClassNameList);
        $this->assertNotContains('__APPEND__', $metadata->app->rebuild->actionClassNameList);
        $this->assertFileExists($metadata->app->jsLibs->{'lexical-kb'}->path);
        $this->assertContains('client/custom/modules/feature-knowledge-base-editor/css/kb-lexical.css',
            $metadata->app->client->cssList);
    }
}
