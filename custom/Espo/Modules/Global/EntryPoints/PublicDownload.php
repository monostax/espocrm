<?php
/************************************************************************
 * Public Download Entry Point
 *
 * Allows access to attachments marked as public without authentication.
 ************************************************************************/

namespace Espo\Modules\Global\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFoundSilent;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Attachment as AttachmentEntity;

class PublicDownload implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private FileStorageManager $fileStorageManager,
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    public function run(Request $request, Response $response): void
    {
        $id = $request->getQueryParam('id');

        if (!$id) {
            throw new BadRequest("No id.");
        }

        /** @var ?AttachmentEntity $attachment */
        $attachment = $this->entityManager->getEntityById(AttachmentEntity::ENTITY_TYPE, $id);

        if (!$attachment) {
            throw new NotFoundSilent("Attachment not found.");
        }

        // Check if attachment is marked as public
        if (!$attachment->get('isPublic')) {
            throw new Forbidden("Attachment is not public.");
        }

        if ($attachment->isBeingUploaded()) {
            throw new Forbidden("Attachment is being uploaded.");
        }

        $stream = $this->fileStorageManager->getStream($attachment);

        $outputFileName = str_replace("\"", "\\\"", $attachment->getName() ?? '');

        $type = $attachment->getType();

        $disposition = 'attachment';

        /** @var string[] $inlineMimeTypeList */
        $inlineMimeTypeList = $this->metadata->get(['app', 'file', 'inlineMimeTypeList']) ?? [];

        if (in_array($type, $inlineMimeTypeList)) {
            $disposition = 'inline';

            $response->setHeader('Content-Security-Policy', "default-src 'self'");
        }

        $response->setHeader('Content-Description', 'File Transfer');

        if ($type) {
            $response->setHeader('Content-Type', $type);
        }

        $size = $stream->getSize() ?? $this->fileStorageManager->getSize($attachment);

        $response
            ->setHeader('Content-Disposition', $disposition . ";filename=\"" . $outputFileName . "\"")
            ->setHeader('Expires', '0')
            ->setHeader('Cache-Control', 'must-revalidate')
            ->setHeader('Content-Length', (string) $size)
            ->setBody($stream);
    }
}
