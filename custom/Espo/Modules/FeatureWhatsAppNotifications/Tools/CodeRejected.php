<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use RuntimeException;

/** A phone code step the person can correct or retry (PhoneCodes). The reason is what the screens translate. */
class CodeRejected extends RuntimeException
{
    public const INVALID_CODE = 'INVALID_CODE';
    public const CODE_EXPIRED = 'CODE_EXPIRED';
    public const TOO_MANY_ATTEMPTS = 'TOO_MANY_ATTEMPTS';
    public const RESEND_TOO_SOON = 'RESEND_TOO_SOON';
    public const SMS_TOO_SOON = 'SMS_TOO_SOON';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const SEND_FAILED = 'SEND_FAILED';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
