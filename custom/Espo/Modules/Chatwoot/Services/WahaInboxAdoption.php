<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Entities\ChatwootInboxIntegration;
use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Adopts WhatsApp QR inboxes that were created outside the CRM.
 *
 * A QR channel can legitimately be provisioned two ways:
 *
 *   1. CRM-first — the Channel wizard creates the WAHA session and the Chatwoot
 *      inbox together, so a ChatwootInboxIntegration exists from the start
 *      (session named `channel_<integrationId>`).
 *
 *   2. Chatwoot-first — the operator connects WhatsApp in the Chatwoot/WAHA UI.
 *      Chatwoot gets a `Channel::Api` inbox and WAHA gets a session (typically
 *      named `qr_inbox_<hash>`), but the CRM has no channel record at all.
 *
 * Case 2 used to leave the inbox unusable: every downstream consumer
 * (campaign inbox filters, resolveInbox, DeriveFromInbox) requires an
 * integration to know the inbox is WhatsApp. Rather than teaching each of
 * those to special-case integration-less inboxes, this service restores the
 * invariant by creating the missing integration.
 *
 * Authority comes from WAHA, not from guesswork: `Channel::Api` is Chatwoot's
 * *generic* API channel and is not WhatsApp by itself. We only adopt an inbox
 * when a WAHA session's own `chatwoot` app declares that it targets exactly
 * that account + inbox, which also tells us the session name and phone number.
 */
class WahaInboxAdoption
{
    /** Chatwoot channel class used by WAHA-backed inboxes. */
    private const CHATWOOT_API_CHANNEL = 'Channel::Api';

    /**
     * WAHA session status => ChatwootInboxIntegration status.
     */
    private const STATUS_MAP = [
        'WORKING' => ChatwootInboxIntegration::STATUS_ACTIVE,
        'SCAN_QR_CODE' => ChatwootInboxIntegration::STATUS_PENDING_QR,
        'STARTING' => ChatwootInboxIntegration::STATUS_CONNECTING,
        'STOPPED' => ChatwootInboxIntegration::STATUS_DISCONNECTED,
        'FAILED' => ChatwootInboxIntegration::STATUS_FAILED,
    ];

    /**
     * Lazily built map of "externalAccountId:externalInboxId" => session info.
     * Built once per request/job run: the reconciliation costs one HTTP call
     * per WAHA session, which must not be repeated per inbox.
     *
     * @var array<string, array{platformId: string, sessionName: string, status: string, phoneNumber: ?string}>|null
     */
    private ?array $sessionMap = null;

    /**
     * Normalized handset number => session names observed on it. Built in the
     * same pass as $sessionMap and covers every session, including those with
     * no Chatwoot app (e.g. coexistence send-only companions).
     *
     * @var array<string, list<string>>
     */
    private array $phoneIndex = [];

    public function __construct(
        private EntityManager $entityManager,
        private WahaApiClient $wahaApiClient,
        private Log $log,
    ) {}

    /**
     * Create the missing ChatwootInboxIntegration for a Chatwoot-first WAHA
     * inbox, or return null when the inbox is not WAHA-backed.
     *
     * @param array<string, mixed> $chatwootInbox Raw Chatwoot inbox payload
     * @param string $espoAccountId ChatwootAccount entity id
     */
    public function adoptIfWahaBacked(array $chatwootInbox, string $espoAccountId): ?Entity
    {
        if (($chatwootInbox['channel_type'] ?? null) !== self::CHATWOOT_API_CHANNEL) {
            return null;
        }

        $externalInboxId = (int) ($chatwootInbox['id'] ?? 0);

        if ($externalInboxId <= 0) {
            return null;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $espoAccountId);

        if (!$account) {
            return null;
        }

        $externalAccountId = (int) ($account->get('chatwootAccountId') ?? 0);

        if ($externalAccountId <= 0) {
            return null;
        }

        $session = $this->resolveSession($externalAccountId, $externalInboxId);

        if (!$session) {
            return null;
        }

        // Respect a deliberate deletion: if a channel for this session was
        // removed in the CRM, re-adopting it here would silently resurrect it
        // on the next sync. Leaving it unadopted keeps the (separate) problem
        // of an orphaned live WAHA session visible instead of papering over it.
        if ($this->sessionHasAnyIntegration($session['sessionName'])) {
            $this->log->debug(sprintf(
                'WahaInboxAdoption: session %s already has a channel record (possibly deleted); not adopting inbox %d.',
                $session['sessionName'],
                $externalInboxId
            ));

            return null;
        }

        // Refuse to create a second live channel for a number that already has
        // one. Two channels on one WhatsApp number means a campaign can pick
        // either inbox and the same contact can be messaged twice, so the safe
        // default is to leave the newer inbox unadopted and make the conflict
        // loud rather than silently double the send surface.
        $conflict = $this->findConflictingLiveChannel($session['sessionName'], $session['phoneNumber']);

        if ($conflict !== null) {
            $this->log->warning(sprintf(
                'WahaInboxAdoption: not adopting Chatwoot inbox %d (session %s, %s) — ' .
                'channel %s "%s" is already live on the same number. ' .
                'Delete one of the two to resolve the duplicate.',
                $externalInboxId,
                $session['sessionName'],
                (string) $session['phoneNumber'],
                $conflict->getId(),
                (string) $conflict->get('name')
            ));

            return null;
        }

        return $this->createIntegration($chatwootInbox, $account, $session);
    }

    /**
     * Find a live channel that already owns this phone number.
     *
     * Two independent lookups, because neither alone is sufficient:
     *
     *  1. Another WAHA session on the same handset that a live channel owns.
     *     Required because QR channels created through the wizard leave
     *     `phoneNumber` empty on the channel record, so a column comparison
     *     would miss almost every real duplicate.
     *  2. A live channel recording this number on the record itself. Required
     *     because coexistence companions have no Chatwoot app and therefore
     *     never appear in the session map, yet do populate `phoneNumber`.
     */
    private function findConflictingLiveChannel(string $candidateSessionName, ?string $phoneNumber): ?Entity
    {
        $digits = self::normalizePhoneNumber($phoneNumber);

        if ($digits === null) {
            return null;
        }

        // Ensure both indexes are populated.
        $this->getSessionMap();

        foreach ($this->phoneIndex[$digits] ?? [] as $otherSessionName) {
            if ($otherSessionName === $candidateSessionName) {
                continue;
            }

            $owner = $this->findLiveIntegrationBySession($otherSessionName);

            if ($owner !== null) {
                return $owner;
            }
        }

        return $this->findLiveIntegrationByPhoneNumber($digits);
    }

    private function findLiveIntegrationBySession(string $sessionName): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository(ChatwootInboxIntegration::ENTITY_TYPE)
            ->where(['wahaSessionName' => $sessionName])
            ->findOne();
    }

    /**
     * @param string $digits Normalized (digits-only) phone number.
     */
    private function findLiveIntegrationByPhoneNumber(string $digits): ?Entity
    {
        $candidates = $this->entityManager
            ->getRDBRepository(ChatwootInboxIntegration::ENTITY_TYPE)
            ->where([
                'channelType' => WhatsAppChannel::WAHA_BACKED,
                'phoneNumber!=' => null,
            ])
            ->find();

        foreach ($candidates as $candidate) {
            if (self::normalizePhoneNumber((string) $candidate->get('phoneNumber')) === $digits) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Reduce a phone number to comparable digits, or null when there is nothing
     * to compare. WAHA reports a bare MSISDN (from `5511933253711@c.us`) while
     * stored numbers may carry `+`, spaces, dashes or parentheses.
     */
    public static function normalizePhoneNumber(?string $phoneNumber): ?string
    {
        if ($phoneNumber === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phoneNumber);

        if (!is_string($digits) || $digits === '') {
            return null;
        }

        return $digits;
    }

    /**
     * Whether two phone numbers denote the same WhatsApp account.
     *
     * Comparison is exact on digits rather than a suffix match: a suffix rule
     * would collide across country codes (e.g. +1 555 11933253711 vs
     * +55 11 93325-3711) and wrongly block a legitimate adoption.
     */
    public static function isSamePhoneNumber(?string $a, ?string $b): bool
    {
        $left = self::normalizePhoneNumber($a);
        $right = self::normalizePhoneNumber($b);

        if ($left === null || $right === null) {
            return false;
        }

        return $left === $right;
    }

    /**
     * @param array<string, mixed> $chatwootInbox
     * @param array{platformId: string, sessionName: string, status: string, phoneNumber: ?string} $session
     */
    private function createIntegration(array $chatwootInbox, Entity $account, array $session): ?Entity
    {
        $name = trim((string) ($chatwootInbox['name'] ?? ''));

        if ($name === '') {
            $name = 'WhatsApp ' . ($session['phoneNumber'] ?? $session['sessionName']);
        }

        try {
            $integration = $this->entityManager->createEntity(
                ChatwootInboxIntegration::ENTITY_TYPE,
                [
                    'name' => $name,
                    'channelType' => ChatwootInboxIntegration::CHANNEL_TYPE_WHATSAPP_QRCODE,
                    'status' => $session['status'],
                    'chatwootAccountId' => $account->getId(),
                    'wahaPlatformId' => $session['platformId'],
                    'wahaSessionName' => $session['sessionName'],
                    'phoneNumber' => $session['phoneNumber'],
                    'chatwootInboxId' => (int) $chatwootInbox['id'],
                    'chatwootInboxIdentifier' => $chatwootInbox['inbox_identifier'] ?? null,
                    'connectedAt' => $session['status'] === ChatwootInboxIntegration::STATUS_ACTIVE
                        ? date('Y-m-d H:i:s')
                        : null,
                ],
                // Silent: this is a reconciliation of existing infrastructure,
                // not an operator action. The provisioning hooks must not fire
                // and try to (re)create a WAHA session or Chatwoot inbox.
                ['silent' => true]
            );
        } catch (Throwable $e) {
            $this->log->error(sprintf(
                'WahaInboxAdoption: could not adopt inbox %s (session %s): %s',
                (string) ($chatwootInbox['id'] ?? '?'),
                $session['sessionName'],
                $e->getMessage()
            ));

            return null;
        }

        $this->log->info(sprintf(
            'WahaInboxAdoption: adopted Chatwoot-created WhatsApp inbox %s as channel %s ' .
            '(session %s, phone %s, status %s).',
            (string) $chatwootInbox['id'],
            $integration->getId(),
            $session['sessionName'],
            (string) $session['phoneNumber'],
            $session['status']
        ));

        return $integration;
    }

    /**
     * Whether any channel record — including soft-deleted ones — already
     * claims this WAHA session.
     */
    private function sessionHasAnyIntegration(string $sessionName): bool
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(ChatwootInboxIntegration::ENTITY_TYPE)
            ->where(['wahaSessionName' => $sessionName])
            ->withDeleted()
            ->build();

        $found = $this->entityManager
            ->getRDBRepository(ChatwootInboxIntegration::ENTITY_TYPE)
            ->clone($query)
            ->findOne();

        return $found !== null;
    }

    /**
     * @return array{platformId: string, sessionName: string, status: string, phoneNumber: ?string}|null
     */
    private function resolveSession(int $externalAccountId, int $externalInboxId): ?array
    {
        $map = $this->getSessionMap();

        return $map[$externalAccountId . ':' . $externalInboxId] ?? null;
    }

    /**
     * Reconcile every WAHA session's `chatwoot` app against the Chatwoot
     * account + inbox it targets.
     *
     * @return array<string, array{platformId: string, sessionName: string, status: string, phoneNumber: ?string}>
     */
    private function getSessionMap(): array
    {
        if ($this->sessionMap !== null) {
            return $this->sessionMap;
        }

        $this->sessionMap = [];
        $this->phoneIndex = [];

        $platforms = $this->entityManager
            ->getRDBRepository('WahaPlatform')
            ->find();

        foreach ($platforms as $platform) {
            $url = (string) ($platform->get('backendUrl') ?? '');
            $apiKey = (string) ($platform->get('apiKey') ?? '');

            if ($url === '' || $apiKey === '') {
                continue;
            }

            try {
                $sessions = $this->wahaApiClient->listSessions($url, $apiKey, true);
            } catch (Throwable $e) {
                // WAHA being unreachable must never break inbox sync.
                $this->log->warning(
                    'WahaInboxAdoption: could not list sessions on ' . $url . ': ' . $e->getMessage()
                );

                continue;
            }

            foreach ($sessions as $session) {
                $this->indexSession($platform->getId(), $url, $apiKey, $session);
            }
        }

        return $this->sessionMap;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function indexSession(string $platformId, string $url, string $apiKey, array $session): void
    {
        $sessionName = trim((string) ($session['name'] ?? ''));

        if ($sessionName === '') {
            return;
        }

        // Index the handset number for every session, before (and regardless of)
        // app discovery: a second session on the same number is a duplicate even
        // if it has no Chatwoot app or its apps cannot be listed right now.
        $phone = self::normalizePhoneNumber($this->extractPhoneNumber($session));

        if ($phone !== null) {
            $this->phoneIndex[$phone][] = $sessionName;
        }

        try {
            $apps = $this->wahaApiClient->listApps($url, $apiKey, $sessionName);
        } catch (Throwable $e) {
            $this->log->debug(
                'WahaInboxAdoption: could not list apps for session ' . $sessionName . ': ' . $e->getMessage()
            );

            return;
        }

        foreach ($apps as $app) {
            if (($app['app'] ?? null) !== 'chatwoot' || empty($app['enabled'])) {
                continue;
            }

            $accountId = (int) ($app['config']['accountId'] ?? 0);
            $inboxId = (int) ($app['config']['inboxId'] ?? 0);

            if ($accountId <= 0 || $inboxId <= 0) {
                continue;
            }

            $this->sessionMap[$accountId . ':' . $inboxId] = [
                'platformId' => $platformId,
                'sessionName' => $sessionName,
                'status' => self::STATUS_MAP[(string) ($session['status'] ?? '')]
                    ?? ChatwootInboxIntegration::STATUS_CONNECTING,
                'phoneNumber' => $this->extractPhoneNumber($session),
            ];
        }
    }

    /**
     * WAHA reports the linked handset as `me.id` = "5511933253711@c.us".
     *
     * @param array<string, mixed> $session
     */
    private function extractPhoneNumber(array $session): ?string
    {
        $raw = $session['me']['id'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', explode('@', $raw)[0]);

        return $digits !== '' ? '+' . $digits : null;
    }
}
