<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\CardImages;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Subscriptions;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Taps;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Workspaces;

/** Settings › Notificações no WhatsApp: each person's own subscription in the workspace given by `accountId`. */
class WhatsAppNotifications
{
    public function __construct(
        private Subscriptions $subscriptions,
        private Taps $taps,
        private Settings $settings,
        private Workspaces $workspaces,
        private CardImages $images,
    ) {}

    public function getActionMe(Request $request): object
    {
        return (object) $this->subscriptions->me($request);
    }

    public function putActionUpdate(Request $request): object
    {
        return (object) $this->subscriptions->update($request);
    }

    public function postActionCode(Request $request): object
    {
        return (object) $this->subscriptions->code($request);
    }

    public function postActionStop(Request $request): object
    {
        return (object) $this->subscriptions->stopMine($request);
    }

    /** "Enviar meu Em dia agora": the next card now (the template when the window is closed). 409 `noCards` when none. */
    public function postActionTest(Request $request, Response $response): object
    {
        $subscription = $this->subscriptions->mine($request);
        if (!$this->settings->enabled() || $this->workspaces->of($subscription)['frozen'] || !$this->taps->next($subscription, 'card', null, false)) {
            $response->setStatus(409);
            return (object) ['reason' => 'noCards'];
        }
        return (object) ['sent' => true];
    }

    /** The card picture in a template's header. No login: Meta fetches it by the signed link (CardImages::url). */
    public function getActionCardImage(Request $request, Response $response): void
    {
        $cardId = $this->settings->enabled() ? $this->images->cardId((string) $request->getRouteParam('token')) : null;
        $png = $cardId !== null ? $this->images->render($cardId) : null;
        if ($png === null) {
            $response->setStatus(404);
            return;
        }
        $response->setHeader('Content-Type', 'image/png')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Length', (string) strlen($png))
            ->writeBody($png);
    }
}
