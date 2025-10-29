<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2025 EspoCRM, Inc.
 *
 * License ID: 99e925c7f52e4853679eb7c383162336
 ************************************************************************************/

namespace Espo\Modules\Google\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ExternalAccount\ClientManager;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Services\ExternalAccount;
use Exception;

class GoogleGmail
{
    private ClientManager $clientManager;
    private Acl $acl;
    private User $user;
    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;

    public function __construct(
        ClientManager $clientManager,
        Acl $acl,
        User $user,
        EntityManager $entityManager,
        InjectableFactory $injectableFactory
    ) {
        $this->clientManager = $clientManager;
        $this->acl = $acl;
        $this->user = $user;
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
    }

    /**
     * @throws Forbidden
     */
    public function processAccessCheck(string $entityType, string $id)
    {
        if ($this->user->isAdmin()) {
            return;
        }

        if ($entityType === 'EmailAccount') {
            $record = $this->entityManager->getEntityById('EmailAccount', $id);

            if (!$record) {
                throw new Forbidden();
            }

            if (!$this->acl->check($record)) {
                throw new Forbidden();
            }

            return;
        }

        throw new Forbidden();
    }

    /**
     * @throws Error
     * @throws NotFound
     */
    public function connect(string $entityType, string $id, string $code)
    {
        $em = $this->entityManager;

        $this->injectableFactory
            ->create(ExternalAccount::class)
            ->authorizationCode('Google', $id, $code);

        if ($entityType === 'EmailAccount') {
            $imapHandler = 'Espo\\Modules\\Google\\Core\\Google\\ImapPersonalHandler';
            $smtpHandler = 'Espo\\Modules\\Google\\Core\\Google\\SmtpPersonalHandler';
        }
        else {
            $imapHandler = 'Espo\\Modules\\Google\\Core\\Google\\ImapGroupHandler';
            $smtpHandler = 'Espo\\Modules\\Google\\Core\\Google\\SmtpGroupHandler';
        }

        $inboundEmail = $em->getRepository($entityType)->getById($id);

        if ($inboundEmail) {
            $inboundEmail->set('imapHandler', $imapHandler);
            $inboundEmail->set('smtpHandler', $smtpHandler);

            $em->saveEntity($inboundEmail);
        }

        return true;
    }

    public function disconnect(string $entityType, string $id)
    {
        $em = $this->entityManager;

        $ea = $em->getRepository('ExternalAccount')->getById('Google__' . $id);

        if ($ea) {
            $ea->set([
                'accessToken' => null,
                'refreshToken' => null,
                'tokenType' => null,
                'enabled' => false,
            ]);

            $em->saveEntity($ea, ['silent' => true]);
        }

        $inboundEmail = $em->getRepository($entityType)->getById($id);

        if ($inboundEmail) {
            $inboundEmail->set('imapHandler', null);
            $inboundEmail->set('smtpHandler', null);

            $em->saveEntity($inboundEmail);
        }

        return true;
    }

    public function ping(string $entityType, string $id) : bool
    {
        $integration = $this->entityManager->getEntityById('ExternalAccount', 'Google__' . $id);

        if (!$integration) {
            return false;
        }

        try {
            $client = $this->clientManager->create('Google', $id);

            if ($client) {
                return $client->getGmailClient()->productPing();
            }
        }
        catch (Exception $e) {}

        return false;
    }
}
