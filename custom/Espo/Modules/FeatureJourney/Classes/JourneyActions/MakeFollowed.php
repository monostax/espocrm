<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Tools\Stream\Service as StreamService;

/**
 * Make specified tenant users follow the journey target (stream).
 * Params: userIds[] (required). Teams/followers expansion not supported.
 */
class MakeFollowed implements Action
{
    public function __construct(
        private TenantGuard $tenantGuard,
        private StreamService $streamService,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('MakeFollowed: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $userIds = $context->params['userIds'] ?? $context->params['userIdList'] ?? null;
        if ($userIds instanceof \stdClass) {
            $userIds = array_values((array) $userIds);
        }
        if (is_string($userIds) && $userIds !== '') {
            $userIds = [$userIds];
        }
        if (isset($context->params['userId']) && (string) $context->params['userId'] !== '') {
            $userIds = is_array($userIds) ? $userIds : [];
            $userIds[] = (string) $context->params['userId'];
        }

        if (!is_array($userIds) || $userIds === []) {
            throw new Error('MakeFollowed: userIds is required.');
        }

        $allowed = [];
        foreach ($userIds as $id) {
            if (!is_string($id) && !is_int($id)) {
                continue;
            }
            $uid = (string) $id;
            if ($uid === '') {
                continue;
            }
            $this->tenantGuard->assertUserInTenant($uid, $tenantId, 'follower');
            $allowed[] = $uid;
        }

        $allowed = array_values(array_unique($allowed));
        if ($allowed === []) {
            throw new Error('MakeFollowed: no eligible users.');
        }

        // skipAclCheck: jobs run as system; tenancy already asserted.
        $this->streamService->followEntityMass($context->target, $allowed, true);
    }
}
