<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Services;

use Espo\Core\Record\CreateParams;
use Espo\Core\Record\CreateResult;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Record\UpdateResult;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\DeleteResult;
use Espo\ORM\Entity;
use stdClass;

/** Preserve the native record contract, with one transaction enclosing series binding. */
class Task extends \Espo\Services\Record
{
    private function recurrenceService(): Recurrence
    {
        return $this->injectableFactory->create(Recurrence::class);
    }

    private function filterIdentity(stdClass $data): void
    {
        foreach (['recurrenceSeriesId', 'recurrenceSeriesName', 'recurrenceId', 'recurrenceOverrides'] as $name) unset($data->$name);
    }

    public function create(stdClass $data, CreateParams $params = new CreateParams()): CreateResult
    {
        $this->filterIdentity($data);
        $input = $data->recurrence ?? null;
        unset($data->recurrence);
        if (!$input) return parent::create($data, $params);
        if (!is_object($input)) throw new \Espo\Core\Exceptions\BadRequest('recurrence: Expected a definition object.');
        return $this->recurrenceService()->create($data, $input, fn ($values) => parent::create($values, $params));
    }

    public function update(string $id, stdClass $data, UpdateParams $params = new UpdateParams()): UpdateResult
    {
        $this->filterIdentity($data);
        if (isset($data->recurrence)) throw new \Espo\Core\Exceptions\BadRequest('Use the scoped recurrence API to change a series.');
        unset($data->recurrence);
        return $this->entityManager->getTransactionManager()->run(fn () => parent::update($id, $data, $params));
    }

    public function delete(string $id, DeleteParams $params = new DeleteParams()): DeleteResult
    {
        return $this->entityManager->getTransactionManager()->run(fn () => parent::delete($id, $params));
    }

    public function loadAdditionalFields(Entity $entity): void
    {
        parent::loadAdditionalFields($entity);
        if ($entity->get('recurrenceSeriesId') && $this->acl->checkField('Task', 'recurrence')) {
            $entity->set('recurrence', $this->recurrenceService()->read($entity->getId()));
        }
    }

    public function prepareEntityForOutput(Entity $entity): void
    {
        parent::prepareEntityForOutput($entity);
        if (!$this->acl->checkField('Task', 'recurrence')) {
            foreach (array_keys((array) $entity->getValueMap()) as $name) if (str_starts_with($name, 'recurrence')) $entity->clear($name);
        }
    }
}
