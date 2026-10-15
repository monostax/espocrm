<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\WhatsAppCampaignContact\AccessControlFilters;

use Espo\Core\AclManager;
use Espo\Core\Select\AccessControl\Filter;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\SelectBuilder;

/** Lists/exports need SQL restrictions too; per-record ACL is not a list filter. */
class Mandatory implements Filter
{
    public function __construct(private User $user, private SelectBuilderFactory $select, private AclManager $acl) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $campaigns = $this->select->create()->from('WhatsAppCampaign')->forUser($this->user)
            ->withAccessControlFilter()->buildQueryBuilder()->select('id')->build();
        $queryBuilder->where(['whatsAppCampaignId=s' => $campaigns]);
        if (!$this->acl->checkScope($this->user, 'Contact', 'read') || !$this->acl->checkField($this->user, 'Contact', 'phoneNumber')) {
            $queryBuilder->where(['managedSendKey' => null]);
            return;
        }
        $contacts = $this->select->create()->from('Contact')->forUser($this->user)
            ->withAccessControlFilter()->buildQueryBuilder()->select('id')->build();
        $queryBuilder->leftJoin('whatsAppCampaign', 'managedCampaign')->leftJoin('contact', 'managedContact');
        $queryBuilder->where(['OR' => [
            ['managedSendKey' => null],
            ['AND' => [
                ['contactId=s' => $contacts],
                Cond::equal(Cond::column('managedContact.tenantId'), Cond::column('managedCampaign.tenantId'))->getRaw(),
                Cond::equal(Cond::column('chatwootAccountId'), Cond::column('managedCampaign.chatwootAccountId'))->getRaw(),
            ]],
        ]]);
    }
}
