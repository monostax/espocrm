<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Enrichment\Providers\Result;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * A Contact's enriched phones must be valid mobile numbers. Brazil follows its numbering
 * plan; other countries follow libphonenumber, the rule journeys use for WhatsApp sends.
 * No paid lookup: Twilio Line Type Intelligence was retired on 2026-11-23.
 * Company (Account) phones are out of scope: landlines are expected there.
 */
class MobilePhones
{
    /** Contact phone capability marker; changing it retires earlier unchecked sibling caches. */
    public const REVISION = 'contact-phone-mobile-only-v1';

    /** Applies to the requested phones and to phones retained as an unpurchased sibling. */
    public function apply(Result $result, string $targetType, string $product): Result
    {
        if ($targetType !== 'Contact' || !in_array($result->state, ['match', 'no_match'], true)) return $result;
        $requested = $product === 'phone' && $result->values;
        if (!$requested && !isset($result->hidden['phone'])) return $result;
        $values = $requested ? self::filter($result->values) : $result->values;
        $hidden = $result->hidden;
        if (isset($hidden['phone'])) {
            $hidden['phone'] = self::filter($hidden['phone']);
            if (!$hidden['phone']) unset($hidden['phone']);
        }
        return new Result($values ? 'match' : 'no_match', $values, hidden: $hidden,
            retentionSeconds: $result->retentionSeconds, dateOfBirth: $result->dateOfBirth);
    }

    /** @param list<string> $phones E.164 values @return list<string> */
    private static function filter(array $phones): array
    {
        return array_values(array_filter($phones, static fn ($phone): bool => is_string($phone) && self::mobile($phone)));
    }

    private static function mobile(string $e164): bool
    {
        if (str_starts_with($e164, '+55')) return self::brazilianMobile($e164);
        try {
            $util = PhoneNumberUtil::getInstance();
            $type = $util->getNumberType($util->parse($e164, null));
        } catch (Throwable) {
            return false;
        }
        return in_array($type, [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true);
    }

    /** DDD + 9 + eight digits. The ninth digit is never inferred for older eight-digit values. */
    public static function brazilianMobile(string $e164): bool
    {
        return preg_match('/\A\+55[1-9][1-9]9[0-9]{8}\z/', $e164) === 1;
    }
}
