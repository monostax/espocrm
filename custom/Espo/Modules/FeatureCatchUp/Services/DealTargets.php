<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Where a deal found in a conversation (`new_deal`) can go: the workspace's active funnels the reader may read, each
 * with its active open stages (probability 1–99) and its won stage (100). A lost stage (0) is never offered: a deal
 * Em dia found is never created lost. The analysis gets this list (prompt version 13) and proposes a funnel and a
 * stage; the card shows them and creating the deal checks them again here, as the reader.
 */
class DealTargets
{
    /** Funnels the analysis sees; the backend keeps them within its own byte budget. */
    public const MAX_FUNNELS = 12;
    public const MAX_STAGES = 20;

    /** @var array<string, list<Entity>> funnels by tenant, for this request */
    private array $funnels = [];
    /** @var array<string, list<Entity>> stages by funnel, for this request */
    private array $stages = [];

    public function __construct(private EntityManager $em, private SelectBuilderFactory $select) {}

    /**
     * The funnels for the analysis, default first: `{id, name, description, stages: [{id, name, kind, description}]}`.
     * Funnels with no stage a deal may start in are left out.
     *
     * @return list<array{id: string, name: string, description: ?string, stages: list<array{id: string, name: string, kind: string, description: ?string}>}>
     */
    public function forPrompt(string $tenantId): array
    {
        $result = [];
        foreach (array_slice($this->funnels($tenantId), 0, self::MAX_FUNNELS) as $funnel) {
            $stages = [];
            foreach (array_slice($this->stages((string) $funnel->getId()), 0, self::MAX_STAGES) as $stage) {
                $stages[] = [
                    'id' => (string) $stage->getId(), 'name' => self::cut($stage->get('name'), 80) ?? '',
                    'kind' => self::kind($stage), 'description' => self::cut($stage->get('description'), 300),
                ];
            }
            if (!$stages) continue;
            $result[] = [
                'id' => (string) $funnel->getId(), 'name' => self::cut($funnel->get('name'), 80) ?? '',
                'description' => self::cut($funnel->get('description'), 500), 'stages' => $stages,
            ];
        }
        return $result;
    }

    /**
     * A proposed deal as stored on the card: its funnel and stage only when they are one of the funnels the analysis
     * was given (the stage in that funnel); otherwise null, and the card falls back to the default. An answer from a
     * backend before version 13 has neither key and is kept as is, so the card stays outdated (Verdicts::outdated) and
     * is analysed again once the backend proposes funnels: the CRM can be deployed first.
     *
     * @param array<string, mixed> $deal
     * @return array<string, mixed>
     */
    public function sanitize(string $tenantId, array $deal): array
    {
        if (!array_key_exists('funnelId', $deal) && !array_key_exists('stageId', $deal)) return $deal;
        $funnelId = is_string($deal['funnelId'] ?? null) ? $deal['funnelId'] : null;
        $stageId = is_string($deal['stageId'] ?? null) ? $deal['stageId'] : null;
        $valid = $funnelId !== null && $stageId !== null && $this->pick($tenantId, $funnelId, $stageId) !== null;
        return [...$deal, 'funnelId' => $valid ? $funnelId : null, 'stageId' => $valid ? $stageId : null];
    }

    /**
     * The funnel and stage a deal is created in: the chosen pair when the reader may use it, else (nothing chosen) the
     * default funnel, or the only one, and its first open stage.
     *
     * @return array{string, string} funnel id, stage id
     * @throws BadRequest `invalidStage` for a pair the reader can't use, `funnelRequired` / `noFunnel` without one
     */
    public function resolve(string $tenantId, ?string $funnelId, ?string $stageId): array
    {
        if ($funnelId !== null || $stageId !== null) {
            $pair = $funnelId !== null && $stageId !== null ? $this->pick($tenantId, $funnelId, $stageId) : null;
            if (!$pair) throw new BadRequest('invalidStage');
            return $pair;
        }
        $funnels = $this->funnels($tenantId);
        $funnel = null;
        foreach ($funnels as $candidate) if (!$funnel && $candidate->get('isDefault')) $funnel = $candidate;
        if (!$funnel && count($funnels) === 1) $funnel = $funnels[0];
        if (!$funnel) throw new BadRequest($funnels ? 'funnelRequired' : 'noFunnel');
        $open = array_values(array_filter($this->stages((string) $funnel->getId()), fn (Entity $stage) => self::kind($stage) === 'open'))[0] ?? null;
        if (!$open) throw new BadRequest('noStage');
        return [(string) $funnel->getId(), (string) $open->getId()];
    }

    /** @return ?array{string, string} */
    private function pick(string $tenantId, string $funnelId, string $stageId): ?array
    {
        foreach ($this->funnels($tenantId) as $funnel) {
            if ($funnel->getId() !== $funnelId) continue;
            foreach ($this->stages($funnelId) as $stage) if ($stage->getId() === $stageId) return [$funnelId, $stageId];
        }
        return null;
    }

    /** @return list<Entity> the workspace's active funnels the reader may read, default first, then by name */
    public function funnels(string $tenantId): array
    {
        if (isset($this->funnels[$tenantId])) return $this->funnels[$tenantId];
        $query = $this->select->create()->from('Funnel')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['tenantId' => $tenantId, 'isActive' => true])
            ->order('isDefault', 'DESC')
            ->order('name')
            ->build();
        return $this->funnels[$tenantId] = iterator_to_array($this->em->getRDBRepository('Funnel')->clone($query)->find(), false);
    }

    /** @return list<Entity> a funnel's active stages a deal may start in (open or won), in order */
    protected function stages(string $funnelId): array
    {
        if (isset($this->stages[$funnelId])) return $this->stages[$funnelId];
        $stages = $this->em->getRDBRepository('OpportunityStage')
            ->where(['funnelId' => $funnelId, 'isActive' => true, 'probability>' => 0])
            ->order('order')
            ->find();
        return $this->stages[$funnelId] = iterator_to_array($stages, false);
    }

    /** A stage at 100% wins the deal (SyncFromOpportunityStage); anything above 0 keeps it open. */
    private static function kind(Entity $stage): string
    {
        return (int) $stage->get('probability') >= 100 ? 'won' : 'open';
    }

    private static function cut(mixed $value, int $max): ?string
    {
        if (!is_string($value)) return null;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
