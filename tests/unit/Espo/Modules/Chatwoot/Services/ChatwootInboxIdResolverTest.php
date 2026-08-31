<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Modules\Chatwoot\Services\ChatwootInboxIdResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

// vendor/ is installed --no-dev, so autoload-dev PSR-4 is unavailable;
// PHPUnit only includes *Test.php files, so shared helpers need an explicit require.
require_once __DIR__ . '/../Support/EntityDouble.php';

/**
 * The integration's `chatwootInboxId` attribute is the linked ChatwootInbox's
 * Espo row id, not the numeric Chatwoot id. Casting it with (int) yielded a
 * constant 6 (every Espo id begins with "6"), which silently targeted the wrong
 * inbox in REST calls. These tests pin the correct resolution.
 */
class ChatwootInboxIdResolverTest extends TestCase
{
    /**
     * @param array<string, mixed> $attributes
     */
    private function entity(array $attributes): Entity
    {
        return new EntityDouble($attributes, 'ChatwootInboxIntegration');
    }

    private function resolver(?Entity $byId = null): ChatwootInboxIdResolver
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($byId);

        return new ChatwootInboxIdResolver($em);
    }

    /**
     * Happy path: the linked record carries the real numeric id.
     */
    public function testPrefersLinkedInboxNumericId(): void
    {
        $channel = $this->entity([
            'chatwootInbox' => $this->entity(['chatwootInboxId' => 80]),
            'chatwootInboxId' => '6a95c5059bb2b709f',
        ]);

        $this->assertSame(80, $this->resolver()->resolve($channel));
    }

    /**
     * The bug: without resolution this returned (int) '6a95c5059bb2b709f' === 6.
     */
    public function testEspoRowIdIsNeverCastToAnInboxId(): void
    {
        $espoRowId = '6a95c5059bb2b709f';
        $this->assertSame(6, (int) $espoRowId, 'guards the premise of this test');

        $channel = $this->entity(['chatwootInboxId' => $espoRowId]);

        // Nothing found by id, and no back-reference lookup requested.
        $this->assertNull($this->resolver(null)->resolve($channel));
    }

    /**
     * Falls back to loading the referenced ChatwootInbox record.
     */
    public function testResolvesViaReferencedInboxRecord(): void
    {
        $channel = $this->entity(['chatwootInboxId' => '6a95c5059bb2b709f']);
        $inbox = $this->entity(['chatwootInboxId' => 65]);

        $this->assertSame(65, $this->resolver($inbox)->resolve($channel));
    }

    /**
     * Legacy in-request writes assign the numeric id directly; that value is
     * usable but never survives a reload because the attribute is not storable.
     */
    public function testAcceptsNumericValueFromInRequestWrite(): void
    {
        $channel = $this->entity(['chatwootInboxId' => 42]);

        $this->assertSame(42, $this->resolver()->resolve($channel));
    }

    public function testReturnsNullWhenNothingIsResolvable(): void
    {
        $this->assertNull($this->resolver()->resolve($this->entity([])));
    }

    /**
     * A linked record without a numeric id must not be trusted.
     */
    public function testIgnoresLinkedInboxWithoutNumericId(): void
    {
        $channel = $this->entity([
            'chatwootInbox' => $this->entity(['chatwootInboxId' => null]),
        ]);

        $this->assertNull($this->resolver()->resolve($channel));
    }
}
