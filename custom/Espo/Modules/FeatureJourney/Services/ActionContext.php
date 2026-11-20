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

    /** After wrong-number review, only the confirmed person and destination may receive outreach. */
    public function recipients(array $people): array
    {
        $id = AccountReplyReview::state($this->record)['contactId'] ?? null;
        return $id ? array_values(array_filter($people, static fn ($person) =>
            $person->getEntityType() === 'Contact' && $person->getId() === $id)) : $people;
    }

    public function phones(array $phones): array
    {
        $s = AccountReplyReview::state($this->record);
        $phones = array_diff($phones, $s['excluded'] ?? []);
        return array_values(isset($s['phone']) ? array_intersect($phones, [$s['phone']]) : $phones);
    }
}
