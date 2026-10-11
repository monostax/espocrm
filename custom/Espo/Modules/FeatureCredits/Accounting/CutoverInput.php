<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use InvalidArgumentException;
use stdClass;

/** Explicit approved tenant cutover; this command neither converts nor grants credits. */
final readonly class CutoverInput
{
    public string $evidenceJson;
    public string $hash;

    public function __construct(public string $tenantId, public string $cutoverAt, stdClass $evidence)
    {
        GrantInput::identity($tenantId);
        GrantInput::timestamp($cutoverAt);
        if (get_object_vars($evidence) === []) {
            throw new InvalidArgumentException('Approved cutover evidence required.');
        }
        $this->evidenceJson = json_encode(GrantInput::canonical($evidence), JSON_THROW_ON_ERROR);
        $this->hash = hash('sha256', json_encode(['cutover-v1', $tenantId, $cutoverAt, $this->evidenceJson], JSON_THROW_ON_ERROR));
    }
}
