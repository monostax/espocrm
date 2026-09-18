<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\Acl\AccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Entities\User;

/** Internal ledgers are accessible only through the owner/workspace-checked operation service. */
class BulkPostAccessChecker implements AccessChecker
{
    public function check(User $user, ScopeData $data): bool
    {
        return false;
    }
}
