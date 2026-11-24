<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\User;

use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder;

/** CRM API users bound to an active managed AI agent identity (never other API keys). */
class ManagedAgents
{
    public static function idIn(): WhereItem
    {
        return Cond::in(
            Expr::column('id'),
            SelectBuilder::create()
                ->from('ChatwootMachineIdentity')
                ->select('crmUserId')
                ->where(['kind' => 'agent', 'status' => 'active', 'crmUserId!=' => null])
                ->build()
        );
    }
}
