<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCatchUp\Services\Parties;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class PartiesTest extends TestCase
{
    private function entity(array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        return $entity;
    }

    private function parties(ChatwootApiClient $api, bool $definitionCached = false): Parties
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => match ($type) {
            'ChatwootAccount' => $id === 'crm-account' ? $this->entity(['apiKey' => 'key', 'platformId' => 'p', 'chatwootAccountId' => 12]) : null,
            'ChatwootPlatform' => $this->entity(['backendUrl' => 'http://chatwoot']),
        });
        $cache = $this->createMock(DataCache::class);
        $kept = $definitionCached ? ['catchUp/partyDefinition/12' => true] : [];
        $cache->method('has')->willReturnCallback(function ($key) use (&$kept) { return isset($kept[$key]); });
        $cache->method('store')->willReturnCallback(function ($key, $value) use (&$kept): void { $kept[$key] = $value; });
        return new Parties($em, $api, $cache, $this->createMock(Log::class));
    }

    private static function asked(string $id, int $accountId = 12): array
    {
        return ['id' => $id, 'classify' => true, 'account' => 'crm-account', 'accountId' => $accountId];
    }

    public function testLabelsMapToClassesAndUnknownValuesAreNoClass(): void
    {
        self::assertSame('team', Parties::classOf(['custom_attributes' => ['em_dia_tipo' => 'Equipe']]));
        self::assertSame('automated', Parties::classOf(['custom_attributes' => ['em_dia_tipo' => 'Automático']]));
        self::assertNull(Parties::classOf(['custom_attributes' => ['em_dia_tipo' => 'Médica']]));
        self::assertNull(Parties::classOf(null));
    }

    public function testFillsOnlyEmptyFieldsOfContactsTheAnalysisWasAskedAbout(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('listCustomAttributeDefinitions')->willReturn([]);
        $api->expects(self::once())->method('createCustomAttributeDefinition')
            ->with('http://chatwoot', 'key', 12, self::callback(fn ($d) => $d['attribute_key'] === 'em_dia_tipo' && $d['attribute_display_type'] === 'list'));
        $api->method('getContact')->willReturnCallback(fn ($url, $key, $account, $id) => ['payload' => ['custom_attributes' =>
            $id === 20098 ? ['em_dia_tipo' => 'Cliente'] : []]]);
        $writes = [];
        $api->method('updateContact')->willReturnCallback(function ($url, $key, $account, $id, $data) use (&$writes) {
            $writes[] = [$account, $id, $data];
            return [];
        });
        $stored = $this->parties($api)->store(
            [self::asked('20094'), self::asked('20098'), ['id' => '20289', 'classify' => false, 'account' => 'crm-account', 'accountId' => 12]],
            [['id' => '20094', 'class' => 'team'], ['id' => '20098', 'class' => 'team'], ['id' => '20289', 'class' => 'team'], ['id' => '1', 'class' => 'team'],
             ['id' => '20094', 'class' => 'boss']],
        );
        self::assertSame([[12, 20094, ['custom_attributes' => ['em_dia_tipo' => 'Equipe']]]], $writes,
            'a person labeled 20098 meanwhile; 20289 and 1 were not asked about; "boss" is no class');
        self::assertSame(['20094' => 'team', '20098' => 'customer'], $stored);
    }

    public function testNeverLabelsAContactOutsideItsOwnAccount(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::never())->method('getContact');
        $api->expects(self::never())->method('updateContact');
        $stored = $this->parties($api, true)->store([self::asked('20094', 99)], [['id' => '20094', 'class' => 'team']]);
        self::assertSame([], $stored);
    }
}
