<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCatchUp\Services\Actions;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Snoozes;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * What a tap does to the card's subject, as the person who tapped, through Em dia's own services and with the deck's
 * guards: the frozen workspace, the revision the card was built from (409 when the conversation moved), the person's
 * access (the CRM ACL and Chatwoot's), and the deck's review, which marks the verdict acted on.
 *
 * A reply goes into the customer's conversation through Chatwoot's account API with the person's own Chatwoot token,
 * so Chatwoot checks their access to the conversation, blocks sends from a frozen workspace, and records them as the
 * author. The message carries `content_attributes.sent_from = whatsapp_avisos`.
 */
class Acting
{
    public const SENT = 'sent';
    public const RESOLVED = 'resolved';
    public const CHANGED = 'changed';
    public const NO_ACCESS = 'noAccess';
    public const FROZEN = 'frozen';
    public const FAILED = 'failed';

    public function __construct(
        private EntityManager $em,
        private ActorScope $actors,
        private Workspaces $workspaces,
        private ChatwootApiClient $api,
        private Log $log,
    ) {}

    public function send(Entity $subscription, Entity $card, string $text): string
    {
        $conversation = null;
        $status = $this->reviewed($subscription, $card, function (callable $make, array $fresh) use (&$conversation): string {
            $conversation = $fresh['conversation'] ?? null;
            return self::SENT;
        });
        if ($status !== self::SENT) return $status;
        $workspace = $this->workspaces->of($subscription);
        if (!is_array($conversation) || (int) ($conversation['accountId'] ?? 0) !== $workspace['accountNumber']) return self::NO_ACCESS;
        $token = $this->token($subscription);
        $platform = $workspace['platform'];
        if (!$token || !$platform?->get('backendUrl')) return self::NO_ACCESS;
        try {
            $response = $this->api->accountRequest((string) $platform->get('backendUrl'), $token, 'POST',
                "/api/v1/accounts/{$workspace['accountNumber']}/conversations/" . (int) $conversation['conversationId'] . '/messages', [
                    'content' => $text, 'message_type' => 'outgoing', 'private' => false,
                    'content_attributes' => ['sent_from' => 'whatsapp_avisos'],
                ]);
        } catch (Throwable $e) {
            $this->log->warning("WhatsApp notifications: send for card {$card->getId()} failed: " . $e->getMessage());
            return self::FAILED;
        }
        if ($response['code'] >= 200 && $response['code'] < 300) return self::SENT;
        $this->log->warning("WhatsApp notifications: Chatwoot refused the send for card {$card->getId()}: HTTP {$response['code']} " . json_encode($response['body']));
        return in_array($response['code'], [401, 403, 404], true) ? self::NO_ACCESS : self::FAILED;
    }

    /** "Já resolvi": on a deal, the deck's note ("Resolvido por fora: …"); on a conversation, the loop it showed is kept. */
    public function resolve(Entity $subscription, Entity $card): string
    {
        $workspace = $this->workspaces->of($subscription);
        $id = (string) $card->get('subjectId');
        return $this->reviewed($subscription, $card, function (callable $make) use ($workspace, $card, $id): string {
            if ($card->get('lane') === 'deals') {
                $make(Actions::class)->resolvedElsewhere(Deck::request($workspace, [
                    'accountId' => (string) $workspace['accountNumber'],
                    'text' => $workspace['language'] === 'en_US' ? 'no details (from WhatsApp)' : 'sem detalhes (pelo WhatsApp)',
                ]), $id);
            } else {
                $make(ServiceFeed::class)->resolved($id, Deck::request($workspace));
            }
            return self::RESOLVED;
        });
    }

    /** "Me lembre mais tarde": the deck's Adiar for this person; the reminder brings the card back at that time. */
    public function snooze(Entity $subscription, Entity $card, \DateTimeImmutable $until): bool
    {
        $workspace = $this->workspaces->of($subscription);
        try {
            $this->actors->run((string) $subscription->get('userId'), fn (callable $make) => $make(Snoozes::class)->snooze($workspace['tenant'],
                $card->get('lane') === 'deals' ? 'Opportunity' : 'ChatwootConversation', (string) $card->get('subjectId'), $until->format(DATE_ATOM)));
        } catch (Throwable $e) {
            $this->log->info("WhatsApp notifications: snooze for card {$card->getId()} refused: " . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Rebuild the subject's snapshot as the person, refuse when its revision isn't the card's, run the deck's review,
     * then $then. The snapshot is built here rather than reusing the card's token: the card may have been built by
     * another worker, whose snapshot cache this one doesn't share.
     *
     * @param callable(callable(class-string): object, array<string, mixed>): string $then
     */
    private function reviewed(Entity $subscription, Entity $card, callable $then): string
    {
        $workspace = $this->workspaces->of($subscription);
        if ($workspace['frozen']) return self::FROZEN;
        $lane = (string) $card->get('lane');
        $id = (string) $card->get('subjectId');
        try {
            return $this->actors->run((string) $subscription->get('userId'), function (callable $make) use ($workspace, $card, $lane, $id, $then): string {
                $feed = $make($lane === 'deals' ? Feed::class : ServiceFeed::class);
                $request = Deck::request($workspace);
                $fresh = $feed->snapshot($id, $request);
                if ((string) $fresh['revision'] !== (string) $card->get('revision')) return self::CHANGED;
                $feed->review($id, (string) $fresh['snapshot'], $request);
                return $then($make, $fresh);
            });
        } catch (Conflict) {
            return self::CHANGED;
        } catch (Forbidden | NotFound) {
            return self::NO_ACCESS;
        } catch (Throwable $e) {
            $this->log->warning("WhatsApp notifications: action on card {$card->getId()} failed: " . $e->getMessage());
            return self::FAILED;
        }
    }

    /** The person's own Chatwoot API token on the workspace's platform. */
    private function token(Entity $subscription): ?string
    {
        $workspace = $this->workspaces->of($subscription);
        $viewer = $this->em->getRDBRepository('ChatwootUser')->where([
            'assignedUserId' => $subscription->get('userId'), 'platformId' => $workspace['account']->get('platformId'),
        ])->findOne();
        $platform = $workspace['platform'];
        if (!$viewer || !$platform) return null;
        $token = (string) $viewer->get('userAccessToken');
        if ($token === '' && $viewer->get('chatwootUserId') && $platform->get('accessToken')) {
            try {
                $token = (string) $this->api->fetchUserAccessToken((string) $platform->get('backendUrl'), (string) $platform->get('accessToken'),
                    (int) $viewer->get('chatwootUserId'));
            } catch (Throwable) {
                return null;
            }
        }
        return $token !== '' ? $token : null;
    }
}
