<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;
use stdClass;

/**
 * Operator-controlled activation, pilot allowlist, rate limits, and dispatch kill switch (D9, D20, D25).
 * Deployed disabled by default until minimal Brazil inputs and pricing are validated and approved.
 */
class CapabilityGate
{
    public const CONFIG_KEY = 'featureCreditsPhoneVerification';
    public const PRICE_CREDITS = '8.0000';
    public const PROVIDER_TIMEOUT_MS = 30000;
    public const OPERATION_DEADLINE_SECONDS = 300;
    public const FRESH_REUSE_SECONDS = 2592000; // 30 days
    public const RETENTION_SECONDS = 7776000; // 90 days
    public const DEFAULT_MAX_OUTSTANDING = 5;
    public const DEFAULT_MAX_PER_MINUTE = 10;
    public const DEFAULT_MAX_PER_DAY = 100;
    public const DEFAULT_COOLDOWN_SECONDS = 60;

    private ?array $published = null;

    public function __construct(
        private Config $config,
        private TwilioCredentials $credentials,
        private Clock $clock,
        private ?ConfigWriter $configWriter = null,
    ) {}

    public function configuration(): array
    {
        $raw = $this->published ?? $this->config->get(self::CONFIG_KEY);
        $data = $raw instanceof stdClass ? (array) $raw : (is_array($raw) ? $raw : []);
        $envKill = getenv('VERIFICATION_KILL_SWITCH');
        $killSwitch = (bool) ($data['killSwitch'] ?? false) ||
            (is_string($envKill) && in_array(strtolower(trim($envKill)), ['1', 'true', 'yes'], true));
        $allowlist = [];
        foreach (is_array($data['allowlistedTenantIds'] ?? null) ? $data['allowlistedTenantIds'] : [] as $tenantId) {
            if (is_string($tenantId) && preg_match('/\A[a-zA-Z0-9_-]{1,24}\z/', $tenantId)) {
                $allowlist[] = $tenantId;
            }
        }
        $allowlist = array_values(array_unique($allowlist));
        sort($allowlist, SORT_STRING);

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'killSwitch' => $killSwitch,
            'brazilMinimalInputValidated' => (bool) ($data['brazilMinimalInputValidated'] ?? false),
            'releaseApprovalRef' => is_string($data['releaseApprovalRef'] ?? null) && $data['releaseApprovalRef'] !== ''
                ? $data['releaseApprovalRef'] : null,
            'allowlistedTenantIds' => $allowlist,
            'maxOutstandingPerTenant' => $this->boundedInt($data['maxOutstandingPerTenant'] ?? null, self::DEFAULT_MAX_OUTSTANDING, 1, 100),
            'maxPerMinutePerTenant' => $this->boundedInt($data['maxPerMinutePerTenant'] ?? null, self::DEFAULT_MAX_PER_MINUTE, 1, 1000),
            'maxPerDayPerTenant' => $this->boundedInt($data['maxPerDayPerTenant'] ?? null, self::DEFAULT_MAX_PER_DAY, 1, 10000),
            'sameInputCooldownSeconds' => $this->boundedInt($data['sameInputCooldownSeconds'] ?? null, self::DEFAULT_COOLDOWN_SECONDS, 1, 3600),
            'updatedAt' => is_string($data['updatedAt'] ?? null) ? $data['updatedAt'] : null,
            'updatedById' => is_string($data['updatedById'] ?? null) ? $data['updatedById'] : null,
            'lastRecoveryAt' => is_string($data['lastRecoveryAt'] ?? null) ? $data['lastRecoveryAt'] : null,
        ];
    }

    public function inspect(string $tenantId): array
    {
        $cfg = $this->configuration();
        $creds = $this->credentials->get();
        $hasCreds = $creds !== [];
        $allowlisted = in_array($tenantId, $cfg['allowlistedTenantIds'], true);
        $approved = $cfg['brazilMinimalInputValidated'] && $cfg['releaseApprovalRef'] !== null;
        $available = $cfg['enabled'] && !$cfg['killSwitch'] && $approved && $hasCreds && $allowlisted;
        $reason = match (true) {
            $cfg['killSwitch'] => 'kill_switch',
            !$cfg['enabled'] || !$approved => 'verification_disabled',
            !$allowlisted => 'tenant_not_allowlisted',
            !$hasCreds => 'provider_unavailable',
            default => null,
        };

        return [
            'available' => $available,
            'reason' => $reason,
            'priceCredits' => self::PRICE_CREDITS,
            'policyVersion' => $this->policyVersion(),
            'limits' => [
                'maxOutstandingPerTenant' => $cfg['maxOutstandingPerTenant'],
                'maxPerMinutePerTenant' => $cfg['maxPerMinutePerTenant'],
                'maxPerDayPerTenant' => $cfg['maxPerDayPerTenant'],
                'sameInputCooldownSeconds' => $cfg['sameInputCooldownSeconds'],
            ],
        ];
    }

    public function policyVersion(): string
    {
        $creds = $this->credentials->get();
        $credentialDigest = $creds ? hash('sha256', json_encode($creds, JSON_THROW_ON_ERROR)) : 'unconfigured';
        return hash('sha256', json_encode([
            'phone-verification-policy-2026-10-14-v1',
            'twilio-lookup-v2-identity-match-br',
            self::PRICE_CREDITS,
            $credentialDigest,
        ], JSON_THROW_ON_ERROR));
    }

    public function publish(stdClass $input, string $actorId): array
    {
        GrantInput::identity($actorId);
        $vars = get_object_vars($input);
        $allowedKeys = [
            'enabled',
            'killSwitch',
            'brazilMinimalInputValidated',
            'releaseApprovalRef',
            'allowlistedTenantIds',
            'maxOutstandingPerTenant',
            'maxPerMinutePerTenant',
            'maxPerDayPerTenant',
            'sameInputCooldownSeconds',
        ];
        if (array_diff(array_keys($vars), $allowedKeys)) {
            throw new InvalidArgumentException('Unknown phone verification configuration parameter.');
        }
        $current = $this->configuration();
        foreach (['enabled', 'killSwitch', 'brazilMinimalInputValidated'] as $boolKey) {
            if (array_key_exists($boolKey, $vars)) {
                if (!is_bool($vars[$boolKey])) {
                    throw new InvalidArgumentException("Boolean value required for $boolKey.");
                }
                $current[$boolKey] = $vars[$boolKey];
            }
        }
        if (array_key_exists('releaseApprovalRef', $vars)) {
            if ($vars['releaseApprovalRef'] !== null &&
                (!is_string($vars['releaseApprovalRef']) || !preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/', $vars['releaseApprovalRef']))) {
                throw new InvalidArgumentException('Invalid release approval reference.');
            }
            $current['releaseApprovalRef'] = $vars['releaseApprovalRef'];
        }
        if (array_key_exists('allowlistedTenantIds', $vars)) {
            if (!is_array($vars['allowlistedTenantIds']) || count($vars['allowlistedTenantIds']) > 500) {
                throw new InvalidArgumentException('Allowlisted tenant IDs must be a bounded list.');
            }
            $tenants = [];
            foreach ($vars['allowlistedTenantIds'] as $tenantId) {
                if (!is_string($tenantId)) {
                    throw new InvalidArgumentException('Invalid allowlisted tenant ID.');
                }
                GrantInput::identity($tenantId);
                $tenants[] = $tenantId;
            }
            $tenants = array_values(array_unique($tenants));
            sort($tenants, SORT_STRING);
            $current['allowlistedTenantIds'] = $tenants;
        }
        foreach ([
            'maxOutstandingPerTenant' => [1, 100],
            'maxPerMinutePerTenant' => [1, 1000],
            'maxPerDayPerTenant' => [1, 10000],
            'sameInputCooldownSeconds' => [1, 3600],
        ] as $intKey => [$min, $max]) {
            if (array_key_exists($intKey, $vars)) {
                if (!is_int($vars[$intKey]) || $vars[$intKey] < $min || $vars[$intKey] > $max) {
                    throw new InvalidArgumentException("Invalid bound for $intKey.");
                }
                $current[$intKey] = $vars[$intKey];
            }
        }
        if ($current['enabled'] && (!$current['brazilMinimalInputValidated'] || $current['releaseApprovalRef'] === null)) {
            throw new InvalidArgumentException('Enabling paid verification requires validated Brazil minimal inputs and a release approval reference.');
        }
        $current['updatedAt'] = $this->clock->now();
        $current['updatedById'] = $actorId;
        $this->published = $current;
        if ($this->configWriter !== null) {
            $this->configWriter->set(self::CONFIG_KEY, $current);
            $this->configWriter->save();
        }
        return $current;
    }

    public function recordRecoveryHeartbeat(string $now): void
    {
        $current = $this->configuration();
        $current['lastRecoveryAt'] = $now;
        $this->published = $current;
        if ($this->configWriter === null) {
            return;
        }
        $this->configWriter->set(self::CONFIG_KEY, $current);
        $this->configWriter->save();
    }

    private function boundedInt(mixed $value, int $default, int $min, int $max): int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : $default;
    }
}
