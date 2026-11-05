<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;

/** "Adiar" hides a card from this user's Em dia only. The record itself is never changed. */
class Snoozes
{
    public const TYPES = ['Opportunity', 'ChatwootConversation'];
    private const MAX_DAYS = 30;

    public function __construct(private EntityManager $em, private Acl $acl, private User $user) {}

    /** IDs this user has deferred and that are still hidden. */
    public function active(string $type): Select
    {
        return SelectBuilder::create()->from('CatchUpSnooze')->select('targetId')->where([
            'userId' => $this->user->getId(), 'targetType' => $type,
            'snoozedUntil>' => gmdate('Y-m-d H:i:s'),
        ])->build();
    }

    /** $tenant is the opted-in workspace the card was shown in; the record must belong to it. */
    public function snooze(Entity $tenant, string $type, string $id, string $until): array
    {
        if (!in_array($type, self::TYPES, true) || !preg_match('/^[a-zA-Z0-9_-]{1,24}$/D', $id)) {
            throw new BadRequest('Unsupported Catch Up card.');
        }
        try {
            $time = (new DateTimeImmutable($until))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new BadRequest('Invalid snooze time.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($time <= $now || $time > $now->modify('+' . self::MAX_DAYS . ' days')) throw new BadRequest('Invalid snooze time.');
        $record = $this->em->getEntityById($type, $id);
        if (!$record) throw new NotFound();
        // A conversation has no tenant of its own; its Chatwoot account's tenant owns it.
        $owner = $type === 'ChatwootConversation'
            ? $this->em->getEntityById('ChatwootAccount', (string) $record->get('chatwootAccountId'))?->get('tenantId')
            : $record->get('tenantId');
        if (!$this->acl->checkEntityRead($record) || $owner !== $tenant->getId()) {
            throw new Forbidden();
        }
        $value = $time->format('Y-m-d H:i:s');
        $this->em->getTransactionManager()->run(function () use ($type, $id, $value): void {
            $where = ['userId' => $this->user->getId(), 'targetType' => $type, 'targetId' => $id];
            $snooze = $this->em->getRDBRepository('CatchUpSnooze')->where($where)->forUpdate()->findOne()
                ?? $this->em->getNewEntity('CatchUpSnooze');
            $snooze->set([...$where, 'snoozedUntil' => $value]);
            $this->em->saveEntity($snooze);
        });
        return ['type' => $type, 'id' => $id, 'until' => $time->format(DATE_ATOM)];
    }
}
