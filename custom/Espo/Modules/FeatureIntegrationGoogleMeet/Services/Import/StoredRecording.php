<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

use Espo\Core\FileStorage\Attachment;

/** No generic Attachment entity: the Meeting playback route is the only download authority. */
final class StoredRecording implements Attachment
{
    public function __construct(private string $key)
    {
        if (!preg_match('/^meeting-(?:stage|recording)-[a-f0-9]{64}$/D', $key)) throw new Failure('storage');
    }
    public function getSourceId(): string { return $this->key; }
}
