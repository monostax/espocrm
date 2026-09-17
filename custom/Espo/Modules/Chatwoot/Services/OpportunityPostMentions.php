<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Entities\Note;
use Espo\ORM\EntityManager;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Chatwoot\Tools\Activities\Access;

/** Resolve the editor's Chatwoot IDs once, at write time, into verified CRM IDs. */
class OpportunityPostMentions
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private AclManager $aclManager,
        private UserTenantResolver $tenantResolver,
        private TenantResolver $teamTenants,
    ) {}

    public function resolve(Note $note, bool $includeTeams = true): array
    {
        $type = $note->getParentType();
        if (!in_array($type, ['Opportunity', ...Access::TYPES], true)) return [];
        $opportunity = $this->entityManager->getEntityById($type, $note->getParentId());
        if (!$opportunity) {
            return [];
        }
        // Activity posts share mention normalization, including posts written in Espo itself.
        if (!$opportunity->get('tenantId') && in_array($type, Access::TYPES, true)) {
            $teams = $this->entityManager->getRDBRepository($type)->getRelation($opportunity, 'teams')->find();
            $opportunity->set('tenantId', $this->teamTenants->resolveUniqueFromTeamIds(array_map(fn ($team) => $team->getId(), [...$teams])));
        }
        $ids = [];
        $nativeText = preg_replace('~\[[^\]]*\]\(mention://[^)]*\)~', '', $note->getPost() ?? '');
        foreach ($note->getData()->mentions ?? (object) [] as $token => $mention) {
            // The native parser also sees @labels inside Chatwoot links. Those labels
            // are display names, not CRM usernames, and must not identify a second user.
            if (($mention->_scope ?? null) === 'User' && isset($mention->id) &&
                preg_match('/(?<![\w@.-])' . preg_quote($token, '/') . '(?![\w@.-])/u', $nativeText)) {
                $ids[] = $mention->id;
            }
        }

        preg_match_all('~\(mention://(user|team)/(\d+)/[^)]*\)~', $note->getPost() ?? '', $matches, PREG_SET_ORDER);
        if ($matches && $opportunity->get('tenantId')) {
            $where = ['tenantId' => $opportunity->get('tenantId')];
            if ($note->get('opportunityChatwootAccountId')) {
                $where['chatwootAccountId'] = $note->get('opportunityChatwootAccountId');
            }
            $accounts = $this->entityManager->getRDBRepository('ChatwootAccount')->where($where)->limit(0, 2)->find();
            // Numeric IDs are platform/account scoped. Never guess across integrations.
            if (count($accounts) === 1) {
                $account = $accounts[0];
                $users = [];
                $teams = [];
                foreach ($matches as $match) {
                    if ($match[1] === 'user') {
                        $users[] = (int) $match[2];
                    } else {
                        $teams[] = (int) $match[2];
                    }
                }
                if ($users) {
                    $query = $this->entityManager->getQueryBuilder()->select()->from('ChatwootAccountUserMembership')
                        ->join('chatwootUser')->where([
                            'chatwootAccountId' => $account->getId(),
                            'chatwootUser.platformId' => $account->get('platformId'),
                            'chatwootUser.chatwootUserId' => $users,
                        ])->select([['chatwootUser.assignedUserId', 'crmUserId']])->build();
                    $ids = array_merge($ids, $this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_COLUMN));
                }
                if ($teams && $includeTeams) {
                    $query = $this->entityManager->getQueryBuilder()->select()->from('ChatwootAccountUserMembership')
                        ->join('chatwootUser')->join('chatwootTeams', 'mentionTeam')->distinct()->where([
                            'chatwootAccountId' => $account->getId(),
                            'chatwootUser.platformId' => $account->get('platformId'),
                            'mentionTeam.accountId' => $account->getId(),
                            'mentionTeam.chatwootTeamId' => $teams,
                        ])->select([['chatwootUser.assignedUserId', 'crmUserId']])->build();
                    $ids = array_merge($ids, $this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_COLUMN));
                }
            }
        }

        $result = [];
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        foreach ($this->entityManager->getRDBRepository('User')->where(['id' => $ids, 'isActive' => true])->find() as $user) {
            if (($user->isRegular() || $user->isAdmin()) && $this->acl->checkUserPermission($user, 'mention') &&
                $this->aclManager->checkEntityRead($user, $opportunity) &&
                $this->aclManager->checkEntityStream($user, $opportunity) &&
                ($user->isAdmin() || $this->tenantResolver->canActForTenant($user, (string) $opportunity->get('tenantId')))) {
                $result[] = $user->getId();
            }
        }
        sort($result);
        return $result;
    }

    /** Capture explicit, authorized AI targets while the human author is the API actor. */
    public function resolveAiTargets(Note $note): array
    {
        if ($note->getData()->opportunityStreamAgent ?? null) {
            return [];
        }
        $opportunity = $this->entityManager->getEntityById('Opportunity', $note->getParentId());
        if (!$opportunity?->get('tenantId') || !$note->get('opportunityMentionUserIds')) {
            return [];
        }
        $where = ['tenantId' => $opportunity->get('tenantId')];
        if ($note->get('opportunityChatwootAccountId')) {
            $where['chatwootAccountId'] = $note->get('opportunityChatwootAccountId');
        }
        $accounts = $this->entityManager->getRDBRepository('ChatwootAccount')->where($where)->limit(0, 2)->find();
        if (count($accounts) !== 1) {
            return [];
        }
        $account = $accounts[0];
        $userIds = $this->resolve($note, includeTeams: false);
        if (!$userIds) {
            return [];
        }
        // A CRM user can own multiple Chatwoot identities. Retain the exact selected
        // platform ID rather than waking every AI linked to the same CRM user.
        preg_match_all('~\(mention://user/(\d+)/[^)]*\)~', $note->getPost() ?? '', $directMatches);
        $platformUserIds = array_map('intval', $directMatches[1]);
        $nativeUserIds = [];
        $nativeText = preg_replace('~\[[^\]]*\]\(mention://[^)]*\)~', '', $note->getPost() ?? '');
        foreach ($note->getData()->mentions ?? (object) [] as $token => $mention) {
            if (($mention->_scope ?? null) === 'User' && isset($mention->id) &&
                preg_match('/(?<![\w@.-])' . preg_quote($token, '/') . '(?![\w@.-])/u', $nativeText)) {
                $nativeUserIds[] = $mention->id;
            }
        }
        $memberships = $this->entityManager->getRDBRepository('ChatwootAccountUserMembership')
            ->join('chatwootUser')->where([
                'chatwootAccountId' => $account->getId(),
                'chatwootUser.platformId' => $account->get('platformId'),
                'isAI' => true,
            ])->find();
        $targets = [];
        foreach ($memberships as $membership) {
            $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $membership->get('chatwootUserId'));
            $userId = $chatwootUser?->get('assignedUserId');
            // Includes cross-AI replies, not just the selected AI's own posts.
            if ($userId && $userId === $note->getCreatedById()) {
                return [];
            }
            if ($userId && in_array($userId, $userIds, true) &&
                (in_array((int) $chatwootUser->get('chatwootUserId'), $platformUserIds, true) ||
                    in_array($userId, $nativeUserIds, true))) {
                $targets[] = (object) [
                    'aiAgentMembershipId' => $membership->getId(),
                    'chatwootAccountCrmId' => $account->getId(),
                    'crmTenantId' => $opportunity->get('tenantId'),
                ];
            }
        }
        return $targets;
    }
}
