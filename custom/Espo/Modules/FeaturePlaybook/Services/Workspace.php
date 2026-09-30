<?php

declare(strict_types=1);

namespace Espo\Modules\FeaturePlaybook\Services;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use stdClass;

/** Workspace lists are explicit projections, not generic record CRUD or export endpoints. */
class Workspace
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private User $user,
        private UserTenantResolver $tenants,
        private TeamsAccess $teams,
        private Playbooks $playbooks,
    ) {}

    private function account(string $accountId): Entity
    {
        if (!$this->user->isRegular() && !$this->user->isAdmin()) throw new Forbidden();
        if (!ctype_digit($accountId) || (int) $accountId < 1) throw new BadRequest('Workspace is required.');
        $query = $this->select->create()->from('ChatwootAccount')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['chatwootAccountId' => (int) $accountId])->limit(0, 2)->build();
        $accounts = iterator_to_array($this->em->getRDBRepository('ChatwootAccount')->clone($query)->find());
        if (count($accounts) !== 1) throw new Forbidden('Workspace is unavailable.');
        $account = reset($accounts);
        if (!$account->get('tenantId') || (!$this->user->isAdmin() &&
            !$this->tenants->canActForTenant($this->user, $account->get('tenantId')))) throw new Forbidden();
        return $account;
    }

    private function templateQuery(Entity $account): SelectBuilder
    {
        $query = $this->select->create()->from('Playbook')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['tenantId' => $account->get('tenantId')]);
        // A role granting "all" must not widen the template library beyond shared teams.
        if (!$this->user->isAdmin()) {
            $teams = $this->em->getQueryBuilder()->select('entityId')->from('EntityTeam')->where([
                'entityType' => 'Playbook', 'teamId' => $this->user->getTeamIdList(), 'deleted' => false,
            ])->build();
            $query->where(['id=s' => $teams]);
        }
        return $query;
    }

    private function template(Entity $account, string $id): Entity
    {
        $query = $this->templateQuery($account)->where(['id' => $id])->build();
        $template = $this->em->getRDBRepository('Playbook')->clone($query)->findOne();
        if (!$template || !$this->acl->checkEntityRead($template)) throw new NotFound();
        if ($template->get('status') !== 'Published' && !$this->acl->checkEntityEdit($template)) throw new Forbidden();
        return $template;
    }

    public function detail(string $accountId, string $id): object
    {
        $template = $this->template($this->account($accountId), $id);
        return (object) [
            'id' => $template->getId(), 'name' => $template->get('name'),
            'status' => $template->get('status'), 'revision' => $template->get('revision'),
            'canEdit' => $this->acl->checkEntityEdit($template),
            'steps' => $this->playbooks->definitions($id),
        ];
    }

    public function save(string $accountId, stdClass $body): object
    {
        $account = $this->account($accountId);
        if (!empty($body->id)) {
            if (!$this->acl->checkEntityEdit($this->template($account, $body->id))) throw new Forbidden();
        } elseif (!$this->canCreate($account)) {
            throw new Forbidden();
        }
        return $this->playbooks->saveWorkspaceTemplate($account, $body);
    }

    private function canCreate(Entity $account): bool
    {
        return $this->acl->checkScope('Playbook', 'create') &&
            (bool) $this->teams->entityTeamIds($account) &&
            ($this->user->isAdmin() || $this->teams->userSharesTeam($this->user, $account));
    }

    public function listing(Request $request): object
    {
        $account = $this->account((string) $request->getRouteParam('accountId'));
        $runs = $request->getQueryParam('view') === 'runs';
        $type = $runs ? 'PlaybookRun' : 'Playbook';
        if ($runs) {
            $opportunities = $this->select->create()->from('Opportunity')->withStrictAccessControl()->buildQueryBuilder()
                ->select('id')->where(['tenantId' => $account->get('tenantId')]);
            if ($id = $request->getQueryParam('opportunityId')) $opportunities->where(['id' => $id]);
            $query = $this->em->getQueryBuilder()->select()->from($type)->where(['opportunityId=s' => $opportunities->build()]);
        } else {
            $query = $this->templateQuery($account);
        }
        $status = $request->getQueryParam('status');
        $statuses = $runs ? ['Active', 'Completed', 'Stopped', 'Cancelled'] : ['Draft', 'Published', 'Archived'];
        if ($status) {
            if (!in_array($status, $statuses, true)) throw new BadRequest('Invalid status.');
            $query->where(['status' => $status]);
        }
        $search = trim((string) $request->getQueryParam('search'));
        if (mb_strlen($search) > 200) throw new BadRequest('Search is too long.');
        if ($search !== '') $query->where(['name*' => '%' . $search . '%']);
        $cursor = (string) $request->getQueryParam('cursor');
        if ($cursor !== '') {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $cursor)) throw new BadRequest('Invalid cursor.');
            $query->where(['id<' => $cursor]);
        }
        $query->order('id', 'DESC')->limit(0, self::PAGE_SIZE + 1);
        $items = [];
        $more = false;
        $lastId = null;
        // Scan bounded batches, but paginate only authorized rows (draft visibility is record-level).
        do {
            $rows = iterator_to_array($this->em->getRDBRepository($type)->clone($query->build())->find());
            foreach ($rows as $row) {
                if (!$runs && (!$this->acl->checkEntityRead($row) ||
                    ($row->get('status') !== 'Published' && !$this->acl->checkEntityEdit($row)))) continue;
                if (count($items) === self::PAGE_SIZE) { $more = true; break; }
                $items[] = $runs ? $this->runRow($row) : $this->templateRow($row);
                $lastId = $row->getId();
            }
            if ($more || count($rows) < self::PAGE_SIZE + 1) break;
            $query->where(['id<' => end($rows)->getId()]);
        } while (true);
        return (object) ['items' => $items, 'cursor' => $more ? $lastId : null, 'canCreate' => !$runs && $this->canCreate($account)];
    }

    private function templateRow(Entity $template): object
    {
        return (object) [
            'id' => $template->getId(), 'name' => $template->get('name'), 'status' => $template->get('status'),
            'revision' => $template->get('revision'), 'modifiedAt' => $template->get('modifiedAt'),
            'createdByName' => $template->get('createdByName'), 'canEdit' => $this->acl->checkEntityEdit($template),
            'total' => $this->em->getRDBRepository('PlaybookStep')->where(['playbookId' => $template->getId()])->count(),
        ];
    }

    private function runRow(Entity $run): object
    {
        $steps = $this->em->getRDBRepository('PlaybookRunStep')->where(['runId' => $run->getId()])->find();
        $completed = $total = 0;
        foreach ($steps as $step) {
            $total++;
            if ($step->get('status') === 'Completed') $completed++;
        }
        return (object) [
            'id' => $run->getId(), 'name' => $run->get('name'), 'status' => $run->get('status'),
            'opportunityId' => $run->get('opportunityId'), 'opportunityName' => $run->get('opportunityName'),
            'assignedUserName' => $run->get('assignedUserName'), 'createdAt' => $run->get('createdAt'),
            'completed' => $completed, 'total' => $total,
        ];
    }
}
