<?php

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Global\Hooks\User\EmailIdentity;
use Espo\ORM\EntityManager;

/** Migrate unambiguous human usernames without changing passwords or access. */
class BackfillUserEmailIdentity implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private EmailIdentity $identity,
        private Log $log,
    ) {}

    public function process(): void
    {
        $users = $this->entityManager->getRDBRepository('User')
            ->where(['type!=' => [User::TYPE_API, User::TYPE_SYSTEM]])->find();

        foreach ($users as $user) {
            $email = strtolower(trim((string) $user->get('emailAddress')));
            if ($email === '' || $user->get('userName') === $email) {
                continue;
            }

            try {
                $this->identity->process($user);
                $this->entityManager->saveEntity($user, [
                    'silent' => true,
                    'skipChatwootProvisioning' => true,
                ]);
            } catch (BadRequest | Conflict $e) {
                $this->log->warning(
                    "BackfillUserEmailIdentity: Skipped User {$user->getId()}: {$e->getMessage()}"
                );
            }
        }
    }
}
