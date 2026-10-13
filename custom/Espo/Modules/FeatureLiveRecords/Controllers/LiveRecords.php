<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Controllers;

use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Core\Exceptions\Forbidden;

final class LiveRecords
{
    public function __construct(private User $user, private Config $config) {}

    public function getActionContext(): object
    {
        if (!$this->user->isActive() || $this->user->isPortal() || $this->user->isApi()) throw new Forbidden();
        $url = $this->config->get('webSocketUrl');
        if (!$url) {
            $site = parse_url($this->config->get('siteUrl') ?? '');
            $secure = ($site['scheme'] ?? '') === 'https';
            $url = ($secure ? 'wss://' : 'ws://') . ($site['host'] ?? '') . ($secure ? '/wss' : ':8080');
        }
        return (object) ['enabled' => (bool) $this->config->get('useWebSocket'), 'userId' => $this->user->getId(), 'url' => $url];
    }
}
