<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Classes\RecordHooks\TrackingSource;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\ORM\Entity;

/**
 * Enforces kind-dependent requirements on TrackingSource at the API layer.
 *
 * Trust policy (see TrackingSource::isTrustedKind()):
 *   - Trusted kinds (Server / Chatwoot / Other): a signingSecret is
 *     MANDATORY — the ingest endpoint verifies HMAC-SHA256 on every call,
 *     so a trusted source without a secret could never ingest anything.
 *   - kind=Website: a non-empty allowedOrigins allow-list is MANDATORY —
 *     browser ingestion is origin-gated and an empty list would make the
 *     source permanently dead for its only intended client.
 *   - kind=Mobile: exempt from both. Native apps send no Origin header and
 *     cannot keep a secret; abuse control is rate limiting + field
 *     stripping on the public path.
 *
 * Registered as SaveHook (single signature, accepted for both beforeCreate
 * and beforeUpdate) via recordDefs/TrackingSource.json. Runs before the
 * EncryptSecrets ORM hook; the value seen here may be new plaintext, the
 * '********' mask round-tripped from the OutputFilter, or the stored
 * ciphertext — any non-empty string satisfies the presence check.
 *
 * The clientDefs dynamicLogic mirrors these rules in the edit form; this
 * hook is the authoritative enforcement.
 *
 * @implements SaveHook<TrackingSource>
 */
class ValidateKindRequirements implements SaveHook
{
    public static int $order = 9;

    public function process(Entity $entity): void
    {
        if (!$entity instanceof TrackingSource) {
            return;
        }

        if ($entity->isTrustedKind()) {
            $this->requireNonEmpty(
                $entity,
                'signingSecret',
                'A Signing Secret is required for kind=' . $entity->get('kind') .
                '. Trusted (server-to-server) sources must sign every request with HMAC-SHA256.',
            );

            return;
        }

        if ($entity->get('kind') === TrackingSource::KIND_WEBSITE) {
            $this->requireNonEmpty(
                $entity,
                'allowedOrigins',
                'Allowed Origins is required for kind=Website. ' .
                'Browser ingestion is rejected unless the Origin header matches the allow-list.',
            );
        }
    }

    /**
     * @throws BadRequest
     */
    private function requireNonEmpty(TrackingSource $entity, string $field, string $message): void
    {
        $value = $entity->get($field);

        if (!is_string($value) || trim($value) === '') {
            throw new BadRequest($message);
        }
    }
}
