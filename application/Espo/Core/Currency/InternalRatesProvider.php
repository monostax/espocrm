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

namespace Espo\Core\Currency;

use Espo\Core\Field\Date;
use Espo\Core\Utils\Cache\DataCacheAccess;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Event\EventDispatcher;
use Espo\Tools\Currency\Events\CurrencyRateUpdate;
use LogicException;
use RuntimeException;
use stdClass;

/**
 * @internal
 */
class InternalRatesProvider
{
    private string $cacheKey = 'currencyRates';

    private ?Date $today = null;
    private ?string $base = null;

    /**
     * @param DataCacheAccess<stdClass> $dataCacheAccess
     */
    public function __construct(
        private DateTime $dateTime,
        private InternalRateEntryProvider $rateEntryProvider,
        private DataCacheAccess $dataCacheAccess,
        private EventDispatcher $eventDispatcher,
    ) {
        $this->dataCacheAccess->init(
            key: $this->cacheKey,
            loader: function () {
                if (!$this->today || $this->base === null) {
                    throw new LogicException();
                }

                return $this->buildData($this->today, $this->base);
            },
            validityChecker: function (stdClass $data): bool {
                if (!$this->today) {
                    throw new LogicException();
                }

                $date = $data->date ?? null;

                return $date === $this->today->toString();
            },
        );

        $this->eventDispatcher->subscribe(CurrencyRateUpdate::class, function () {
            $this->dataCacheAccess->reset();
        });
    }

    /**
     * @return array<string, float>
     */
    public function get(string $base): array
    {
        $today = $this->dateTime->getToday();

        $this->today = $today;
        $this->base = $base;

        $data = $this->dataCacheAccess->get();

        if (!property_exists($data, 'rates')) {
            throw new RuntimeException("Corrupted cache data in '$this->cacheKey'.");
        }

        return get_object_vars($data->rates);
    }

    /**
     * @return stdClass&object{date: string, rates: stdClass}
     */
    private function buildData(Date $today, string $base): stdClass
    {
        $rates = [];

        foreach ($this->rateEntryProvider->getRateEntries($today, $base) as $rate) {
            $rates[$rate->getRecord()->getCode()] = (float) $rate->getRate();
        }

        return (object) [
            'date' => $today->toString(),
            'rates' => (object) $rates,
        ];
    }
}
