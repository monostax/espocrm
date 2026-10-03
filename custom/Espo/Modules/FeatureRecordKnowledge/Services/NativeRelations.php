<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\EntityManager;

/** Read adapters only: native fields remain authoritative, never copied into claims. */
class NativeRelations
{
    public function __construct(private EntityManager $em, private Acl $acl, private Access $access, private SelectBuilderFactory $select, private Tenancy $tenancy) {}

    public function page(string $type, string $id, string $direction, string $after, int $limit): array
    {
        if (!(($type === 'Opportunity' && $direction !== 'incoming') || ($type === 'Account' && $direction !== 'outgoing')) ||
            !$this->acl->checkScope('Opportunity', 'read') || !$this->acl->checkField('Opportunity', 'account')) {
            return ['list' => [], 'cursor' => null];
        }
        try {
            $query = $this->select->create()->from('Opportunity')->withStrictAccessControl()->buildQueryBuilder()
                ->where($type === 'Account' ? ['accountId' => $id] : ['id' => $id])
                ->where(['id>' => $after])->order('id')->limit(0, 100)->build();
        } catch (Forbidden) { return ['list' => [], 'cursor' => null]; }
        $rows = iterator_to_array($this->em->getRDBRepository('Opportunity')->clone($query)->find());
        $list = [];
        $cursor = null;
        foreach ($rows as $opportunity) {
            $cursor = $opportunity->getId();
            $accountId = $opportunity->get('accountId');
            if (!$accountId) continue;
            try {
                $account = $this->access->record('Account', $accountId);
                $subject = $this->access->record('Opportunity', $opportunity->getId());
                $ids = array_values(array_intersect($this->tenancy->recordIds($subject), $this->tenancy->recordIds($account)));
                if (count($ids) !== 1) continue;
                $this->tenancy->assert($ids[0]);
            } catch (Forbidden|NotFound|\Espo\Core\Exceptions\BadRequest) { continue; }
            $list[] = [
                'id' => 'native:' . $opportunity->getId() . ':account', 'predicate' => 'builtin:deal_for', 'predicateLabel' => 'deal for',
                'inverseLabel' => 'has deal', 'qualifiers' => (object) [],
                'tenantId' => $ids[0],
                'subjectType' => 'Opportunity', 'subjectId' => $opportunity->getId(), 'subjectLabel' => $opportunity->get('name'),
                'objectType' => 'Account', 'objectId' => $accountId, 'objectLabel' => $account->get('name'),
                'direction' => $type === 'Account' ? 'incoming' : 'outgoing', 'status' => 'confirmed', 'origin' => 'native',
                'provenance' => ['recordType' => 'Opportunity', 'recordId' => $opportunity->getId(), 'field' => 'account'],
                'editable' => false,
            ];
            if (count($list) === $limit) return ['list' => $list, 'cursor' => $cursor];
        }
        return ['list' => $list, 'cursor' => count($rows) === 100 ? $cursor : null];
    }
}
