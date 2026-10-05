<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

/** Companies share Contacts' workspace-scoped, server-paginated inbox contract. */
class AccountInbox extends ContactInbox
{
    protected const ENTITY_TYPE = 'Account';
    protected const GROUP_FIELDS = [
        'assignee' => ['assignedUserId', 'assignedUser'],
        'type' => ['type', 'type'],
        'industry' => ['industry', 'industry'],
    ];
    protected const SORT_FIELDS = ['streamUpdatedAt', 'createdAt', 'modifiedAt', 'name', 'assignedUserName', 'type', 'industry'];
    protected const FIELDS = [
        'name', 'website', 'emailAddress', 'phoneNumber', 'type', 'industry', 'assignedUser', 'teams',
        'billingAddressStreet', 'billingAddressCity', 'billingAddressState', 'billingAddressCountry', 'billingAddressPostalCode',
        'shippingAddressStreet', 'shippingAddressCity', 'shippingAddressState', 'shippingAddressCountry', 'shippingAddressPostalCode',
        'description', 'customFields', 'createdAt', 'modifiedAt', 'createdBy',
    ];
    protected const EDIT_FIELDS = [
        'name', 'website', 'emailAddress', 'phoneNumber', 'type', 'industry', 'assignedUserId',
        'billingAddressStreet', 'billingAddressCity', 'billingAddressState', 'billingAddressCountry', 'billingAddressPostalCode',
        'shippingAddressStreet', 'shippingAddressCity', 'shippingAddressState', 'shippingAddressCountry', 'shippingAddressPostalCode',
        'description', 'customFields',
    ];
}
