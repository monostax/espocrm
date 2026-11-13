<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Utils\Event\Redis;

use Closure;
use Espo\Core\Utils\Event\CrossInstanceEvent;
use Espo\Core\Utils\Event\Envelope;
use Espo\Core\Utils\Event\EventTransport;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use LogicException;
use Predis\Connection\ConnectionException;
use RuntimeException;
use stdClass;
use Throwable;

class RedisEventTransport implements EventTransport
{
    private const string STREAM_NAME = 'espocrm:events';

    private const int COUNT = 50;
    private const int MAX_STREAM_LENGTH = 10000;

    /**
     * @var ?(Closure(Envelope): void) $callback
     */
    private ?Closure $callback = null;

    private ?string $lastId = null;

    public function __construct(
        private ClientProvider $clientProvider,
        private Log $log,
    ) {}

    public function subscribe(Closure $callback): void
    {
        $this->callback = $callback;
    }

    /**
     * @noinspection PhpRedundantCatchClauseInspection
     */
    public function publish(Envelope $envelope): void
    {
        $json = Json::encode([
            'eventClassName' => $envelope->eventClassName,
            'payload' => $envelope->payload,
            'origin' => $envelope->origin,
        ]);

        try {
            $this->publishRaw($json);
        } catch (ConnectionException $e) {
            $this->log->info("Redis connection lost, reconnecting.", ['exception' => $e]);

            $this->clientProvider->reconnect();

            $this->publishRaw($json);
        }
    }

    public function tick(): void
    {
        $client = $this->clientProvider->get();

        if ($this->lastId === null) {
            $response = $client->xread(1, null, [self::STREAM_NAME], '+');

            $this->lastId = '0';

            foreach (($response[self::STREAM_NAME] ?? []) as $item) {
                [$messageId] = $this->getMessageData($item);

                $this->lastId = $messageId;

                break;
            }
        }

        $response = $client->xread(self::COUNT, null, [self::STREAM_NAME], $this->lastId);

        $jsonItems = [];

        foreach (($response[self::STREAM_NAME] ?? []) as $item) {
            [$messageId, $json] = $this->getMessageData($item);

            $this->lastId = $messageId;

            $jsonItems[] = $json;
        }

        if (!$this->callback) {
            return;
        }

        foreach ($jsonItems as $json) {
            $this->processMessageItem($json);
        }
    }

    private function processMessageItem(string $json): void
    {
        if (!$this->callback) {
            throw new LogicException();
        }

        $data = Json::decode($json);

        /** @var ?class-string<CrossInstanceEvent> $eventClassName */
        $eventClassName = $data->eventClassName ?? null;
        $payload = $data->payload ?? null;
        $origin = $data->origin ?? null;

        if (!is_string($eventClassName)) {
            throw new RuntimeException();
        }

        if (!$payload instanceof stdClass) {
            throw new RuntimeException();
        }

        if (!is_string($origin)) {
            throw new RuntimeException();
        }

        $envelope = new Envelope(
            eventClassName: $eventClassName,
            payload: $payload,
            origin: $origin,
        );

        try {
            ($this->callback)($envelope);
        } catch (Throwable $e) {
            $this->log->error("Event callback error, {eventClassName}.", [
                'exception' => $e,
                'eventClassName' => $eventClassName,
                'origin' => $origin,
            ]);
        }
    }

    private function publishRaw(string $json): void
    {
        $client = $this->clientProvider->get();

        $options = [
            'trim' => ['MAXLEN', self::MAX_STREAM_LENGTH],
        ];

        // Magic method is used.
        $client->xadd(self::STREAM_NAME, ['data' => $json], '*', $options);
    }

    /**
     * @param array<int, mixed> $item
     * @return array{string, string}
     */
    private function getMessageData(mixed $item): array
    {
        $messageId = $item[0] ?? null;
        $json = $item[1][1] ?? null;

        if ($messageId === null) {
            throw new RuntimeException("Bad message data, no ID.");
        }

        if (!is_string($json)) {
            throw new RuntimeException("Bad message data.");
        }

        return [$messageId, $json];
    }
}
