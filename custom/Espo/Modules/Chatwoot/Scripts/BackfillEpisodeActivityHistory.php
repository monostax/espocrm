<?php

namespace Espo\Modules\Chatwoot\Scripts;

use Espo\Core\Container;
use Espo\Modules\Chatwoot\Services\ConversationEpisodeSync;
use RuntimeException;

/** Invoked through run-module-script.php; scoped to one explicit CRM account. */
class BackfillEpisodeActivityHistory
{
    public function run(Container $container): void
    {
        $accountId = getenv('CRM_ACCOUNT_ID');
        if (!$accountId) {
            throw new RuntimeException('CRM_ACCOUNT_ID is required');
        }
        $account = $container->get('entityManager')->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            throw new RuntimeException('CRM account not found');
        }
        $sync = $container->get('injectableFactory')->create(ConversationEpisodeSync::class);
        $after = (int) (getenv('AFTER') ?: 0);
        $maxPages = max(1, (int) (getenv('MAX_PAGES') ?: 10));
        $apply = getenv('APPLY') === 'true';

        for ($page = 0; $page < $maxPages; $page++) {
            $stats = $sync->backfillActivityHistory($account, $after, $apply);
            echo json_encode(['accountId' => $accountId, 'apply' => $apply] + $stats, JSON_THROW_ON_ERROR) . PHP_EOL;
            $after = $stats['after'];
            if (!$stats['hasMore']) {
                break;
            }
        }
    }
}
