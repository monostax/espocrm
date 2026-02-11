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

/**
 * Entry point for serving the PWA service worker at /?entryPoint=pwaServiceWorker.
 * Must be served without authentication so the browser can register it.
 */
class PwaServiceWorker implements EntryPoint
{
    use NoAuth;

    public function run(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/javascript');
        $response->setHeader('Service-Worker-Allowed', '/');
        $response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');

        $response->writeBody($this->getServiceWorkerContent());
    }

    private function getServiceWorkerContent(): string
    {
        return <<<'JS'
// EspoCRM PWA Service Worker
// Version: 1.1.0
// Purpose: Push notifications only. No fetch interception to avoid
// interfering with EspoCRM's own authentication and caching.

/**
 * Install event - activate immediately
 */
self.addEventListener('install', (event) => {
    console.log('[ServiceWorker] Install');
    self.skipWaiting();
});

/**
 * Activate event - claim clients immediately
 */
self.addEventListener('activate', (event) => {
    console.log('[ServiceWorker] Activate');
    event.waitUntil(self.clients.claim());
});

/**
 * Push event - display notification
 */
self.addEventListener('push', (event) => {
    console.log('[ServiceWorker] Push received');

    let data = {
        title: 'EspoCRM',
        body: 'You have a new notification',
        icon: '/client/custom/img/logo-192.png',
        url: '/'
    };

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: data.icon || '/client/custom/img/logo-192.png',
        badge: '/client/custom/img/logo-192.png',
        vibrate: [100, 50, 100],
        data: {
            url: data.url || '/',
            timestamp: Date.now()
        },
        actions: data.actions || []
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

/**
 * Notification click event - focus or open window
 */
self.addEventListener('notificationclick', (event) => {
    console.log('[ServiceWorker] Notification click');
    event.notification.close();

    const url = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    if (client.url === url && 'focus' in client) {
                        return client.focus();
                    }
                }
                if (clients.openWindow) {
                    return clients.openWindow(url);
                }
            })
    );
});

console.log('[ServiceWorker] Loaded');
JS;
    }
}
