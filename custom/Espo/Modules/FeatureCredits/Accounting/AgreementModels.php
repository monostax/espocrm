<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Modules\FeatureCredits\Pricing\AiCatalog;
use Espo\Modules\FeatureCredits\Pricing\AiModelPolicy;
use Espo\ORM\EntityManager;
use PDO;

/**
 * The models a tenant agreement prices: its main model, optional stream-agent
 * model and the models users may pick per AI Session or mention (decided
 * 2026-11-13). Agreements and their links are insert-only, so a pair of
 * (agreement, policy) is either linked forever or never.
 */
class AgreementModels
{
    public function __construct(private EntityManager $entityManager, private Clock $clock, private AiCatalog $catalog) {}

    /** Selectable rate record ids, in picker order, from the agreement row's immutable JSON list. */
    public static function selectableFromColumn(mixed $raw): array
    {
        if ($raw === null || $raw === '') return [];
        $ids = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($ids) || !array_is_list($ids)) throw new ServiceUnavailable('Agreement selectable models are invalid.');
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,24}$/D', $id)) throw new ServiceUnavailable('Agreement selectable models are invalid.');
        }
        return $ids;
    }

    public static function selectableRateIds(PDO $pdo, string $agreementId): array
    {
        $query = $pdo->prepare('SELECT ai_selectable_model_credit_rate_ids FROM tenant_credit_billing_rate WHERE id = ? AND deleted = FALSE');
        $query->execute([$agreementId]);
        return self::selectableFromColumn($query->fetchColumn() ?: null);
    }

    /** Policy ids the agreement links: main, stream, selectable and native search. */
    public static function linkedPolicyIds(PDO $pdo, string $tenantId, string $agreementId): array
    {
        $query = $pdo->prepare('SELECT ai_model_credit_rate_id, ai_stream_model_credit_rate_id, ai_native_search_model_credit_rate_id,
            ai_selectable_model_credit_rate_ids FROM tenant_credit_billing_rate WHERE id = ? AND tenant_id = ? AND deleted = FALSE');
        $query->execute([$agreementId, $tenantId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];
        $rateIds = array_values(array_filter([$row['ai_model_credit_rate_id'], $row['ai_stream_model_credit_rate_id'],
            $row['ai_native_search_model_credit_rate_id'], ...self::selectableFromColumn($row['ai_selectable_model_credit_rate_ids'])]));
        if (!$rateIds) return [];
        $query = $pdo->prepare('SELECT policy_id FROM ai_model_credit_rate WHERE id IN (' .
            implode(',', array_fill(0, count($rateIds), '?')) . ') AND deleted = FALSE');
        $query->execute($rateIds);
        return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    /** A signed admission or request authorization may only use a model its agreement prices. */
    public static function requireLinked(PDO $pdo, string $tenantId, string $agreementId, string $policyId): void
    {
        if (!in_array($policyId, self::linkedPolicyIds($pdo, $tenantId, $agreementId), true)) {
            throw Conflict::createWithBody('The tenant agreement does not price this model.', '{"reason":"agreement_required"}');
        }
    }

    /** "provider/model": the provider is the first segment, the model may contain slashes. */
    public static function split(?string $value): ?array
    {
        if (!is_string($value) || strlen($value) > 200 ||
            !preg_match('~\A([a-zA-Z0-9][a-zA-Z0-9._:-]{0,63})/([a-zA-Z0-9][a-zA-Z0-9._:/-]{0,127})\z~', $value, $match)) {
            return null;
        }
        return ['provider' => $match[1], 'model' => $match[2]];
    }

    /**
     * The tenant's pickable models from its single active agreement, or null for a
     * legacy-billed tenant or one without exactly one active agreement. The
     * default is the stream-agent model when the agreement has one, else the main one.
     */
    public function forTenant(string $tenantId): ?array
    {
        $pdo = $this->entityManager->getPDO();
        $now = $this->clock->now();
        $query = $pdo->prepare('SELECT cutover_at, deleted FROM tenant_credit_cutover WHERE tenant_id = ?');
        $query->execute([$tenantId]);
        $cutover = $query->fetch(PDO::FETCH_ASSOC);
        if (!$cutover || $cutover['deleted'] || $cutover['cutover_at'] > $now) return null;
        $query = $pdo->prepare('SELECT id, ai_model_credit_rate_id, ai_stream_model_credit_rate_id, ai_selectable_model_credit_rate_ids FROM tenant_credit_billing_rate
            WHERE tenant_id = ? AND deleted = FALSE AND effective_from <= ? AND (effective_until IS NULL OR effective_until > ?)');
        $query->execute([$tenantId, $now, $now]);
        $agreements = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($agreements) !== 1 || !$agreements[0]['ai_model_credit_rate_id']) return null;
        $agreement = $agreements[0];
        $defaultId = $agreement['ai_stream_model_credit_rate_id'] ?: $agreement['ai_model_credit_rate_id'];
        $ids = array_values(array_unique(array_filter([$defaultId, $agreement['ai_model_credit_rate_id'],
            $agreement['ai_stream_model_credit_rate_id'], ...self::selectableFromColumn($agreement['ai_selectable_model_credit_rate_ids'])])));
        $query = $pdo->prepare('SELECT policy_id, display_name, image_input FROM ai_model_credit_rate WHERE id = ? AND deleted = FALSE');
        $models = [];
        foreach ($ids as $id) {
            $query->execute([$id]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            $models[] = self::entry($this->catalog->resolve($row['policy_id']), $row, $id === $defaultId);
        }
        if (!$models || !$models[0]['isDefault']) return null;
        return ['default' => $models[0], 'models' => $models];
    }

    /** Empty means the agreement default; anything else must be priced by the active agreement. */
    public function allows(string $tenantId, ?string $value): bool
    {
        if ($value === null || $value === '') return true;
        $wanted = self::split($value);
        if (!$wanted) return false;
        foreach ($this->forTenant($tenantId)['models'] ?? [] as $model) {
            if ($model['provider'] === $wanted['provider'] && $model['model'] === $wanted['model']) return true;
        }
        return false;
    }

    private static function entry(AiModelPolicy $policy, array $row, bool $isDefault): array
    {
        return [
            'id' => $policy->rate->provider . '/' . $policy->rate->model,
            'provider' => $policy->rate->provider,
            'model' => $policy->rate->model,
            'displayName' => is_string($row['display_name']) && $row['display_name'] !== '' ? $row['display_name'] : $policy->rate->model,
            'multiplier' => (string) $policy->rate->multiplier,
            'holdCredits' => $policy->request('validation', 'validation', 'validation', 'validation')->credits,
            'imageInput' => (bool) $row['image_input'],
            'isDefault' => $isDefault,
        ];
    }
}
