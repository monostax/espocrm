<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\Crypt;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\GoogleMeetCredentialHelper;

class Discovery
{
    public function __construct(private EntityManager $em, private Access $access, private Google $google,
        private Documents $documents, private GoogleMeetCredentialHelper $credentials, private Crypt $crypt) {}

    public static function key(string $kind, string ...$ids): string { return hash('sha256', json_encode([$kind, ...$ids])); }

    public function capabilities(Entity $credential): array
    {
        $account = $this->em->getEntityById('OAuthAccount', $credential->get('oAuthAccountId'));
        $scopes = $account?->get('googleMeetGrantedScopes');
        return ['transcript' => true,
            'recording' => $scopes === null || in_array('https://www.googleapis.com/auth/drive.meet.readonly', $scopes, true),
            'notes' => $scopes === null || in_array('https://www.googleapis.com/auth/documents.readonly', $scopes, true)];
    }

    public function connections(int $accountId, string $meetingId): array
    {
        $this->access->meeting($accountId, $meetingId, true);
        $list = [];
        foreach ($this->credentials->getAccessibleCredentials() as $candidate) {
            try { $credential = $this->access->credential($accountId, $candidate->getId()); }
            catch (\Throwable) { continue; }
            $status = 'available';
            try { $this->google->token($credential->get('oAuthAccountId'), microtime(true) + 30); }
            catch (Failure $e) { $status = $e->category; }
            $reconnect = true;
            try { $this->access->credential($accountId, $credential->getId(), true); }
            catch (\Throwable) { $reconnect = false; }
            $list[] = ['id' => $credential->getId(), 'name' => $credential->get('name'), 'status' => $status,
                'capabilities' => $this->capabilities($credential), 'canReconnect' => $reconnect];
        }
        $provider = $this->em->getEntityById('OAuthProvider', 'msx_gmeet_01');
        return ['enabled' => $this->access->enabled(), 'list' => $list, 'teams' => $this->access->setupTeams($accountId),
            'setupAvailable' => (bool) ($provider?->get('isActive') && $provider->get('clientId') && $provider->get('clientSecret'))];
    }

    public function sessions(int $accountId, string $meetingId, string $credentialId, ?string $cursor): array
    {
        $this->access->meeting($accountId, $meetingId, true);
        $credential = $this->access->credential($accountId, $credentialId);
        $params = ['pageSize' => 25, 'filter' => 'end_time IS NOT NULL'];
        if ($cursor) {
            try { $data = json_decode($this->crypt->decrypt($cursor), true, 16, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { throw new BadRequest('Invalid session cursor.'); }
            if (($data['credential'] ?? null) !== $credentialId || ($data['account'] ?? null) !== $accountId || ($data['expires'] ?? 0) < time()) {
                throw new BadRequest('Invalid session cursor.');
            }
            $params['pageToken'] = $data['page'];
        }
        $data = $this->google->get($credential->get('oAuthAccountId'), Google::MEET . 'conferenceRecords', $params, microtime(true) + 30);
        $list = [];
        foreach ($data['conferenceRecords'] ?? [] as $session) {
            if (!isset($session['endTime'])) continue;
            $list[] = ['name' => Google::conference($session['name']), 'space' => $session['space'] ?? '',
                'startTime' => $session['startTime'] ?? '', 'endTime' => $session['endTime']];
        }
        return ['list' => $list, 'cursor' => empty($data['nextPageToken']) ? null : $this->crypt->encrypt(json_encode([
            'credential' => $credentialId, 'account' => $accountId, 'page' => $data['nextPageToken'], 'expires' => time() + 3600,
        ]))];
    }

    public function preview(int $accountId, string $meetingId, string $credentialId, string $conference, bool $readContent = true): array
    {
        $meeting = $this->access->meeting($accountId, $meetingId, true);
        $credential = $this->access->credential($accountId, $credentialId);
        $aggregate = $this->documents->aggregate($meetingId);
        if ($aggregate && $aggregate->get('conference') !== $conference) throw new Conflict('This Meeting already has a different source.');
        $account = $credential->get('oAuthAccountId');
        $deadline = microtime(true) + 300;
        $session = $this->google->get($account, Google::MEET . Google::conference($conference), [], $deadline);
        if (($session['name'] ?? null) !== $conference || empty($session['endTime'])) throw new BadRequest('Select a completed session.');
        $capabilities = $this->capabilities($credential);
        $units = [];
        $kinds = [];
        foreach (['transcript' => 'transcripts', 'recording' => 'recordings', 'notes' => 'smartNotes'] as $kind => $collection) {
            $kinds[$kind] = ['state' => 'missing', 'allowed' => false];
            try {
                if ($kind === 'recording') $this->access->recording(true);
                else $this->access->text($this->documents->destination($meeting, $kind));
                $kinds[$kind]['allowed'] = !$readContent || $capabilities[$kind];
            } catch (\Throwable) { $kinds[$kind]['state'] = 'denied'; continue; }
            if ($readContent && !$capabilities[$kind]) { $kinds[$kind]['state'] = 'consentRequired'; continue; }
            try {
                // Materialize completely before exposing a set as importable.
                $artifacts = iterator_to_array($this->google->pages($account, $conference . '/' . $collection, $collection, $deadline), false);
                foreach ($artifacts as $artifact) {
                    $resource = $artifact['name'];
                    if (!preg_match('~^' . preg_quote($conference . '/' . $collection . '/', '~') . '[A-Za-z0-9_-]+$~D', $resource)) throw new Failure('invalidSource');
                    $ready = ($artifact['state'] ?? '') === 'FILE_GENERATED';
                    $unit = ['kind' => $kind === 'notes' ? 'notesDocument' : $kind, 'resource' => $resource,
                        'sourceKey' => self::key($kind === 'notes' ? 'notesDocument' : $kind, $resource),
                        'title' => $resource, 'readiness' => $ready ? 'ready' : 'pending'];
                    if ($kind === 'recording' && $ready) {
                        $unit['fileId'] = Google::id($artifact['driveDestination']['file'] ?? '');
                        if ($readContent) try {
                            $file = $this->google->file($account, $unit['fileId'], $deadline);
                            $unit['bytes'] = $file['size'] ?? null;
                            if (($file['mimeType'] ?? '') !== 'video/mp4') $unit['error'] = 'unsupportedMedia';
                            if (isset($unit['bytes']) && (int) $unit['bytes'] > Google::MAX_BYTES) $unit['error'] = 'tooLarge';
                        } catch (Failure $e) { $unit['readiness'] = 'denied'; $unit['error'] = $e->category; }
                    }
                    if ($kind === 'notes' && $ready) {
                        $unit['documentId'] = Google::id($artifact['docsDestination']['document'] ?? '');
                        if ($readContent) try {
                            $doc = $this->google->doc($account, $unit['documentId'], $deadline);
                            $unit['title'] = $doc['title'] ?? $unit['documentId'];
                            foreach (Text::tabs($doc) as $tab) {
                                $key = self::key('notes', $unit['documentId'], $tab['id']);
                                $units[$key] = ['sourceKey' => $key, 'kind' => 'notes', 'resource' => $resource,
                                    'documentId' => $unit['documentId'], 'tabId' => $tab['id'], 'title' => $tab['title'],
                                    'parentId' => $tab['parentId'], 'depth' => $tab['depth'], 'readiness' => 'ready'];
                            }
                        } catch (Failure $e) { $unit['readiness'] = 'denied'; $unit['error'] = $e->category; }
                    }
                    $units[$unit['sourceKey']] = $unit;
                    $kinds[$kind]['state'] = 'available';
                }
            } catch (Failure $e) { $kinds[$kind] = ['allowed' => false, 'state' => $e->category]; }
        }
        if ($aggregate) {
            foreach ($this->em->getRDBRepository('MeetingGoogleUnit')->where(['importId' => $aggregate->getId()])->find() as $unit) {
                if (isset($units[$unit->get('sourceKey')]) && $unit->get('attemptId')) {
                    $units[$unit->get('sourceKey')]['status'] = $unit->get('status');
                    $units[$unit->get('sourceKey')]['error'] = $unit->get('error');
                }
            }
        }
        return ['conference' => $conference, 'startTime' => $session['startTime'] ?? '',
            'kinds' => $kinds, 'units' => array_values($units)];
    }
}
