<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Subscriptions;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Taps;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Workspaces;

/** Settings › Avisos no WhatsApp: each person's own subscription in the workspace given by `accountId`. */
class WhatsAppNotifications
{
    public function __construct(
        private Subscriptions $subscriptions,
        private Taps $taps,
        private Settings $settings,
        private Workspaces $workspaces,
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
}
