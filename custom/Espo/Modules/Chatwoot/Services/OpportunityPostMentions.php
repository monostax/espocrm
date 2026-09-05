<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Entities\Note;
use Espo\ORM\EntityManager;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;

/** Resolve the editor's Chatwoot IDs once, at write time, into verified CRM IDs. */
class OpportunityPostMentions
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private AclManager $aclManager,
        private UserTenantResolver $tenantResolver,
    ) {}

    public function resolve(Note $note): array
    {
        $opportunity = $this->entityManager->getEntityById('Opportunity', $note->getParentId());
        if (!$opportunity) {
            return [];
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
                if ($teams) {
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
}
