<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCatchUp\Services\BusinessDescription;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class BusinessDescriptionTest extends TestCase
{
    private function entity(array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($key) => $values[$key] ?? null);
        return $entity;
    }

    private function service(ChatwootApiClient $api, array $accounts = null): BusinessDescription
    {
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('limit')->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection($accounts ?? [$this->entity(['platformId' => 'p1', 'apiKey' => 'admin-key'])]));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->with(['chatwootAccountId' => 20408, 'status' => 'active'])->willReturn($builder);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->with('ChatwootAccount')->willReturn($repository);
        $em->method('getEntityById')->with('ChatwootPlatform', 'p1')
            ->willReturn($this->entity(['backendUrl' => 'http://chatwoot', 'accessToken' => 'platform-token']));
        return new BusinessDescription($em, $api);
    }

    public function testDraftIsSavedWithTheAccountKeyWhileTheAccountHasNone(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getAccount')->with('http://chatwoot', 'platform-token', 20408)
            ->willReturn(['catch_up' => ['businessDescription' => null]]);
        $api->expects(self::once())->method('updateAccountSettings')
            ->with('http://chatwoot', 'admin-key', 20408, ['business_description' => 'Fotografia de gestantes e família.']);

        self::assertSame(['saved' => true], $this->service($api)->saveDraft(20408, "  Fotografia de gestantes e família.\n"));
    }

    public function testADescriptionWrittenMeanwhileWins(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getAccount')->willReturn(['catch_up' => ['businessDescription' => 'Escrito pela Bruna.']]);
        $api->expects(self::never())->method('updateAccountSettings');

        self::assertSame(['saved' => false, 'skipped' => 'exists'], $this->service($api)->saveDraft(20408, 'Rascunho da IA.'));
    }

    public function testAnAmbiguousAccountIsLeftAlone(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::never())->method('updateAccountSettings');
        $two = [$this->entity(['platformId' => 'p1']), $this->entity(['platformId' => 'p1'])];

        self::assertSame(['saved' => false, 'skipped' => 'account'], $this->service($api, $two)->saveDraft(20408, 'Rascunho.'));
    }

    public function testAnEmptyDraftIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->service($this->createMock(ChatwootApiClient::class))->saveDraft(20408, '   ');
    }
}
