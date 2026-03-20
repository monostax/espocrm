<?php

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Chatwoot\Services\ChatwootAccountMembershipOrchestrator;
use stdClass;

class ChatwootAccount extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * POST ChatwootAccount/:id/addUserMembership
     *
     * Body: { userId: string, role: "agent"|"administrator" }
     *
     * @throws BadRequest
     * @throws NotFound
     */
    public function postActionAddUserMembership(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');
        $payload = $request->getParsedBody();

        if (!$id) {
            $id = $payload->id ?? null;
        }

        if (!$id) {
            throw new BadRequest('ID is required.');
        }

        $userId = $payload->userId ?? null;
        $role = $payload->role ?? null;

        if (!$userId || !$role) {
            throw new BadRequest('userId and role are required.');
        }

        if (!in_array($role, ['agent', 'administrator'], true)) {
            throw new BadRequest('Invalid role. Allowed values: agent, administrator.');
        }

        $membership = $this->getOrchestrator()->addUserMembership(
            (string) $id,
            (string) $userId,
            (string) $role
        );

        return (object) [
            'id' => $membership->getId(),
            'name' => $membership->get('name'),
            'role' => $membership->get('role'),
            'chatwootAccountId' => $membership->get('chatwootAccountId'),
            'chatwootUserId' => $membership->get('chatwootUserId'),
            'syncStatus' => $membership->get('syncStatus'),
        ];
    }

    private function getOrchestrator(): ChatwootAccountMembershipOrchestrator
    {
        return $this->injectableFactory->create(ChatwootAccountMembershipOrchestrator::class);
    }
}

