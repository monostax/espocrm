<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\GoogleGemini;

use Espo\Core\Utils\Log;
use Espo\Modules\GoogleGemini\Jobs\IndexArticle;
use Espo\Modules\GoogleGemini\Jobs\ProcessUploadOperations;
use Espo\Modules\GoogleGemini\Services\GeminiFileSearchService;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class IndexPublicationTest extends TestCase
{
    private function entity(array &$values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($values['id'] ?? 'fixture');
        // Capture by reference: later get() calls must see publication changes.
        $entity->method('get')->willReturnCallback(function ($key) use (&$values) { return $values[$key] ?? null; });
        $entity->method('set')->willReturnCallback(function ($key, $value = null) use (&$values, $entity) {
            if (is_array($key)) { $values = array_merge($values, $key); } else { $values[$key] = $value; }
            return $entity;
        });
        return $entity;
    }

    private function publication(array &$articleData, array $rows): array
    {
        $article = $this->entity($articleData);
        $entities = [];
        foreach ($rows as &$row) { $entities[] = $this->entity($row); }
        unset($row);
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('order')->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection($entities));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($builder);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($article);
        $em->method('getRDBRepository')->willReturn($repository);
        $service = $this->createMock(GeminiFileSearchService::class);
        return [new ProcessUploadOperations($em, $service, $this->createMock(Log::class)), $service, $em];
    }

    public function testFailedReplacementPreservesUsableDocumentAndAttachments(): void
    {
        $data = ['geminiDocumentName' => 'old-body', 'geminiAttachmentDocuments' => [['documentName' => 'old-file']]];
        [$job, $service] = $this->publication($data, [
            ['status' => 'Failed', 'errorMessage' => 'Superseded: retained until replacement', 'geminiDocumentName' => 'old-body'],
            ['status' => 'Completed', 'documentType' => 'ArticleBody', 'geminiDocumentName' => 'new-body'],
            ['status' => 'Failed', 'documentType' => 'Attachment', 'errorMessage' => 'provider unavailable'],
        ]);
        $service->expects($this->never())->method('deleteDocument');
        (new ReflectionMethod($job, 'updateArticleStatusIfComplete'))->invoke($job, 'article');
        $this->assertSame('Failed', $data['geminiIndexStatus']);
        $this->assertSame('old-body', $data['geminiDocumentName']);
        $this->assertSame([['documentName' => 'old-file']], $data['geminiAttachmentDocuments']);
    }

    public function testSuccessfulReplacementPublishesBeforeRetiringOldDocuments(): void
    {
        $data = ['geminiDocumentName' => 'old-body', 'geminiAttachmentDocuments' => [['documentName' => 'old-file']]];
        [$job, $service, $em] = $this->publication($data, [
            ['status' => 'Failed', 'errorMessage' => 'Superseded: retained until replacement', 'geminiDocumentName' => 'old-other-store'],
            ['status' => 'Completed', 'documentType' => 'ArticleBody', 'geminiDocumentName' => 'new-body'],
        ]);
        $published = false;
        $em->method('saveEntity')->willReturnCallback(function ($entity) use (&$published) {
            if ($entity->get('geminiIndexStatus') === 'Indexed') { $published = true; }
            return true;
        });
        $deleted = [];
        $service->expects($this->exactly(3))->method('deleteDocument')->willReturnCallback(function ($name) use (&$deleted, &$published) {
            $this->assertTrue($published);
            $deleted[] = $name;
            return true;
        });
        (new ReflectionMethod($job, 'updateArticleStatusIfComplete'))->invoke($job, 'article');
        $this->assertSame('new-body', $data['geminiDocumentName']);
        $this->assertSame('Indexed', $data['geminiIndexStatus']);
        $this->assertEqualsCanonicalizing(['old-body', 'old-file', 'old-other-store'], $deleted);
    }

    public function testPendingReplacementDoesNotPublishOrDelete(): void
    {
        $data = ['geminiDocumentName' => 'old-body'];
        [$job, $service, $em] = $this->publication($data, [
            ['status' => 'Completed', 'documentType' => 'ArticleBody', 'geminiDocumentName' => 'new-body'],
            ['status' => 'Processing', 'documentType' => 'Attachment'],
        ]);
        $em->expects($this->never())->method('saveEntity');
        $service->expects($this->never())->method('deleteDocument');
        (new ReflectionMethod($job, 'updateArticleStatusIfComplete'))->invoke($job, 'article');
    }

    public function testDiscardedOlderUploadDoesNotBlockNewPublication(): void
    {
        $data = ['geminiDocumentName' => null];
        [$job] = $this->publication($data, [
            ['status' => 'Processing', 'discardDocument' => true],
            ['status' => 'Completed', 'documentType' => 'ArticleBody', 'geminiDocumentName' => 'new-body'],
        ]);
        (new ReflectionMethod($job, 'updateArticleStatusIfComplete'))->invoke($job, 'article');
        $this->assertSame('Indexed', $data['geminiIndexStatus']);
    }

    public function testSuccessWithoutDocumentNameIsFailureNotEmptyPublication(): void
    {
        $data = ['id' => 'operation'];
        $op = $this->entity($data);
        $em = $this->createMock(EntityManager::class);
        $job = new ProcessUploadOperations($em, $this->createMock(GeminiFileSearchService::class), $this->createMock(Log::class));
        (new ReflectionMethod($job, 'handleCompletedOperation'))->invoke($job, $op, ['done' => true], null);
        $this->assertSame('Failed', $data['status']);
    }

    public function testCategoryScopeRequiresExactStoreBoundary(): void
    {
        $job = (new \ReflectionClass(IndexArticle::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($job, 'isInAllowedStore');
        $this->assertTrue($method->invoke($job, 'fileSearchStores/allowed/documents/old', ['fileSearchStores/allowed']));
        $this->assertFalse($method->invoke($job, 'fileSearchStores/allowed-other/documents/old', ['fileSearchStores/allowed']));
        $this->assertFalse($method->invoke($job, 'fileSearchStores/removed/documents/old', ['fileSearchStores/allowed']));
    }
}
