<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\DeleteResult;
use Espo\Core\Record\Service;

/** Preserve the thread anchor when deleting a post that has replies. */
class Note extends Service
{
    public function delete(string $id, DeleteParams $params = new DeleteParams()): DeleteResult
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $params): DeleteResult {
            $note = $this->getRepository()->where(['id' => $id])->forUpdate()->findOne();
            if (!$note || $note->get('parentType') !== 'Opportunity' || $note->get('type') !== 'Post' ||
                $note->get('opportunityThreadRootId') ||
                !$this->getRepository()->where(['opportunityThreadRootId' => $id])->findOne()) {
                return parent::delete($id, $params);
            }
            if (!$this->acl->check('Note', 'delete') || !$this->acl->checkEntityDelete($note)) {
                throw new Forbidden();
            }
            foreach ($this->getRepository()->getRelation($note, 'attachments')->find() as $attachment) {
                $this->entityManager->removeEntity($attachment);
            }
            $note->set(['post' => '', 'data' => (object) [], 'opportunityMentionUserIds' => [], 'opportunityPostDeleted' => true]);
            $this->entityManager->saveEntity($note);
            $this->processActionHistoryRecord('delete', $note);
            return new DeleteResult();
        });
    }
}
