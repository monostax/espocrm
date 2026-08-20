<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Entities;

use Espo\Core\ORM\Entity;

class GoogleAdsConversionUpload extends Entity
{
    public const ENTITY_TYPE = 'GoogleAdsConversionUpload';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_PROCESSING = 'Processing';
    public const STATUS_RETRY_SCHEDULED = 'RetryScheduled';
    public const STATUS_SENT = 'Sent';
    public const STATUS_VALIDATED = 'Validated';
    public const STATUS_FAILED_PERMANENT = 'FailedPermanent';
    public const STATUS_SKIPPED = 'Skipped';

    /** @var list<string> */
    public const TERMINAL_STATUSES = [
        self::STATUS_SENT,
        self::STATUS_VALIDATED,
        self::STATUS_FAILED_PERMANENT,
        self::STATUS_SKIPPED,
    ];
}
