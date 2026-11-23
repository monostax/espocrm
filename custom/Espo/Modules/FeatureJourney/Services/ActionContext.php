<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Value object passed to journey action implementations.
 */
class ActionContext
{
    /** The record produced by a create action, persisted as a reference by ActionRunner. */
    public ?Entity $createdRecord = null;

    /**
     * @param array<string, mixed> $params
      * @param User|null $actor User whose ACL/authorship apply (run-as identity).
      */
    public function __construct(
        public readonly Entity $target,
        public readonly Entity $record,
        public readonly Entity $stage,
        public readonly Entity $journey,
        public readonly string $trigger,
        public readonly array $params,
        public readonly ?string $tenantId,
        public readonly ?User $actor = null,
    ) {}

    /** While a number added in reply review catches up, only its contact receives ({@see JourneyCatchUp}). */
    public function recipients(array $people): array
    {
        $lane = $this->lane(AccountReplyReview::state($this->record));
        if ($lane === null) {
            return $people;
        }
        $ids = array_flip(array_values($lane));

        return array_values(array_filter($people, static fn ($person) =>
            $person->getEntityType() === 'Contact' && isset($ids[$person->getId()])));
    }

    /** Numbers marked wrong in reply review never receive; during catch-up only the added numbers do. */
    public function phones(array $phones): array
    {
        $s = AccountReplyReview::state($this->record);
        $phones = array_values(array_diff($phones, $s['excluded'] ?? []));
        $lane = $this->lane($s);

        return $lane === null ? $phones : array_values(array_intersect($phones, array_map('strval', array_keys($lane))));
    }

    private function lane(array $state): ?array
    {
        return $this->stage->hasId() ? JourneyCatchUp::lane($state, $this->stage->getId(), $this->trigger) : null;
    }
}
