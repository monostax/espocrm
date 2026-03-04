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

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\GoogleMeetCredentialHelper;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\GoogleMeetConferenceRecord as ConferenceRecordService;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\GoogleMeetParticipant as ParticipantService;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\GoogleMeetTranscriptEntry as TranscriptEntryService;
use Espo\Tools\OAuth\TokensProvider;
use stdClass;

/**
 * Controller for GoogleMeetConferenceRecord virtual entity.
 *
 * Read-only controller — no create, update, or delete actions.
 * Uses Credential entities for authentication with team-based ACL.
 * Dispatches linked panels (participants, transcriptEntries) to child services.
 */
class GoogleMeetConferenceRecord
{
    public function __construct(
        private InjectableFactory $injectableFactory,
    ) {}

    /**
     * GET GoogleMeetConferenceRecord - List conference records.
     *
     * Supports filtering via:
     *   - Direct `credentialId` query param.
     *   - Native EspoCRM `where` / `whereGroup` clauses for `credentialId`.
     *
     * @throws Error
     * @throws Forbidden
     */
    public function getActionIndex(Request $request, Response $response): stdClass
    {
        $credentialId = $request->getQueryParam('credentialId');
        $credentialIds = null;

        $where = $request->getQueryParams()['whereGroup']
            ?? $request->getQueryParams()['where']
            ?? null;

        if (is_array($where)) {
            foreach ($where as $item) {
                if (($item['attribute'] ?? null) !== 'credentialId') {
                    continue;
                }

                $type = $item['type'] ?? null;

                if ($type === 'equals' && isset($item['value'])) {
                    $credentialId = $item['value'];
                } elseif ($type === 'in' && is_array($item['value'] ?? null)) {
                    $credentialIds = $item['value'];
                }
            }
        }

        $service = $this->getService();
        $result = $service->find($credentialId, $credentialIds);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET GoogleMeetConferenceRecord/:id - Get a single conference record.
     * ID format: credentialId_conferenceRecordId
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function getActionRead(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        [$credentialId, $conferenceRecordId] = $this->parseId($id);

        $record = $this->getService()->read($credentialId, $conferenceRecordId);

        try {
            $record->transcriptContent = $this->buildTranscriptContent($credentialId, $conferenceRecordId);
        } catch (\Throwable) {
            $record->transcriptContent = null;
        }

        return $record;
    }

    /**
     * GET GoogleMeetConferenceRecord/:id/:link - Get linked records.
     * Dispatches to child services for participants and transcriptEntries.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function getActionListLinked(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');
        $link = $request->getRouteParam('link');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        if (!$link) {
            throw new BadRequest("Link name is required.");
        }

        [$credentialId, $conferenceRecordId] = $this->parseId($id);

        return match ($link) {
            'participants' => $this->listParticipants($credentialId, $conferenceRecordId),
            'transcriptEntries' => $this->listTranscriptEntries($credentialId, $conferenceRecordId),
            default => (object) [
                'total' => 0,
                'list' => [],
            ],
        };
    }

    /**
     * GET GoogleMeetConferenceRecord/action/credentials - List accessible credentials.
     */
    public function getActionCredentials(Request $request, Response $response): stdClass
    {
        $helper = $this->injectableFactory->create(GoogleMeetCredentialHelper::class);
        $credentials = $helper->getAccessibleCredentials();

        $list = [];

        foreach ($credentials as $credential) {
            $list[] = (object) [
                'id' => $credential->getId(),
                'name' => $credential->get('name'),
            ];
        }

        return (object) [
            'total' => count($list),
            'list' => $list,
        ];
    }

    /**
     * POST GoogleMeetConferenceRecord/:id/createLink - Stub.
     */
    public function postActionCreateLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * DELETE GoogleMeetConferenceRecord/:id/removeLink - Stub.
     */
    public function deleteActionRemoveLink(Request $request, Response $response): bool
    {
        return true;
    }

    private function listParticipants(string $credentialId, string $conferenceRecordId): stdClass
    {
        $accessToken = $this->resolveAccessToken($credentialId);

        $service = $this->injectableFactory->create(ParticipantService::class);
        $result = $service->find($accessToken, $conferenceRecordId);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    private function listTranscriptEntries(string $credentialId, string $conferenceRecordId): stdClass
    {
        $accessToken = $this->resolveAccessToken($credentialId);

        $participantMap = $this->buildParticipantMap($accessToken, $conferenceRecordId);

        $service = $this->injectableFactory->create(TranscriptEntryService::class);
        $result = $service->find($accessToken, $conferenceRecordId);

        $list = [];

        foreach ($result->getValueMapList() as $item) {
            $participantResource = $item->participant ?? '';
            $participantId = $this->extractLastSegment($participantResource);
            $item->participant = $participantMap[$participantId] ?? $participantId ?: $participantResource;
            $list[] = $item;
        }

        return (object) [
            'total' => $result->getTotal(),
            'list' => $list,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function parseId(string $id): array
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            throw new BadRequest("Invalid ID format. Expected: credentialId_conferenceRecordId");
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Build an HTML transcript from all transcript entries, resolving participant display names.
     */
    private function buildTranscriptContent(string $credentialId, string $conferenceRecordId): ?string
    {
        $accessToken = $this->resolveAccessToken($credentialId);

        $participantMap = $this->buildParticipantMap($accessToken, $conferenceRecordId);

        $transcriptService = $this->injectableFactory->create(TranscriptEntryService::class);
        $entriesResult = $transcriptService->find($accessToken, $conferenceRecordId);

        $entries = [];

        foreach ($entriesResult as $entry) {
            $entries[] = $entry;
        }

        usort($entries, function ($a, $b) {
            return ($a->get('startTime') ?? '') <=> ($b->get('startTime') ?? '');
        });

        if (empty($entries)) {
            return null;
        }

        $html = '';

        foreach ($entries as $entry) {
            $participantResource = $entry->get('participant') ?? '';
            $participantId = $this->extractLastSegment($participantResource);
            $displayName = $participantMap[$participantId] ?? $participantId ?: 'Unknown';
            $displayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');

            $text = htmlspecialchars($entry->get('text') ?? '', ENT_QUOTES, 'UTF-8');

            $startTime = $entry->get('startTime');
            $timeLabel = '';

            if ($startTime) {
                try {
                    $dt = new \DateTime($startTime);
                    $timeLabel = ' <em>(' . $dt->format('H:i:s') . ')</em>';
                } catch (\Throwable) {}
            }

            $html .= "<p><strong>{$displayName}</strong>{$timeLabel}</p>";
            $html .= "<p>{$text}</p>\n";
        }

        return $html;
    }

    /**
     * @return array<string, string> Participant ID => display name.
     */
    private function buildParticipantMap(string $accessToken, string $conferenceRecordId): array
    {
        $participantService = $this->injectableFactory->create(ParticipantService::class);
        $result = $participantService->find($accessToken, $conferenceRecordId);

        $map = [];

        foreach ($result as $participant) {
            $map[$participant->get('id')] = $participant->get('displayName') ?: $participant->get('name');
        }

        return $map;
    }

    private function resolveAccessToken(string $credentialId): string
    {
        $credentialHelper = $this->injectableFactory->create(GoogleMeetCredentialHelper::class);
        $tokensProvider = $this->injectableFactory->create(TokensProvider::class);

        $credential = $credentialHelper->validateCredentialAccess($credentialId);
        $oAuthAccountId = $credentialHelper->getOAuthAccountId($credential);
        $tokens = $tokensProvider->get($oAuthAccountId);

        return $tokens->getAccessToken();
    }

    private function extractLastSegment(string $resourceName): string
    {
        $parts = explode('/', $resourceName);

        return end($parts);
    }

    private function getService(): ConferenceRecordService
    {
        return $this->injectableFactory->create(ConferenceRecordService::class);
    }
}
