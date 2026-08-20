<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Resolves recent Google click attribution and the latest captured consent.
 */
class GoogleAdsAttributionResolver
{
    private const CONSENT_UNKNOWN = 'Unknown';

    public function __construct(private EntityManager $entityManager) {}

    /**
     * @return array{
     *   clickIds: array{gclid?: string, gbraid?: string, wbraid?: string},
     *   attributionType: string,
     *   consent: array{adUserData: string, adPersonalization: string},
     *   trackingEventId: string|null
     * }
     */
    public function resolve(
        Entity $contact,
        string $tenantId,
        int $lookbackDays,
        ?DateTimeImmutable $anchor = null,
    ): array
    {
        $consent = [
            'adUserData' => self::CONSENT_UNKNOWN,
            'adPersonalization' => self::CONSENT_UNKNOWN,
        ];
        $clickIds = [];
        $trackingEventId = null;
        $anchor = ($anchor ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $threshold = $anchor
            ->modify('-' . max(1, min(90, $lookbackDays)) . ' days')
            ->format('Y-m-d H:i:s');
        $anchorString = $anchor->format('Y-m-d H:i:s');

        try {
            $events = $this->entityManager
                ->getRDBRepository(TrackingEvent::ENTITY_TYPE)
                ->where([
                    'contactId' => $contact->getId(),
                    'tenantId' => $tenantId,
                    'occurredAt>=' => $threshold,
                    'occurredAt<=' => $anchorString,
                    'deleted' => false,
                ])
                ->order('occurredAt', 'DESC')
                ->limit(0, 100)
                ->find();

            $adUserDataResolved = false;
            $adPersonalizationResolved = false;

            foreach ($events as $event) {
                $payload = $event->get('payload');

                // Internal CRM events default these fields to Unknown. Only an
                // event that actually carried a consent key may supersede a
                // previously captured browser choice.
                if (!$adUserDataResolved && $this->hasExplicitConsent($payload, 'adUserData')) {
                    $consent['adUserData'] = $this->consent(
                        $event->get('googleAdUserDataConsent'),
                    );
                    $adUserDataResolved = true;
                }

                if (
                    !$adPersonalizationResolved &&
                    $this->hasExplicitConsent($payload, 'adPersonalization')
                ) {
                    $consent['adPersonalization'] = $this->consent(
                        $event->get('googleAdPersonalizationConsent'),
                    );
                    $adPersonalizationResolved = true;
                }

                if ($clickIds === []) {
                    $candidate = $this->clickIds($event->get('attribution'));

                    if ($candidate !== []) {
                        $clickIds = $this->compatibleClickIds($candidate);
                        $trackingEventId = $event->getId();
                    }
                }

                if ($clickIds !== [] && $adUserDataResolved && $adPersonalizationResolved) {
                    break;
                }
            }
        } catch (Throwable) {
            // TrackingEvent is optional at runtime; use the Contact fallback.
        }

        if ($clickIds === [] && $this->contactAttributionIsRecent($contact, $threshold)) {
            $clickIds = $this->compatibleClickIds(array_filter([
                'gclid' => $this->clickId($contact->get('googleGclid')),
                'gbraid' => $this->clickId($contact->get('googleGbraid')),
                'wbraid' => $this->clickId($contact->get('googleWbraid')),
            ]));
        }

        return [
            'clickIds' => $clickIds,
            'attributionType' => $this->attributionType($clickIds),
            'consent' => $consent,
            'trackingEventId' => $trackingEventId,
        ];
    }

    /**
     * @return array{gclid?: string, gbraid?: string, wbraid?: string}
     */
    private function clickIds(mixed $attribution): array
    {
        if ($attribution instanceof stdClass) {
            $attribution = (array) $attribution;
        }

        if (!is_array($attribution)) {
            return [];
        }

        return array_filter([
            'gclid' => $this->clickId($attribution['gclid'] ?? null),
            'gbraid' => $this->clickId($attribution['gbraid'] ?? null),
            'wbraid' => $this->clickId($attribution['wbraid'] ?? null),
        ]);
    }

    private function clickId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 512 || preg_match('/\s/u', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * Data Manager accepts all three fields, but gbraid and wbraid describe
     * different click paths. Keep one braid per web conversion so attribution
     * metadata has an unambiguous type.
     *
     * @param array{gclid?: string, gbraid?: string, wbraid?: string} $values
     * @return array{gclid?: string, gbraid?: string, wbraid?: string}
     */
    private function compatibleClickIds(array $values): array
    {
        $result = [];

        if (isset($values['gclid'])) {
            $result['gclid'] = $values['gclid'];
        }

        if (isset($values['wbraid'])) {
            $result['wbraid'] = $values['wbraid'];
        } elseif (isset($values['gbraid'])) {
            $result['gbraid'] = $values['gbraid'];
        }

        return $result;
    }

    private function contactAttributionIsRecent(Entity $contact, string $threshold): bool
    {
        $capturedAt = $contact->get('googleAdsClickCapturedAt');

        return is_string($capturedAt) && $capturedAt >= $threshold;
    }

    private function consent(mixed $value): string
    {
        return in_array($value, ['Granted', 'Denied'], true) ? $value : self::CONSENT_UNKNOWN;
    }

    private function hasExplicitConsent(mixed $payload, string $key): bool
    {
        if ($payload instanceof stdClass) {
            $payload = (array) $payload;
        }

        if (!is_array($payload)) {
            return false;
        }

        $consent = $payload['consent'] ?? null;

        if ($consent instanceof stdClass) {
            $consent = (array) $consent;
        }

        return is_array($consent) && array_key_exists($key, $consent);
    }

    /**
     * @param array{gclid?: string, gbraid?: string, wbraid?: string} $clickIds
     */
    private function attributionType(array $clickIds): string
    {
        if (isset($clickIds['gclid'], $clickIds['wbraid'])) {
            return 'Gclid+Wbraid';
        }

        if (isset($clickIds['gclid'], $clickIds['gbraid'])) {
            return 'Gclid+Gbraid';
        }

        if (isset($clickIds['gclid'])) {
            return 'Gclid';
        }

        if (isset($clickIds['wbraid'])) {
            return 'Wbraid';
        }

        return isset($clickIds['gbraid']) ? 'Gbraid' : 'None';
    }
}
