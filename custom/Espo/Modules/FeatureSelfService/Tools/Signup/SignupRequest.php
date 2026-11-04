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

/**
 * The signup form, validated and normalized.
 *
 * Phones become E.164. A number written without a country code is taken as
 * Brazilian (10 or 11 digits with the area code).
 */
final class SignupRequest
{
    public const MIN_PASSWORD_LENGTH = 8;

    public readonly string $name;
    public readonly string $email;
    public readonly string $phone;
    public readonly string $company;
    public readonly string $password;
    public readonly string $language;

    /**
     * @throws SignupRejected
     */
    public function __construct(
        string $name,
        string $email,
        string $phone,
        string $company,
        string $password,
        bool $acceptTerms,
        string $language = 'pt_BR',
    ) {
        $this->name = self::text($name, SignupRejected::INVALID_NAME);
        $this->company = self::text($company, SignupRejected::INVALID_COMPANY);

        $email = strtolower(trim($email));

        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SignupRejected(SignupRejected::INVALID_EMAIL);
        }

        $this->email = $email;
        $this->phone = self::phone($phone);

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH || mb_strlen($password) > 128 || trim($password) === '') {
            throw new SignupRejected(SignupRejected::WEAK_PASSWORD);
        }

        $this->password = $password;

        if (!$acceptTerms) {
            throw new SignupRejected(SignupRejected::TERMS_NOT_ACCEPTED);
        }

        $this->language = $language === 'en_US' ? 'en_US' : 'pt_BR';
    }

    /**
     * @throws SignupRejected
     */
    public static function phone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);
        $international = str_starts_with(trim($phone), '+') || str_starts_with(trim($phone), '00');

        if (!$international && strlen($digits) >= 10 && strlen($digits) <= 11) {
            $digits = '55' . $digits;
        }

        if (str_starts_with(trim($phone), '00')) {
            $digits = substr($digits, 2);
        }

        if (!preg_match('/^[1-9]\d{9,14}$/', $digits)) {
            throw new SignupRejected(SignupRejected::INVALID_PHONE);
        }

        return '+' . $digits;
    }

    /**
     * @throws SignupRejected
     */
    private static function text(string $value, string $reason): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (mb_strlen($value) < 2 || mb_strlen($value) > 100) {
            throw new SignupRejected($reason);
        }

        return $value;
    }
}
