<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Posts on the journey record's mural who a WhatsApp/email action didn't reach
 * and why (send error, empty field, no mobile, opt-out), @mentioning the
 * record's owner so they get a notification. Never fails the action.
 */
class JourneyUndeliveredNotice
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * @param array<string, string> $missed person name => reason (Markdown)
     * @param int $total people the action tried to reach
     */
    public function post(ActionContext $context, string $what, array $missed, int $total): void
    {
        if ($missed === []) {
            return;
        }

        $target = $context->target;

        try {
            $owner = $this->owner($target);
            $mention = $owner
                ? '[@' . self::escape((string) $owner->get('name')) . '](' . References::url([
                    'kind' => 'record',
                    'entityType' => 'User',
                    'recordId' => $owner->getId(),
                ]) . ') '
                : '';

            $lines = [
                $mention . '**' . self::escape((string) $context->journey->get('name')) . ' · ' .
                self::escape((string) $context->stage->get('name')) . '**: ' . $what . ' não chegou a ' .
                count($missed) . ' de ' . max($total, count($missed)) . ':',
            ];

            foreach ($missed as $name => $reason) {
                $lines[] = '- **' . self::escape((string) $name) . '**: ' . $reason;
            }

            $this->entityManager->createEntity('Note', [
                'type' => 'Post',
                'parentType' => $target->getEntityType(),
                'parentId' => $target->getId(),
                'post' => implode("\n", $lines),
                'isInternal' => true,
            ]);
        } catch (Throwable $e) {
            $this->log->warning(
                'JourneyUndeliveredNotice: could not post on ' . $target->getEntityType() . '/' .
                $target->getId() . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * `{{customFields.x}}` as inline code, so underscores and braces stay literal.
     */
    public static function code(string $text): string
    {
        return '`' . str_replace('`', "'", $text) . '`';
    }

    private function owner(Entity $target): ?Entity
    {
        $id = $target->hasAttribute('assignedUserId') ? (string) ($target->get('assignedUserId') ?? '') : '';

        if ($id === '') {
            return null;
        }

        $user = $this->entityManager->getEntityById('User', $id);

        if (!$user || !$user->get('isActive') || !in_array($user->get('type'), ['regular', 'admin'], true)) {
            return null;
        }

        return $user;
    }

    private static function escape(string $text): string
    {
        return addcslashes($text, '\\*_[]`#');
    }
}
