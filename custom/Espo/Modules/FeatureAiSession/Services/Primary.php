<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDOException;

/**
 * The person's main chat in a workspace: one per user and Chatwoot account (the unique `primarySlot` index), made on
 * first use. Its WhatsApp turns are mirrored back to the person's verified number (FeatureWhatsAppNotifications);
 * everything else about it is an ordinary private session: same owner, ACL, agent and model rules.
 */
class Primary
{
    public const SLOT = 'main';
    public const NAME = 'Principal';

    public function __construct(
        private EntityManager $em, private User $user, private Acl $acl, private Access $access, private Submissions $submissions,
    ) {}

    public static function is(Entity $session): bool
    {
        return $session->get('primarySlot') === self::SLOT;
    }

    public function find(Entity $account): ?Entity
    {
        return $this->em->getRDBRepository('AiSession')->where([
            'assignedUserId' => $this->user->getId(), 'chatwootAccountId' => $account->getId(), 'primarySlot' => self::SLOT,
        ])->findOne();
    }

    /** The main chat, made now if missing. Null when the person may not use any AI agent in this workspace. */
    public function ensure(Entity $account): ?Entity
    {
        $session = $this->find($account);
        if ($session) return $session;
        if (!$this->acl->checkScope('AiSession', 'create')) return null;
        $agentId = $this->agent($account);
        if ($agentId === null) return null;
        try {
            // Validate (beforeSave) sets the owner and tenant from the current user and checks the agent again.
            return $this->em->createEntity('AiSession', [
                'name' => self::NAME, 'chatwootAccountId' => $account->getId(), 'aiAgentMembershipId' => $agentId,
                'primarySlot' => self::SLOT,
            ]);
        } catch (PDOException $e) {
            // Another request made it first.
            if ((string) $e->getCode() !== '23000') throw $e;
            return $this->find($account);
        }
    }

    /**
     * A WhatsApp turn: the text becomes the person's post in their main chat and queues the agent like the composer.
     *
     * @param array<string, scalar> $origin
     * @return array{session: Entity, post: object}
     */
    public function ask(Entity $account, string $text, array $origin): array
    {
        $session = $this->ensure($account);
        if (!$session) throw new Forbidden('No AI agent is available to this person in this workspace.');
        return ['session' => $session, 'post' => $this->submissions->post($session, (object) ['post' => $text], $origin)];
    }

    /** The agent of the person's latest chat, else the first AI of the workspace they may use. */
    private function agent(Entity $account): ?string
    {
        $probe = $this->em->getNewEntity('AiSession');
        $probe->set(['assignedUserId' => $this->user->getId(), 'tenantId' => $account->get('tenantId'), 'chatwootAccountId' => $account->getId()]);
        $latest = $this->em->getRDBRepository('AiSession')->where([
            'assignedUserId' => $this->user->getId(), 'chatwootAccountId' => $account->getId(),
        ])->order('modifiedAt', 'DESC')->findOne();
        $candidates = $latest ? [(string) $latest->get('aiAgentMembershipId')] : [];
        foreach ($this->em->getRDBRepository('ChatwootAccountUserMembership')->where([
            'chatwootAccountId' => $account->getId(), 'isAI' => true,
        ])->order('createdAt')->find() as $membership) $candidates[] = $membership->getId();
        foreach (array_unique($candidates) as $id) {
            try {
                return $this->access->agent($this->user, $probe, $id)->getId();
            } catch (Forbidden) {
                continue;
            }
        }
        return null;
    }
}
