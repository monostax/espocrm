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

class AfterInstall
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

        if (
            !$entityManager
                ->getRepository('ScheduledJob')
                ->where(['job' => 'SynchronizeEventsWithGoogleCalendar'])
                ->findOne()
        ) {
            $job = $entityManager->getNewEntity('ScheduledJob');

            $job->set([
               'name' => 'Google Calendar Sync',
               'job' => 'SynchronizeEventsWithGoogleCalendar',
               'status' => 'Active',
               'scheduling' => '*/10 * * * *',
            ]);

            $entityManager->saveEntity($job);
        }

        $this->addAdminIframeUrl($config, $configWriter);

        $this->clearCache();
    }

    protected function clearCache()
    {
        try {
            $this->container->get('dataManager')->clearCache();
        }
        catch (\Exception $e) {}
    }

    private function addAdminIframeUrl(Config $config, ConfigWriter $configWriter): void
    {
        /** @var ?string $url */
        $url = $config->get('adminPanelIframeUrl');

        if (empty($url) || trim($url) == '/') {
            $url = 'https://s.espocrm.com/';
        }

        $url = $this->addUrlParam($url, 'instanceId', $config->get('instanceId'));
        $url = $this->addUrlParam($url, 'google-integration', '99e925c7f52e4853679eb7c383162336');

        if ($url == $config->get('adminPanelIframeUrl')) {
            return;
        }

        $configWriter->set('adminPanelIframeUrl', $url);
        $configWriter->save();
    }

    private function addUrlParam(string $url, string $paramName, $paramValue): string
    {
        $urlQuery = parse_url($url, PHP_URL_QUERY);

        if (!$urlQuery) {
            $params = [
                $paramName => $paramValue
            ];

            $url = trim($url);
            /** @var string $url */
            $url = preg_replace('/\/\?$/', '', $url);
            /** @var string $url */
            $url = preg_replace('/\/$/', '', $url);

            return $url . '/?' . http_build_query($params);
        }

        parse_str($urlQuery, $params);

        if (!isset($params[$paramName]) || $params[$paramName] != $paramValue) {
            $params[$paramName] = $paramValue;

            return str_replace($urlQuery, http_build_query($params), $url);
        }

        return $url;
    }
}
