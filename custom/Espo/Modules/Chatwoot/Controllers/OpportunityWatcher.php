<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use DateTimeImmutable;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\OpportunityWatcher as Service;
use Throwable;

class OpportunityWatcher
{
    private const MAX_ACTIONS = 20;

    public function __construct(private Service $service) {}

    public function postActionDecision(Request $request): object
    {
        $id = $request->getRouteParam('id');
        $body = $request->getParsedBody();
        $probability = $body->probability ?? null;
        $conversationId = $body->conversationId ?? null;
        $window = $body->messageWindow ?? null;
        if (!$this->text($id, 24) ||
            !in_array($body->outcome ?? null, Service::OUTCOMES, true) ||
            !$this->text($body->runId ?? null, 64) || !preg_match('/^[a-zA-Z0-9_:-]+$/D', $body->runId) ||
            !$this->text($body->model ?? null, 100) ||
            !$this->optional($body->expectedStageId ?? null, 24) || !$this->optional($body->toStageId ?? null, 24) ||
            !$this->optional($body->reason ?? null, 1000) ||
            ($probability !== null && (!is_numeric($probability) || $probability < 0 || $probability > 1)) ||
            ($conversationId !== null && (!is_int($conversationId) || $conversationId < 1)) ||
            ($window !== null && (!is_object($window) || !$this->optional($window->from ?? null, 40) || !$this->optional($window->to ?? null, 40))) ||
            (($body->details ?? null) !== null && (!is_object($body->details) || strlen(json_encode($body->details)) > 16000))) {
            throw new BadRequest('Invalid watcher decision.');
        }
        if ($body->outcome === Service::MOVE && (!$this->text($body->expectedStageId ?? null, 24) ||
            !$this->text($body->toStageId ?? null, 24) || !$this->text($body->reason ?? null, 1000) || $probability === null)) {
            throw new BadRequest('A move needs the expected stage, the target stage, a reason and a probability.');
        }
        if ($body->outcome === Service::ACT) $this->checkActions($body);
        return $this->service->decide($id, $body);
    }

    /** The shape of a v2 run; whether each target may be used is the service's to decide. */
    private function checkActions(object $body): void
    {
        $actions = $body->actions ?? null;
        $message = $body->message ?? null;
        if (!is_array($actions) || count($actions) > self::MAX_ACTIONS || !$this->optional($message, 2000) ||
            (!$actions && !$this->text($message, 2000))) {
            throw new BadRequest('A watcher run needs actions or a message.');
        }
        foreach ($actions as $action) {
            if (!is_object($action) || !in_array($action->kind ?? null, Service::ACTION_KINDS, true) ||
                !$this->text($action->reason ?? null, 1000) || !$this->validAction($action)) {
                throw new BadRequest('Invalid watcher action.');
            }
        }
    }

    private function validAction(object $action): bool
    {
        return match ($action->kind) {
            'stage' => $this->text($action->expectedStageId ?? null, 24) && $this->text($action->toStageId ?? null, 24),
            'assign' => $this->text($action->userId ?? null, 24) && $this->optional($action->expectedAssignedUserId ?? null, 24),
            'taskCreate' => is_string($action->key ?? null) && preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $action->key) &&
                $this->text($action->name ?? null, 255) && trim($action->name) !== '' &&
                $this->optional($action->description ?? null, 4000) && $this->optional($action->assignedUserId ?? null, 24) &&
                is_bool($action->asNextAction ?? false) && $this->validDue($action),
            'taskUpdate' => $this->text($action->taskId ?? null, 24) && $this->optional($action->name ?? null, 255) && $this->validDue($action),
            'taskComplete', 'taskCancel' => $this->text($action->taskId ?? null, 24),
            'nextAction' => $this->optional($action->expectedNextActionId ?? null, 24) && $this->optional($action->taskId ?? null, 24),
            'followup' => $this->moment($action->expectedFollowupTimer ?? null) && $this->moment($action->at ?? null) &&
                (($action->at ?? null) === null || $this->text($action->senderMembershipId ?? null, 24)),
        };
    }

    /** A date-only deadline or an ISO moment, never both. */
    private function validDue(object $action): bool
    {
        $date = $action->dueDate ?? null;
        $at = $action->dueAt ?? null;
        if ($date !== null && $at !== null) return false;
        if ($date !== null && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ||
            DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date)) {
            return false;
        }
        if ($at !== null) {
            if (!$this->text($at, 40)) return false;
            try {
                new DateTimeImmutable($at);
            } catch (Throwable) {
                return false;
            }
        }
        return true;
    }

    /** Null or a UTC `Y-m-d H:i:s`, as follow-up timers are stored. */
    private function moment(mixed $value): bool
    {
        return $value === null || (is_string($value) &&
            DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value)?->format('Y-m-d H:i:s') === $value);
    }

    private function text(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= $max;
    }

    private function optional(mixed $value, int $max): bool
    {
        return $value === null || $this->text($value, $max);
    }
}
