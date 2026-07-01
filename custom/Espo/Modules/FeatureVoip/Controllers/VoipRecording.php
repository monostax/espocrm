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

namespace Espo\Modules\FeatureVoip\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Templates\Controllers\Base;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;

class VoipRecording extends Base
{
    public function getActionStream(Request $request, Response $response): void
    {
        $callId = $request->getRouteParam('id');
        if (!$callId || !is_string($callId)) {
            throw new NotFound('Call not found.');
        }

        $call = $this->entityManager->getEntityById('Call', $callId);
        if (!$call) {
            throw new NotFound('Call not found.');
        }

        if (!$this->acl->check($call, 'read')) {
            throw new Forbidden('Access denied.');
        }

        $recordingUrl = $call->get('recordingUrl');
        if (!$recordingUrl) {
            throw new NotFound('Recording not found.');
        }

        $accountSid = $this->accountSidFromRecordingUrl($recordingUrl);
        if (!$accountSid) {
            throw new Error('Invalid Twilio recording URL.');
        }

        $credentials = $this->resolveTwilioCredentials($accountSid, $call);
        if (!$credentials) {
            throw new NotFound('Recording not available.');
        }

        $audio = $this->fetchTwilioRecording($this->audioUrl($recordingUrl), $credentials['username'], $credentials['password']);

        $response
            ->setHeader('Content-Type', 'audio/mpeg')
            ->setHeader('Content-Disposition', 'inline; filename="voip-recording-' . $callId . '.mp3"')
            ->setHeader('Cache-Control', 'no-store, private')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Length', (string) strlen($audio))
            ->writeBody($audio);
    }

    private function accountSidFromRecordingUrl(string $recordingUrl): ?string
    {
        if (!preg_match('#/Accounts/(AC[a-zA-Z0-9]+)/Recordings/#', $recordingUrl, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function audioUrl(string $recordingUrl): string
    {
        return preg_match('/\.(mp3|wav)\z/', $recordingUrl) ? $recordingUrl : $recordingUrl . '.mp3';
    }

    /**
     * @return array{username: string, password: string}|null
     */
    private function resolveTwilioCredentials(string $accountSid, \Espo\ORM\Entity $call): ?array
    {
        $type = $this->entityManager
            ->getRDBRepository('CredentialType')
            ->where(['code' => 'twilio'])
            ->findOne();

        if (!$type) {
            return null;
        }

        $credentials = $this->entityManager
            ->getRDBRepository('Credential')
            ->where([
                'credentialTypeId' => $type->getId(),
                'isActive' => true,
            ])
            ->find();

        /** @var CredentialResolver $resolver */
        $resolver = $this->injectableFactory->create(CredentialResolver::class);

        foreach ($credentials as $credential) {
            if (!$this->sharesTeamWithCall($credential, $call)) {
                continue;
            }

            $config = $resolver->resolve($credential->getId());

            if (($config->accountSid ?? null) === $accountSid) {
                if (!empty($config->apiKeySid) && !empty($config->apiKeySecret)) {
                    return [
                        'username' => $config->apiKeySid,
                        'password' => $config->apiKeySecret,
                    ];
                }

                if (!empty($config->authToken)) {
                    return [
                        'username' => $accountSid,
                        'password' => $config->authToken,
                    ];
                }
            }
        }

        return null;
    }

    private function sharesTeamWithCall(\Espo\ORM\Entity $credential, \Espo\ORM\Entity $call): bool
    {
        $callTeamIds = $call->getLinkMultipleIdList('teams');
        $credentialTeamIds = $credential->getLinkMultipleIdList('teams');

        if (empty($callTeamIds) || empty($credentialTeamIds)) {
            return false;
        }

        return (bool) array_intersect($callTeamIds, $credentialTeamIds);
    }

    private function fetchTwilioRecording(string $url, string $username, string $password): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode < 200 || $httpCode >= 300) {
            throw new Error('Failed to fetch Twilio recording. HTTP ' . $httpCode . ($error ? ': ' . $error : ''));
        }

        return $body;
    }
}
