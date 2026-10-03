<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Controllers;

use Espo\Core\Exceptions\Forbidden;

/** Deliberately no RecordBase inheritance: generic endpoints cannot bypass ACL/evidence checks. */
abstract class Internal
{
    public function getActionRead(): never { throw new Forbidden(); }
    public function getActionList(): never { throw new Forbidden(); }
    public function postActionCreate(): never { throw new Forbidden(); }
    public function putActionUpdate(): never { throw new Forbidden(); }
    public function patchActionUpdate(): never { throw new Forbidden(); }
    public function deleteActionDelete(): never { throw new Forbidden(); }
}
