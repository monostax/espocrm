<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Base;
use Espo\Core\Utils\Config;

/**
 * Controller for serving PWA manifest and service worker.
 */
class PwaManifest extends Base
{
    /**
     * Get the web app manifest.
     */
    public function actionGetManifest(Request $request, Response $response): void
    {
        $config = $this->config;

        $pwaConfig = $config->get('pwaPush') ?? [];

        $manifest = [
            'name' => $pwaConfig['appName'] ?? 'EspoCRM',
            'short_name' => $pwaConfig['shortName'] ?? 'EspoCRM',
            'description' => 'CRM Application',
            'start_url' => '/',
            'scope' => '/',
            'display' => $pwaConfig['displayMode'] ?? 'standalone',
            'orientation' => $pwaConfig['orientation'] ?? 'any',
            'background_color' => $pwaConfig['backgroundColor'] ?? '#ffffff',
            'theme_color' => $pwaConfig['themeColor'] ?? '#2196f3',
            'icons' => [
                [
                    'src' => '/client/custom/img/logo-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ],
                [
                    'src' => '/client/custom/img/logo-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ]
            ],
            'categories' => ['business', 'productivity'],
            'shortcuts' => [
                [
                    'name' => 'Dashboard',
                    'short_name' => 'Dashboard',
                    'url' => '/',
                    'icons' => [
                        [
                            'src' => '/client/custom/img/logo-192.png',
                            'sizes' => '192x192'
                        ]
                    ]
                ]
            ]
        ];

        $response->writeBody(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $response->setHeader('Content-Type', 'application/manifest+json');
        $response->setHeader('Cache-Control', 'public, max-age=86400');
    }
}
