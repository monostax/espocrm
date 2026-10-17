<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokenSetter;
use League\OAuth2\Client\Token\AccessToken;

class OAuth
{
    public function __construct(private EntityManager $em, private Access $access, private Config $config,
        private Crypt $crypt, private User $user, private ServiceContainer $records, private TokenSetter $tokens) {}

    private function session(Request $request): string
    {
        $token = $request->getCookieParam('auth-token');
        $secret = $request->getCookieParam('auth-token-secret');
        if (!$token || !$secret) throw new Forbidden('A browser session is required.');
        return hash('sha256', $token . ':' . $secret . ':' . $this->user->getId());
    }

    private function redirect(): string
    {
        return rtrim((string) $this->config->get('siteUrl'), '/') . '/api/v1/MeetingGoogleImport/callback';
    }

    public function begin(int $accountId, string $meetingId, Request $request): array
    {
        $this->access->assertEnabled();
        $this->access->meeting($accountId, $meetingId, true);
        $origin = $request->getHeader('Origin');
        if (!$origin || !in_array($origin, $this->config->get('meetingGoogleImportOrigins', []), true)) throw new Forbidden('Popup origin is not configured.');
        $body = $request->getParsedBody();
        $credentialId = $body->credentialId ?? null;
        $teamId = null;
        if ($credentialId) {
            $this->access->credential($accountId, (string) $credentialId, true);
        } else {
            $teamId = $body->teamId ?? null;
            if (!is_string($teamId) || !in_array($teamId, array_column($this->access->setupTeams($accountId), 'id'), true)) {
                throw new BadRequest('Explicitly select an eligible workspace team.');
            }
        }
        $provider = $this->em->getEntityById('OAuthProvider', 'msx_gmeet_01');
        if (!$provider?->get('isActive') || !$provider->get('clientId') || !$provider->get('clientSecret')) throw new Failure('setupRequired');
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $this->em->createEntity('MeetingGoogleOAuth', [
            'stateHash' => hash('sha256', $state), 'sessionHash' => $this->session($request), 'verifier' => $this->crypt->encrypt($verifier),
            'userId' => $this->user->getId(), 'accountId' => $accountId, 'meetingId' => $meetingId, 'teamId' => $teamId,
            'credentialId' => $credentialId, 'origin' => $origin, 'expiresAt' => gmdate('Y-m-d H:i:s', time() + 600),
        ]);
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $provider->get('clientId'), 'redirect_uri' => $this->redirect(), 'response_type' => 'code',
            'scope' => implode(' ', $provider->get('scopes')), 'state' => $state, 'access_type' => 'offline',
            'prompt' => 'consent select_account', 'include_granted_scopes' => 'true',
            'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ]);
        return ['url' => $url, 'state' => $state, 'expiresIn' => 600];
    }

    /** Callback is authenticated by CRM first and then bound to the exact originating browser session. */
    public function finish(Request $request): array
    {
        $state = (string) $request->getQueryParam('state');
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) throw new Forbidden();
        $transaction = $this->em->getTransactionManager()->run(function () use ($state, $request) {
            $row = $this->em->getRDBRepository('MeetingGoogleOAuth')->where(['stateHash' => hash('sha256', $state)])->forUpdate()->findOne();
            if (!$row || $row->get('consumed') || $row->get('expiresAt') < gmdate('Y-m-d H:i:s') ||
                $row->get('userId') !== $this->user->getId() || !hash_equals($row->get('sessionHash'), $this->session($request))) throw new Forbidden();
            if (!in_array($row->get('origin'), $this->config->get('meetingGoogleImportOrigins', []), true)) throw new Forbidden();
            $row->set('consumed', true);
            $this->em->saveEntity($row);
            return $row;
        });
        $result = ['origin' => $transaction->get('origin'), 'state' => $state, 'ok' => false];
        try {
            $this->access->assertEnabled();
            $accountId = (int) $transaction->get('accountId');
            $this->access->meeting($accountId, $transaction->get('meetingId'), true);
            if ($request->getQueryParam('error') || !$request->getQueryParam('code')) throw new Failure('authorizationDenied');
            $provider = $this->em->getEntityById('OAuthProvider', 'msx_gmeet_01');
            if (!$provider?->get('isActive')) throw new Failure('setupRequired');
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'authorization_code', 'code' => $request->getQueryParam('code'),
                    'client_id' => $provider->get('clientId'), 'client_secret' => $this->crypt->decrypt($provider->get('clientSecret')),
                    'redirect_uri' => $this->redirect(), 'code_verifier' => $this->crypt->decrypt($transaction->get('verifier')),
                ])]);
            $raw = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $tokens = $raw === false ? null : json_decode($raw, true);
            if ($status !== 200 || !is_array($tokens) || empty($tokens['access_token']) || empty($tokens['refresh_token'])) throw new Failure('authorizationDenied');
            $credentialId = $this->em->getTransactionManager()->run(function () use ($transaction, $accountId, $tokens) {
                $credentialId = $transaction->get('credentialId');
                if ($credentialId) {
                    $credential = $this->access->credential($accountId, $credentialId, true);
                    $account = $this->em->getEntityById('OAuthAccount', $credential->get('oAuthAccountId'));
                } else {
                    $teamId = $transaction->get('teamId');
                    if (!in_array($teamId, array_column($this->access->setupTeams($accountId), 'id'), true)) throw new Forbidden();
                    $name = 'Google Meet — ' . gmdate('Y-m-d H:i');
                    $account = $this->records->get('OAuthAccount')->create((object) [
                        'name' => $name, 'providerId' => 'msx_gmeet_01', 'teamsIds' => [$teamId],
                    ])->getEntity();
                    $credential = $this->records->get('Credential')->create((object) [
                        'name' => $name, 'credentialTypeId' => 'msx_ct_gmeet_01', 'oAuthAccountId' => $account->getId(),
                        'isActive' => true, 'teamsIds' => [$teamId], 'assignedUserId' => $this->user->getId(),
                    ])->getEntity();
                }
                $this->tokens->set($account, new AccessToken($tokens));
                $account->set('googleMeetGrantedScopes', preg_split('/\s+/', trim((string) ($tokens['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY));
                $this->em->saveEntity($account);
                return $credential->getId();
            });
            $result += ['credentialId' => $credentialId];
            $result['ok'] = true;
        } catch (\Throwable $e) {
            $result['error'] = $e instanceof Failure ? $e->category : 'authorizationDenied';
        } finally {
            $transaction->set('verifier', null);
            $this->em->saveEntity($transaction);
        }
        return $result;
    }
}
