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
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\GoogleMeetSpace as GoogleMeetSpaceService;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\GoogleMeetConferenceRecord as ConferenceRecordService;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\GoogleMeetParticipant as ParticipantService;
use Espo\Tools\OAuth\TokensProvider;
use stdClass;

/**
 * Controller for GoogleMeetSpace virtual entity.
 *
 * Read-only controller — no create, update, or delete actions.
 * Uses Credential entities for authentication with team-based ACL.
 */
class GoogleMeetSpace
{
    public function __construct(
        private InjectableFactory $injectableFactory,
    ) {}

    /**
     * GET GoogleMeetSpace - List spaces discovered from conference records.
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
     * GET GoogleMeetSpace/:id - Get a single space.
     * ID format: credentialId_spaceId
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

        [$credentialId, $spaceId] = $this->parseId($id);

        return $this->getService()->read($credentialId, $spaceId);
    }

    /**
     * GET GoogleMeetSpace/:id/:link - Get linked records.
     * Dispatches to conference records service for the `conferenceRecords` link.
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

        [$credentialId, $spaceId] = $this->parseId($id);

        return match ($link) {
            'conferenceRecords' => $this->listConferenceRecords($credentialId, $spaceId),
            'participants' => $this->listParticipants($credentialId, $spaceId),
            default => (object) [
                'total' => 0,
                'list' => [],
            ],
        };
    }

    /**
     * GET GoogleMeetSpace/action/credentials - List accessible credentials.
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
     * POST GoogleMeetSpace/:id/createLink - Stub for link creation.
     * Virtual entity — links are not managed in the database.
     */
    public function postActionCreateLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * DELETE GoogleMeetSpace/:id/removeLink - Stub for link removal.
     * Virtual entity — links are not managed in the database.
     */
    public function deleteActionRemoveLink(Request $request, Response $response): bool
    {
        return true;
    }

    private function listConferenceRecords(string $credentialId, string $spaceId): stdClass
    {
        $service = $this->injectableFactory->create(ConferenceRecordService::class);
        $spaceName = 'spaces/' . $spaceId;
        $result = $service->findBySpace($credentialId, $spaceName);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * Aggregate participants across all conference records for the space.
     */
    private function listParticipants(string $credentialId, string $spaceId): stdClass
    {
        $accessToken = $this->resolveAccessToken($credentialId);

        $conferenceService = $this->injectableFactory->create(ConferenceRecordService::class);
        $spaceName = 'spaces/' . $spaceId;
        $records = $conferenceService->findBySpace($credentialId, $spaceName);

        $participantService = $this->injectableFactory->create(ParticipantService::class);
        $list = [];
        $seen = [];

        foreach ($records as $record) {
            $recordId = $record->get('id');
            $parts = explode('_', $recordId, 2);
            $conferenceRecordId = $parts[1] ?? $recordId;

            try {
                $participants = $participantService->find($accessToken, $conferenceRecordId);

                foreach ($participants as $participant) {
                    $displayName = $participant->get('displayName');
                    if ($displayName && !isset($seen[$displayName])) {
                        $seen[$displayName] = true;
                        $list[] = $participant->getValueMap();
                    } elseif (!$displayName) {
                        $list[] = $participant->getValueMap();
                    }
                }
            } catch (\Throwable) {
            }
        }

        return (object) [
            'total' => count($list),
            'list' => $list,
        ];
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

    /**
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function parseId(string $id): array
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            throw new BadRequest("Invalid ID format. Expected: credentialId_spaceId");
        }

        return [$parts[0], $parts[1]];
    }

    private function getService(): GoogleMeetSpaceService
    {
        return $this->injectableFactory->create(GoogleMeetSpaceService::class);
    }
}
