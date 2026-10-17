<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FileStorage\Storages\EspoUploadDir;
use Espo\ORM\EntityManager;
use GuzzleHttp\Psr7\LimitStream;

class Files
{
    public function __construct(private EspoUploadDir $storage, private EntityManager $em, private Access $access,
        private Documents $documents) {}

    public function path(string $key): string { return $this->storage->getLocalFilePath(new StoredRecording($key)); }

    /** Same private storage volume across all CRM workers; OS releases the lock on process death. */
    public function lock(): mixed
    {
        $handle = fopen('data/upload/.meeting-google-transfer.lock', 'c');
        if (!$handle) throw new Failure('storage');
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
        return $handle;
    }

    public function remove(string $key): void
    {
        $file = new StoredRecording($key);
        if ($this->storage->exists($file)) $this->storage->unlink($file);
    }

    public function playback(int $accountId, string $meetingId, string $unitId, Request $request, Response $response): void
    {
        $this->access->meeting($accountId, $meetingId);
        $this->access->recording();
        $aggregate = $this->documents->aggregate($meetingId);
        $unit = $aggregate ? $this->em->getRDBRepository('MeetingGoogleUnit')->where([
            'id' => $unitId, 'importId' => $aggregate->getId(), 'kind' => 'recording', 'status' => 'succeeded',
        ])->findOne() : null;
        if (!$unit || !$unit->get('storageKey')) throw new NotFound();
        $file = new StoredRecording($unit->get('storageKey'));
        if (!$this->storage->exists($file)) throw new NotFound();
        $size = $this->storage->getSize($file);
        $response->setHeader('Content-Type', 'video/mp4')->setHeader('Accept-Ranges', 'bytes')
            ->setHeader('Cache-Control', 'private, no-store')->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Disposition', 'inline; filename="recording.mp4"');
        $start = 0;
        $end = $size - 1;
        $range = $request->getHeader('Range');
        if ($range !== null) {
            $valid = preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match) && ($match[1] !== '' || $match[2] !== '');
            if ($valid) {
                if ($match[1] === '') $start = max(0, $size - (int) $match[2]);
                else { $start = (int) $match[1]; $end = $match[2] === '' ? $end : min($end, (int) $match[2]); }
                $valid = $start < $size && $end >= $start && !($match[1] === '' && (int) $match[2] === 0);
            }
            if (!$valid) {
                $response->setStatus(416)->setHeader('Content-Range', 'bytes */' . $size)->setHeader('Content-Length', '0');
                return;
            }
            $response->setStatus(206)->setHeader('Content-Range', "bytes $start-$end/$size");
        }
        $response->setHeader('Content-Length', (string) ($end - $start + 1));
        if ($request->getMethod() !== 'HEAD') $response->setBody(new LimitStream($this->storage->getStream($file), $end - $start + 1, $start));
    }
}
