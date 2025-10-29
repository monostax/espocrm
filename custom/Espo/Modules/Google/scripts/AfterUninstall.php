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

use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config\ConfigWriter;

class AfterUninstall
{
    protected $container;

    public function run($container)
    {
        $this->container = $container;

        /** @var EntityManager $entityManager */
        $entityManager = $this->container->get('entityManager');
        /** @var Config $config */
        $config = $this->container->get('config');
        /** @var InjectableFactory $injectableFactory */
        $injectableFactory = $this->container->get('injectableFactory');
        /** @var ConfigWriter $configWriter */
        $configWriter = $injectableFactory->create(ConfigWriter::class);

        if ($job = $entityManager->getRepository('ScheduledJob')->where(['job' => 'SynchronizeEventsWithGoogleCalendar'])->findOne()) {
            $entityManager->removeEntity($job);
        }

        $this->removeAdminIframeUrl($config, $configWriter);
    }

    private function removeAdminIframeUrl(Config $config, ConfigWriter $configWriter): void
    {
        /** @var ?string $url */
        $url = $config->get('adminPanelIframeUrl');
        $url = $this->removeUrlParam($url, 'google-integration', '/');

        if ($url == $config->get('adminPanelIframeUrl')) {
            return;
        }

        $configWriter->set('adminPanelIframeUrl', $url);
        $configWriter->save();
    }

    private function removeUrlParam(string $url, string $paramName, string $suffix = ''): string
    {
        $urlQuery = parse_url($url, \PHP_URL_QUERY);

        if ($urlQuery) {
            parse_str($urlQuery, $params);

            if (isset($params[$paramName])) {
                unset($params[$paramName]);

                $newUrl = str_replace($urlQuery, http_build_query($params), $url);

                if (empty($params)) {
                    /** @var string $newUrl */
                    $newUrl = preg_replace('/\/\?$/', '', $newUrl);
                    /** @var string $newUrl */
                    $newUrl = preg_replace('/\/$/', '', $newUrl);

                    $newUrl .= $suffix;
                }

                return $newUrl;
            }
        }

        return $url;
    }
}
