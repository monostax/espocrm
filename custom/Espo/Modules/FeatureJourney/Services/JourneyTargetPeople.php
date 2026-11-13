<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\EmailTemplate\Data as EmailTemplateData;
use Throwable;

/**
 * Who a journey message on a target is addressed to.
 *
 * - Account / Opportunity: every contact in its Contatos, the same set the
 *   relationships panel shows: native links (an Opportunity's primary contact
 *   first; inactive Account links left out) plus confirmed RecordRelation claims
 *   in either direction. Each contact must belong to the journey's tenant.
 * - Any other target: the target itself.
 *
 * Senders loop over these people, render `{Contact.*}` / `{{Contact.*}}`
 * (and Espo's `{Person.*}`) for each one, and send to that person's own
 * phones / emails.
 */
class JourneyTargetPeople
{
    /** @var array<string, array<string, mixed>> target type => where on its `contacts` link */
    private const FAN_OUT_TYPES = [
        'Account' => ['@relation.isInactive' => false],
        'Opportunity' => [],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
        private Log $log,
    ) {}

    public function fansOut(Entity $target): bool
    {
        return array_key_exists($target->getEntityType(), self::FAN_OUT_TYPES);
    }

    /**
     * @return list<Entity>
     */
    public function forTarget(Entity $target, string $tenantId): array
    {
        if (!$this->fansOut($target)) {
            return [$target];
        }

        if (!$target->hasId()) {
            return [];
        }

        $ids = [];

        $primary = $target->hasAttribute('contactId') ? (string) ($target->get('contactId') ?? '') : '';

        if ($primary !== '') {
            $ids[$primary] = true;
        }

        try {
            $native = $this->entityManager
                ->getRDBRepository($target->getEntityType())
                ->getRelation($target, 'contacts')
                ->select(['id'])
                ->where(self::FAN_OUT_TYPES[$target->getEntityType()])
                ->order('createdAt')
                ->find();

            foreach ($native as $contact) {
                $ids[$contact->getId()] = true;
            }
        } catch (Throwable $e) {
            $this->log->warning('JourneyTargetPeople: native contacts: ' . $e->getMessage());
        }

        if ($this->entityManager->hasRepository('RecordRelation')) {
            $relations = $this->entityManager
                ->getRDBRepository('RecordRelation')
                ->select(['subjectType', 'subjectId', 'objectType', 'objectId'])
                ->where([
                    'status' => 'confirmed',
                    'OR' => [
                        [
                            'subjectType' => 'Contact',
                            'objectType' => $target->getEntityType(),
                            'objectId' => $target->getId(),
                        ],
                        [
                            'subjectType' => $target->getEntityType(),
                            'subjectId' => $target->getId(),
                            'objectType' => 'Contact',
                        ],
                    ],
                ])
                ->order('createdAt')
                ->find();

            foreach ($relations as $relation) {
                $id = $relation->get('subjectType') === 'Contact'
                    ? (string) $relation->get('subjectId')
                    : (string) $relation->get('objectId');

                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        $people = [];

        foreach (array_keys($ids) as $id) {
            $contact = $this->entityManager->getEntityById('Contact', (string) $id);

            if (!$contact) {
                continue;
            }

            if (!$this->tenantGuard->entityBelongsToTenant($contact, $tenantId)) {
                $this->log->warning(
                    'JourneyTargetPeople: skip Contact/' . $contact->getId() .
                    ' on ' . $target->getEntityType() . '/' . $target->getId() . ': outside tenant.'
                );

                continue;
            }

            $people[] = $contact;
        }

        return $people;
    }

    /**
     * The company a contact belongs to, for previewing an Account journey from the
     * contact's side: their primary account, else the first Account whose Contatos
     * list them (active native link, then a confirmed RecordRelation). Must be in
     * the tenant.
     */
    public function companyOf(Entity $contact, string $tenantId): ?Entity
    {
        $ids = [];
        $primary = (string) ($contact->get('accountId') ?? '');

        if ($primary !== '') {
            $ids[] = $primary;
        }

        try {
            $native = $this->entityManager
                ->getRDBRepository('Contact')
                ->getRelation($contact, 'accounts')
                ->select(['id'])
                ->where(['@relation.isInactive' => false])
                ->order('createdAt')
                ->find();

            foreach ($native as $account) {
                $ids[] = $account->getId();
            }
        } catch (Throwable $e) {
            $this->log->warning('JourneyTargetPeople: contact accounts: ' . $e->getMessage());
        }

        if ($this->entityManager->hasRepository('RecordRelation')) {
            $relations = $this->entityManager
                ->getRDBRepository('RecordRelation')
                ->select(['subjectType', 'subjectId', 'objectId'])
                ->where([
                    'status' => 'confirmed',
                    'OR' => [
                        ['subjectType' => 'Contact', 'subjectId' => $contact->getId(), 'objectType' => 'Account'],
                        ['subjectType' => 'Account', 'objectType' => 'Contact', 'objectId' => $contact->getId()],
                    ],
                ])
                ->order('createdAt')
                ->find();

            foreach ($relations as $relation) {
                $ids[] = $relation->get('subjectType') === 'Account'
                    ? (string) $relation->get('subjectId')
                    : (string) $relation->get('objectId');
            }
        }

        foreach (array_unique($ids) as $id) {
            $account = $this->entityManager->getEntityById('Account', $id);

            if ($account && $this->tenantGuard->entityBelongsToTenant($account, $tenantId)) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Template names a person answers to: Espo's generic `Person` and their
     * own type (`Contact`, `Lead`, `User`).
     *
     * @return array<string, Entity>
     */
    public static function templateEntities(Entity $person): array
    {
        return [
            'Person' => $person,
            $person->getEntityType() => $person,
        ];
    }

    /**
     * Email template data for one person. The target itself: Espo finds `{Person.*}`
     * by the recipient address, as before. A contact of an Account: the Account is the
     * parent (`{Account.*}`) and the contact is `{Contact.*}` / `{Person.*}`, pinned
     * rather than looked up by address (two records can share an email).
     */
    public static function emailTemplateData(Entity $target, Entity $person, ?string $emailAddress): EmailTemplateData
    {
        $data = EmailTemplateData::create()
            ->withParent($target)
            ->withParentId($target->getId())
            ->withParentType($target->getEntityType());

        if ($person === $target) {
            $data = $data->withEntityHash([$target->getEntityType() => $target]);

            return $emailAddress ? $data->withEmailAddress($emailAddress) : $data;
        }

        return $data->withEntityHash([
            $target->getEntityType() => $target,
            ...self::templateEntities($person),
        ]);
    }

    /**
     * Email placeholders a template left as text, e.g. `{Account.customFields.x}`
     * when the record has no value or no such related record.
     *
     * @return list<string>
     */
    public static function unresolvedPlaceholders(string $text): array
    {
        preg_match_all('/\{[A-Z][A-Za-z]*\.[A-Za-z0-9_.]+\}/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * One action outcome line for people left out because a value was missing,
     * missing fields first so it survives the 100-character exitReason.
     *
     * @param array<string, list<string>> $missingByPerson person name => placeholders
     */
    public static function missingSummary(array $missingByPerson): string
    {
        $fields = array_values(array_unique(array_merge(...array_values($missingByPerson))));

        return 'Sem valor: ' . implode(', ', $fields) . ' (' . implode(', ', array_keys($missingByPerson)) . ')';
    }
}
