<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Classes\Record\TrackingSource;

use Espo\Core\Record\Output\Filter;
use Espo\ORM\Entity;

/**
 * Output filter for TrackingSource records.
 *
 * Espo has no built-in output masking for `password` fields — without this
 * filter every API read of a TrackingSource would return the Crypt
 * ciphertext of the secrets, which (combined with a leaked cryptKey) IS the
 * secret. Registered via recordDefs/TrackingSource.json
 * `outputFilterClassNameList`.
 *
 * Stored non-empty secrets are replaced with the '********' sentinel; empty
 * values are left as-is so the UI can distinguish "not configured" from
 * "configured". The sentinel round-trips through untouched edit forms and is
 * recognised by both the EncryptSecrets ORM hook (restores the fetched
 * ciphertext) and the ValidateKindRequirements record hook (counts as
 * present).
 *
 * Secrets are write-only through the API: set/rotate by sending a new
 * value, clear by sending an empty string. There is deliberately no way to
 * read one back.
 *
 * @implements Filter<Entity>
 */
class OutputFilter implements Filter
{
    private const MASKED_VALUE_PLACEHOLDER = '********';

    private const SECRET_FIELDS = [
        'signingSecret',
        'identityVerificationSecret',
    ];

    public function filter(Entity $entity): void
    {
        foreach (self::SECRET_FIELDS as $field) {
            $value = $entity->get($field);

            if (is_string($value) && $value !== '') {
                $entity->set($field, self::MASKED_VALUE_PLACEHOLDER);
            }
        }
    }
}
