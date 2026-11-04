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

namespace Espo\Modules\FeatureSelfService\Tools\Signup;

use RuntimeException;

/**
 * A signup step the visitor can correct or retry. The code is stable and is
 * what the signup screen translates.
 */
class SignupRejected extends RuntimeException
{
    public const INVALID_NAME = 'INVALID_NAME';
    public const INVALID_EMAIL = 'INVALID_EMAIL';
    public const INVALID_PHONE = 'INVALID_PHONE';
    public const INVALID_COMPANY = 'INVALID_COMPANY';
    public const WEAK_PASSWORD = 'WEAK_PASSWORD';
    public const TERMS_NOT_ACCEPTED = 'TERMS_NOT_ACCEPTED';
    public const EMAIL_IN_USE = 'EMAIL_IN_USE';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const RESEND_TOO_SOON = 'RESEND_TOO_SOON';
    public const INVALID_CODE = 'INVALID_CODE';
    public const CODE_EXPIRED = 'CODE_EXPIRED';
    public const TOO_MANY_ATTEMPTS = 'TOO_MANY_ATTEMPTS';
    public const NOT_FOUND = 'SIGNUP_NOT_FOUND';
    public const EMAIL_NOT_SENT = 'EMAIL_NOT_SENT';
    public const SOCIAL_FAILED = 'SOCIAL_FAILED';
    public const SOCIAL_EMAIL_UNVERIFIED = 'SOCIAL_EMAIL_UNVERIFIED';
    public const SOCIAL_UNAVAILABLE = 'SOCIAL_UNAVAILABLE';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
