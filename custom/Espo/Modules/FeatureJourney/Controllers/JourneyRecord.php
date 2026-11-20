<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Templates\Controllers\Base;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord as JourneyRecordEntity;
use Espo\Modules\FeatureJourney\Services\JourneyEnrollmentService;
use Espo\Modules\FeatureJourney\Services\AccountReplyReview;
use Espo\Modules\FeatureJourney\Services\JourneyTargetPeople;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TransitionExecutor;
use stdClass;

/**
 * Standard CRUD + lifecycle actions for JourneyRecord.
 */
class JourneyRecord extends Base implements \Espo\Core\Di\EntityManagerAware
{
    use \Espo\Core\Di\EntityManagerSetter;

    private function reviewRecord(Request $request): \Espo\ORM\Entity
    {
        $record = $this->entityManager->getEntityById('JourneyRecord', (string) $request->getRouteParam('id'));
        if (!$record) {
            throw new NotFound();
        }
        if (!$this->acl->check($record, 'edit') || !$this->acl->checkField('JourneyRecord', 'status', 'edit') ||
            !$this->acl->checkScope('Contact', 'read') || !$this->acl->checkField('Contact', 'phoneNumber', 'read')) {
            throw new Forbidden();
        }
        $target = $record->get('targetType') === 'Account' && $record->get('targetId')
            ? $this->entityManager->getEntityById('Account', $record->get('targetId')) : null;
        if (!$target || !$this->acl->check($target, 'read') || $target->get('tenantId') !== $record->get('tenantId')) {
            throw new Forbidden();
        }
        return $record;
    }

    public function getActionReplyReview(Request $request, Response $response): stdClass
    {
        return (object) AccountReplyReview::summary($this->reviewRecord($request));
    }

    public function postActionReplyReview(Request $request, Response $response): stdClass
    {
        $record = $this->reviewRecord($request);
        return (object) $this->injectableFactory->create(AccountReplyReview::class)->decide(
            $record->getId(), (object) $request->getParsedBody(), $this->user->getId(), $this->acl,
            $this->injectableFactory->create(JourneyTargetPeople::class),
            $this->injectableFactory->create(JourneyWhatsAppOutbound::class),
            $this->injectableFactory->create(TransitionExecutor::class),
        );
    }

    /**
     * POST JourneyRecord/{id}/exit — stop processing, keep history (status Exited).
     */
    public function postActionExit(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');
        $body = $request->getParsedBody();

        if (!$id && is_object($body) && isset($body->id)) {
            $id = (string) $body->id;
        }

        if (!$id) {
            throw new BadRequest('Missing JourneyRecord ID.');
        }

        $record = $this->entityManager->getEntityById(JourneyRecordEntity::ENTITY_TYPE, (string) $id);

        if (!$record) {
            throw new NotFound("JourneyRecord {$id} not found.");
        }

        if (!$this->acl->check($record, 'edit')) {
            throw new Forbidden("No edit access to JourneyRecord {$id}.");
        }

        $reason = 'manual';
        if (is_object($body) && isset($body->reason) && is_string($body->reason)) {
            $reason = trim($body->reason) !== '' ? trim($body->reason) : 'manual';
        }

        $service = $this->injectableFactory->create(JourneyEnrollmentService::class);
        $updated = $service->exitRecord(
            (string) $id,
            $this->user->getId() ? (string) $this->user->getId() : null,
            $reason,
        );

        $values = (object) ($updated->getValueMap() ?? []);
        unset($values->replyReviewState);
        return $values;
    }
}
