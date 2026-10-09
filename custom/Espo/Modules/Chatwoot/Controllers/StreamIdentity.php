<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;

/** Minimal authenticated identity probe; no preferences, credentials or ACL tree. */
class StreamIdentity
{
    public function __construct(private User $user) {}

    public function getActionRead(): object
    {
        if (!$this->user->isActive() || $this->user->isApi() || $this->user->isPortal()) {
            throw new Forbidden('An active internal human identity is required.');
        }

        return (object) ['user' => (object) ['id' => $this->user->getId()]];
    }
}
