<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Contact\Where;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\Where\Item;
use Espo\Core\Select\Where\Item\Type;
use Espo\Core\Select\Where\ItemConverter;
use Espo\ORM\Query\Part\WhereClause;
use Espo\ORM\Query\Part\WhereItem;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Query\SelectBuilder;

/** Contacts keep LinkedIn as a channel identity, not a column; filter through the identity table. */
final class HasLinkedin implements ItemConverter
{
    public function __construct(private Acl $acl, private User $user, private UserTenantResolver $tenants) {}

    public function convert(SelectBuilder $queryBuilder, Item $item): WhereItem
    {
        // Same gate as ChannelIdentitiesLoader: no identity access, no identity-derived filtering.
        if (!$this->acl->checkScope('ContactChannelIdentity', Acl\Table::ACTION_READ)) {
            throw new Forbidden('Channel identity access required.');
        }
        $where = ['channelType' => 'linkedin', 'deleted' => false];
        // Scope the identity scan to the user's tenants so the (tenantId, channelType, ...) index applies.
        if (!$this->user->isAdmin()) $where['tenantId'] = $this->tenants->resolveTenantIds($this->user);
        $linked = SelectBuilder::create()
            ->from('ContactChannelIdentity')
            ->select('contactId')
            ->where($where)
            ->build();
        return WhereClause::fromRaw([$item->getType() === Type::IS_FALSE ? 'id!=s' : 'id=s' => $linked]);
    }
}
