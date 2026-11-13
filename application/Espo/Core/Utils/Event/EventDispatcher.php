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

namespace Espo\Core\Utils\Event;

use Closure;

/**
 * @since 10.1.0
 */
class EventDispatcher
{
    /**
     * @var array<class-string<Event>, (Closure(Event, Context): void)[]>
     */
    private array $callbacks = [];

    public function __construct(
        private OriginProvider $originProvider,
        private CrossInstanceEventDispatcher $crossInstanceDispatcher,
        private Configuration $configuration,
    ) {}

    /**
     * @template T of Event
     * @param class-string<T> $className
     * @param Closure(T, Context): void $callback
     */
    public function subscribe(string $className, Closure $callback): void
    {
        /** @var Closure(Event, Context): void $callback */

        $this->callbacks[$className] ??= [];
        $this->callbacks[$className][] = $callback;

        if (
            $this->configuration->subscribeToCrossInstanceEvents() &&
            is_subclass_of($className, CrossInstanceEvent::class)
        ) {
            $this->crossInstanceDispatcher->subscribe($className, $callback);
        }
    }

    /**
     * @template T of Event
     * @param class-string<T> $className
     * @param Closure(T, Context): void $callback
     */
    public function unsubscribe(string $className, Closure $callback): void
    {
        /** @var Closure(Event, Context): void $callback */

        if (!array_key_exists($className, $this->callbacks)) {
            return;
        }

        $list = &$this->callbacks[$className];

        $index = array_search($callback, $list);

        if ($index !== false) {
            unset($list[$index]);

            $list = array_values($list);
        }

        if (
            $this->configuration->subscribeToCrossInstanceEvents() &&
            is_subclass_of($className, CrossInstanceEvent::class)
        ) {
            $this->crossInstanceDispatcher->unsubscribe($className, $callback);
        }
    }

    public function dispatch(Event $event): void
    {
        $callbacks = $this->callbacks[$event::class] ?? [];

        $localContext = new Context(
            isLocal: true,
            origin: $this->originProvider->get(),
        );

        foreach ($callbacks as $callback) {
            $callback($event, $localContext);
        }

        if ($event instanceof CrossInstanceEvent) {
            $this->crossInstanceDispatcher->dispatch($event);
        }
    }
}
