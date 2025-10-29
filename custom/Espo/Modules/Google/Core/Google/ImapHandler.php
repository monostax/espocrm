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

namespace Espo\Modules\Google\Core\Google;

use Espo\Core\ExternalAccount\ClientManager;
use Laminas\Mail\Protocol\Imap;

class ImapHandler
{
    private ClientManager $clientManager;

    public function __construct(
        ClientManager $clientManager
    ) {
        $this->clientManager = $clientManager;
    }

    public function prepareProtocol(string $userId, string $emailAddress, array $params)
    {
        $username = $emailAddress;

        if (!$username) {
            return null;
        }

        $client = $this->clientManager->create('Google', $userId);

        if (!$client) {
            return null;
        }

        if (!$client->getParam('expiresAt')) {
            // for backward compatibility
            $client->getGmailClient()->productPing();
            $accessToken = $client->getGmailClient()->getParam('accessToken');
        } else {
            $client->handleAccessTokenActuality();
            $accessToken = $client->getParam('accessToken');
        }

        if (!$accessToken) {
            return null;
        }

        $ssl = false;

        if (!empty($params['ssl'])) {
            $ssl = 'SSL';
        }

        if (!empty($params['security'])) {
            $ssl = $params['security'];
        }

        $protocol = new Imap($params['host'], $params['port'], $ssl);

        $authString = base64_encode("user=$username\1auth=Bearer $accessToken\1\1");
        $authenticateParams = ['XOAUTH2', $authString];
        $protocol->sendRequest('AUTHENTICATE', $authenticateParams);

        $i = 0;

        while (true) {
            if ($i === 10) {
                return null;
            }

            $response = '';
            $isPlus = $protocol->readLine($response, '+', true);

            if ($isPlus) {
                $GLOBALS['log']->error("Google Imap: Extra server challenge: " . $response);

                $protocol->sendRequest('');
            } else {
                if (
                    preg_match('/^NO /i', $response) ||
                    preg_match('/^BAD /i', $response)
                ) {
                    $GLOBALS['log']->error("Google Imap: Failure: " . $response);

                    return null;
                }
                else if (preg_match("/^OK /i", $response)) {
                    break;
                }
            }

            $i++;
        }

        return $protocol;
    }
}
