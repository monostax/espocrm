<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;

/**
 * "Sobre a empresa": what the business sells and who buys, so Em dia can tell one of its sales from everything else (a
 * photographer's own surgery quote was found as a deal, 2026-11-27). It is the Chatwoot account setting
 * `business_description`, which Rollout passes on as `businessDescription`. While it is empty the backend drafts it from
 * the account's conversations (Hatchet `catch-up-business`) and hands the draft to saveDraft(); people correct it in
 * Settings › Em dia.
 */
class BusinessDescription
{
    public const MAX_LENGTH = 2000;

    public function __construct(private EntityManager $em, private ChatwootApiClient $api) {}

    /**
     * Store a draft unless the account has a description by now: one a person wrote meanwhile wins.
     *
     * @return array{saved: bool, skipped?: string}
     */
    public function saveDraft(int $chatwootAccountId, string $text): array
    {
        $text = trim(mb_substr(trim($text), 0, self::MAX_LENGTH));
        if ($chatwootAccountId < 1 || $text === '') throw new BadRequest('Invalid business description.');
        $accounts = iterator_to_array($this->em->getRDBRepository('ChatwootAccount')
            ->where(['chatwootAccountId' => $chatwootAccountId, 'status' => 'active'])->limit(0, 2)->find());
        $account = count($accounts) === 1 ? reset($accounts) : null;
        if (!$account) return ['saved' => false, 'skipped' => 'account'];
        $platform = $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
        // Settings change through the Account API, with the account's own administrator key (the Platform API can't).
        if (!$platform?->get('backendUrl') || !$platform->get('accessToken') || !$account->get('apiKey')) {
            return ['saved' => false, 'skipped' => 'platform'];
        }
        $url = (string) $platform->get('backendUrl');
        $remote = $this->api->getAccount($url, (string) $platform->get('accessToken'), $chatwootAccountId);
        if (trim((string) ($remote['catch_up']['businessDescription'] ?? '')) !== '') return ['saved' => false, 'skipped' => 'exists'];
        $this->api->updateAccountSettings($url, (string) $account->get('apiKey'), $chatwootAccountId, ['business_description' => $text]);
        return ['saved' => true];
    }
}
