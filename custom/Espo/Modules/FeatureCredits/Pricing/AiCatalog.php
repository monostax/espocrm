<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Pricing;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\ORM\EntityManager;
use InvalidArgumentException;

/** CRM-owned immutable entity versions, never deployment config or mutable aliases. */
final class AiCatalog
{
    public function __construct(private EntityManager $entityManager) {}

    public function resolve(string $id): AiModelPolicy
    {
        $query = $this->entityManager->getPDO()->prepare('SELECT definition FROM ai_model_credit_rate WHERE policy_id=? AND deleted=FALSE');
        $query->execute([$id]);
        $raw = $query->fetchColumn();
        $entry = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($entry)) throw new ServiceUnavailable('Approved credit model policy is unavailable.');
        try { $policy = new AiModelPolicy($entry); }
        catch (InvalidArgumentException | \OverflowException | \Brick\Math\Exception\MathException) {
            throw new ServiceUnavailable('Approved credit model policy is invalid.');
        }
        if (!hash_equals($policy->rate->id, $id)) throw new ServiceUnavailable('Credit model policy reference does not match its content.');
        return $policy;
    }
}
