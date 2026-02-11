<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Utils\Config;

/**
 * Entry point for serving the PWA web manifest at /?entryPoint=pwaManifest.
 * Must be served without authentication for the browser to process it.
 */
class PwaManifest implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private Config $config
    ) {}

    public function run(Request $request, Response $response): void
    {
        $manifest = [
            'name' => $this->config->get('pwaAppName', 'Monostax CRM'),
            'short_name' => $this->config->get('pwaShortName', 'CRM'),
            'description' => 'CRM Application',
            'start_url' => '/',
            'scope' => '/',
            'display' => $this->config->get('pwaDisplayMode', 'standalone'),
            'orientation' => $this->config->get('pwaOrientation', 'any'),
            'background_color' => $this->config->get('pwaBackgroundColor', '#ffffff'),
            'theme_color' => $this->config->get('pwaThemeColor', '#1976d2'),
            'icons' => [
                [
                    'src' => '/client/custom/img/logo-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => '/client/custom/img/logo-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
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
                            'sizes' => '192x192',
                        ],
                    ],
                ],
            ],
        ];

        $response->setHeader('Content-Type', 'application/manifest+json');
        $response->setHeader('Cache-Control', 'public, max-age=86400');
        $response->writeBody(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
