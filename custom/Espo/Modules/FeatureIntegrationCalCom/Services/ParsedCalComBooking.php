<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Services;

/**
 * Normalised value-object representation of a cal.com booking webhook.
 *
 * Produced by {@see CalComPayloadParser::parse()}.
 */
final class ParsedCalComBooking
{
    public function __construct(
        public readonly string $triggerEvent,
        public readonly ?string $bookingUid,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $title,
        public readonly ?string $eventTypeSlug,
        public readonly ?string $eventTypeId,
        public readonly ?int $startTime,
        public readonly ?int $endTime,
        public readonly ?string $fbc,
        public readonly ?string $fbp,
        public readonly ?string $fbclid,
    ) {}
}
