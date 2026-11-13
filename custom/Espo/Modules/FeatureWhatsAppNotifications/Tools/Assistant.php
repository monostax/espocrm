<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Entities\Note;
use Espo\Modules\FeatureAiSession\Services\Primary;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * The AI in WhatsApp. A subscriber's text becomes their own post in their main AI chat of that workspace
 * (FeatureAiSession Primary), submitted as them (ActorScope): their CRM ACL, Chatwoot access, agents, model and credits,
 * exactly as if they typed it in the app. The agent's answer comes back here (`reply`, from the Note hook
 * MirrorAssistantReply), only to the verified number of the active subscription that sent the turn, and only when the
 * session still belongs to that person.
 */
class Assistant
{
    public const CHANNEL = 'whatsapp';
    /** Statuses of a finished turn; "cancelled" (replaced or withdrawn) says nothing. */
    public const ANSWERED = ['completed', 'failed', 'blocked'];

    public function __construct(
        private EntityManager $em,
        private ActorScope $actors,
        private Avisos $avisos,
        private Workspaces $workspaces,
        private Log $log,
    ) {}

    public function ask(Entity $subscription, string $text, int $messageId): void
    {
        $workspace = $this->workspaces->of($subscription);
        $language = $workspace['language'];
        if ($workspace['frozen']) {
            $this->avisos->text($subscription, 'assistant', Copy::t($language, 'ai.frozen'));
            return;
        }
        Sessions::markAi($subscription);
        Sessions::setPending($subscription, null);
        $this->em->saveEntity($subscription, ['silent' => true]);
        try {
            $this->actors->run((string) $subscription->get('userId'), fn (callable $make) => $make(Primary::class)->ask($workspace['account'], $text, [
                'aiSessionChannel' => self::CHANNEL, 'whatsAppSubscriptionId' => $subscription->getId(), 'whatsAppMessageId' => $messageId,
            ]));
        } catch (Forbidden|NotFound|BadRequest $e) {
            $this->log->info("WhatsApp notifications: AI turn refused for subscription {$subscription->getId()}: " . $e->getMessage());
            $this->avisos->text($subscription, 'assistant', Copy::t($language, 'ai.unavailable'));
            return;
        } catch (Throwable $e) {
            $this->log->warning("WhatsApp notifications: AI turn for subscription {$subscription->getId()} failed: " . $e->getMessage());
            $this->avisos->text($subscription, 'assistant', Copy::t($language, 'ai.failed'));
            return;
        }
        $this->avisos->typing($subscription);
    }

    /**
     * A WhatsApp turn of this person in their main chat, still theirs: the subscription it came through (active, theirs,
     * same workspace, window open), its text and the WhatsApp message. Null when any of that doesn't hold.
     *
     * @return ?array{subscription: Entity, text: string, messageId: int}
     */
    public function turn(string $userId, string $noteId): ?array
    {
        $note = $this->em->getEntityById('Note', $noteId);
        if (!$note instanceof Note || $note->getParentType() !== 'AiSession' || $note->getCreatedById() !== $userId ||
            $note->get('opportunityPostDeleted')) return null;
        $origin = $note->getData();
        $session = $this->em->getEntityById('AiSession', (string) $note->getParentId());
        $subscription = ($origin->aiSessionChannel ?? null) === self::CHANNEL
            ? $this->em->getEntityById('WhatsAppNotificationSubscription', (string) ($origin->whatsAppSubscriptionId ?? '')) : null;
        if (!$session || !Primary::is($session) || $session->get('assignedUserId') !== $userId || !$subscription ||
            $subscription->get('status') !== 'active' || $subscription->get('userId') !== $userId ||
            $subscription->get('chatwootAccountId') !== $session->get('chatwootAccountId') || !Avisos::windowOpen($subscription)) return null;
        return ['subscription' => $subscription, 'text' => (string) $note->getPost(), 'messageId' => (int) ($origin->whatsAppMessageId ?? 0)];
    }

    /** The agent's finished answer to a WhatsApp turn, back to the number that asked. */
    public function reply(Note $reply): void
    {
        $progress = $reply->getData()->opportunityStreamAgent ?? null;
        $status = (string) ($progress->status ?? '');
        if ($reply->getParentType() !== 'AiSession' || !in_array($status, self::ANSWERED, true)) return;
        $source = $this->em->getEntityById('Note', (string) ($progress->sourceNoteId ?? ''));
        $session = $this->em->getEntityById('AiSession', (string) $reply->getParentId());
        $origin = $source instanceof Note ? $source->getData() : null;
        if (!$source instanceof Note || !$session || ($origin->aiSessionChannel ?? null) !== self::CHANNEL ||
            $source->getParentId() !== $session->getId()) return;
        $subscription = $this->em->getEntityById('WhatsAppNotificationSubscription', (string) ($origin->whatsAppSubscriptionId ?? ''));
        // The person who asked still owns the chat and still holds this number, in this workspace.
        if (!$subscription || $subscription->get('status') !== 'active' ||
            $subscription->get('userId') !== $source->getCreatedById() || $session->get('assignedUserId') !== $source->getCreatedById() ||
            $subscription->get('chatwootAccountId') !== $session->get('chatwootAccountId')) return;
        try {
            $workspace = $this->workspaces->of($subscription);
            $language = $workspace['language'];
        } catch (Error) {
            return; // the workspace is gone
        }
        if (!Avisos::windowOpen($subscription)) {
            $this->log->info("WhatsApp notifications: AI answer {$reply->getId()} not sent, the 24h window closed.");
            return;
        }
        $link = $this->workspaces->sessionLink($subscription, $session->getId());
        $chunks = match ($status) {
            'completed' => WhatsAppText::chunks(WhatsAppText::fromMarkdown((string) $reply->getPost())),
            'blocked' => [Copy::t($language, 'ai.blocked')],
            default => [Copy::t($language, 'ai.failed')],
        };
        if (!$chunks) $chunks = [Copy::t($language, 'ai.empty', ['link' => $link])];
        if (count($chunks) > WhatsAppText::MAX_CHUNKS) {
            $chunks = array_slice($chunks, 0, WhatsAppText::MAX_CHUNKS);
            $chunks[WhatsAppText::MAX_CHUNKS - 1] .= "\n\n" . Copy::t($language, 'ai.more', ['link' => $link]);
        }
        // Every WhatsApp message ends with who answered and for which workspace (one number may be in several); the app
        // shows the author already.
        $signature = $this->signature($reply, (string) ($workspace['name'] ?? ''));
        foreach ($chunks as $index => $chunk) {
            $this->avisos->text($subscription, 'assistant', $signature !== null ? "{$chunk}\n\n*{$signature}*" : $chunk, null,
                "ai:{$reply->getId()}:{$index}");
        }
    }

    /** "Agent / Workspace" as WhatsApp can bold it: the agent's membership name (else its CRM user's) and the workspace. */
    private function signature(Note $reply, string $workspace): ?string
    {
        $membershipId = (string) ($reply->getData()->opportunityStreamAgent->aiAgentMembershipId ?? '');
        $name = $membershipId !== '' ? $this->em->getEntityById('ChatwootAccountUserMembership', $membershipId)?->get('name') : null;
        if (!is_string($name) || trim($name) === '') {
            $name = $this->em->getEntityById('User', (string) $reply->getCreatedById())?->get('name');
        }
        $parts = array_filter(array_map(fn ($part) => trim((string) preg_replace('/\s+/u', ' ', str_replace(['*', '_', '~', '`'], '', (string) $part))),
            [$name, $workspace]), fn (string $part) => $part !== '');
        return $parts ? implode(' / ', $parts) : null;
    }
}
