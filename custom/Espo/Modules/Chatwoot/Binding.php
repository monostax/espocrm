<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;

class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        $binder->bindImplementation(
            \Espo\Tools\Notification\NoteHookProcessor::class,
            \Espo\Modules\Chatwoot\Tools\Stream\NoteHookProcessor::class,
        );
        $binder->bindImplementation(
            \Espo\Tools\Stream\RecordService\QueryHelper::class,
            \Espo\Modules\Chatwoot\Tools\Stream\QueryHelper::class,
        );
        $binder->bindImplementation(
            \Espo\Tools\Stream\MassNotePreparator::class,
            \Espo\Modules\Chatwoot\Tools\Stream\MassNotePreparator::class,
        );
    }
}
