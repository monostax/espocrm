<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureKnowledgeBaseEditor;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\FeatureKnowledgeBaseEditor\Hooks\KnowledgeBaseArticle\KeepRevision;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class KeepRevisionTest extends TestCase
{
    private function article(?array $fetched, array $changes): CoreEntity
    {
        $article = new CoreEntity('KnowledgeBaseArticle', ['attributes' => array_fill_keys(
            ['id', 'name', 'status', 'bodyFormat', 'body', 'description'], ['type' => 'varchar'])]);
        if ($fetched !== null) {
            $article->set(['id' => 'a1', ...$fetched]);
            $article->setAsNotNew();
            $article->setAsFetched();
        }
        $article->set($changes);
        return $article;
    }

    /** @return list<array<string, mixed>> revisions the hook stored */
    private function stored(CoreEntity $article): array
    {
        $created = [];
        $builder = $this->createMock(RDBSelectBuilder::class);
        foreach (['select', 'where', 'order', 'limit'] as $method) $builder->method($method)->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection([]));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('select')->willReturn($builder);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repository);
        $em->method('createEntity')->willReturnCallback(function (string $type, array $data) use (&$created, $article) {
            $this->assertSame('KnowledgeBaseArticleRevision', $type);
            unset($data['createdAt']);
            $created[] = $data;
            return $article;
        });
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('u1');
        (new KeepRevision($em, $user))->beforeSave($article, []);
        return $created;
    }

    private const BEFORE = ['name' => 'Preços', 'status' => 'Published', 'bodyFormat' => 'Html', 'body' => '<p>R$ 150</p>'];

    public function testChangedTextKeepsWhatTheArticleSaidBefore(): void
    {
        $this->assertSame(
            [['articleId' => 'a1', ...self::BEFORE, 'createdById' => 'u1']],
            $this->stored($this->article(self::BEFORE, ['body' => '<p>R$ 200</p>'])),
        );
    }

    public function testStatusChangeIsKeptToo(): void
    {
        $this->assertCount(1, $this->stored($this->article(self::BEFORE, ['status' => 'Archived'])));
    }

    public function testNewArticlesAndUnrelatedChangesKeepNothing(): void
    {
        $this->assertSame([], $this->stored($this->article(null, self::BEFORE)));
        $this->assertSame([], $this->stored($this->article(self::BEFORE, ['description' => 'x'])));
        $this->assertSame([], $this->stored($this->article(self::BEFORE, ['body' => '<p>R$ 150</p>'])));
    }
}
