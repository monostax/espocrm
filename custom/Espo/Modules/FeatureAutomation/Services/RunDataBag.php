<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureAutomation\Entities\AutomationRunItem;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Opt-in run-level data bag shared across batch stages.
 *
 * Writers:
 *  - stage.exportToRunBag after a stage's items are terminal
 *  - exportToRunBag action (mid-stage / explicit)
 *
 * Readers:
 *  - stage.importRunBag when materializing the next stage's items
 *
 * Default isolation is preserved; bag is never filled or seeded unless configured.
 */
class RunDataBag
{
    public const MAX_JSON_BYTES = 524288; // 512 KiB

    public const MAX_KEYS = 64;

    public function __construct(
        private EntityManager $entityManager,
        private PayloadBag $payloadBag,
    ) {}

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public function normalize(mixed $raw): array
    {
        return $this->payloadBag->normalize($raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(Entity $run): array
    {
        return $this->normalize($run->get('dataBag'));
    }

    /**
     * Persist bag on AutomationRun.
     *
     * @param array<string, mixed> $bag
     */
    public function write(Entity $run, array $bag, bool $persist = true): void
    {
        $bag = $this->stripReservedRoots($bag);
        $this->assertSize($bag);
        $run->set('dataBag', $bag);
        if ($persist && $run->hasId() && $run->getId() && !str_starts_with((string) $run->getId(), 'sim_')) {
            $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);
        }
    }

    /**
     * Merge or replace selected public keys from source payload into run bag.
     *
     * @param array<string, mixed> $sourcePayload
     * @param list<string>|true|null $keys true/null = all public roots
     * @return array<string, mixed> updated bag
     */
    public function exportFromPayload(
        Entity $run,
        array $sourcePayload,
        array|bool|null $keys = true,
        string $mode = 'merge',
        bool $persist = true,
    ): array {
        $slice = $this->pickPublic($sourcePayload, $keys);
        if ($slice === []) {
            return $this->read($run);
        }

        $mode = $mode === 'replace' ? 'replace' : 'merge';
        $current = $this->read($run);

        if ($mode === 'replace' && ($keys === true || $keys === null)) {
            $next = $slice;
        } elseif ($mode === 'replace') {
            $next = $current;
            foreach ($slice as $k => $v) {
                $next[$k] = $v;
            }
        } else {
            $next = array_replace_recursive($current, $slice);
        }

        $this->write($run, $next, $persist);

        return $next;
    }

    /**
     * Seed item payload from run bag.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $bag
     * @param list<string>|true $keys
     * @return array<string, mixed>
     */
    public function seedPayload(
        array $payload,
        array $bag,
        array|bool $keys = true,
        string $into = 'root',
        bool $overwrite = false,
    ): array {
        $slice = $this->pickPublic($bag, $keys);
        // Always expose a reserved snapshot (read-only for formulas via object\get).
        $payload['_runBag'] = $slice;

        if ($into === 'runBag' || $into === 'bag') {
            return $payload;
        }

        // into=root (default): flatten public keys so existing formulas keep working.
        foreach ($slice as $k => $v) {
            if (!$overwrite && array_key_exists($k, $payload)) {
                continue;
            }
            $payload[$k] = $v;
        }

        return $payload;
    }

    /**
     * Normalize stage.exportToRunBag → structured config or null (disabled).
     *
     * @param mixed $raw
     * @return array{keys: list<string>|true, from: string, mode: string}|null
     */
    public function normalizeExportConfig(mixed $raw): ?array
    {
        if ($raw === null || $raw === false || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }

        $keys = true;
        $from = 'lastDone';
        $mode = 'merge';

        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
            // defaults
        } elseif (is_string($raw)) {
            $parsed = $this->parseKeysString($raw);
            if ($parsed === []) {
                return null;
            }
            $keys = $parsed;
        } elseif (is_array($raw) && array_is_list($raw)) {
            $keys = $this->normalizeKeyList($raw);
            if ($keys === []) {
                return null;
            }
        } elseif ($raw instanceof stdClass || is_array($raw)) {
            $cfg = $this->payloadBag->normalize($raw);
            if (array_key_exists('enabled', $cfg) && !$cfg['enabled']) {
                return null;
            }
            if (array_key_exists('keys', $cfg)) {
                $k = $cfg['keys'];
                if ($k === true || $k === '*' || $k === 'all') {
                    $keys = true;
                } elseif (is_string($k)) {
                    $keys = $this->parseKeysString($k);
                    if ($keys === []) {
                        $keys = true;
                    }
                } elseif (is_array($k)) {
                    $keys = $this->normalizeKeyList($k);
                    if ($keys === []) {
                        $keys = true;
                    }
                }
            }
            $fromRaw = strtolower(trim((string) ($cfg['from'] ?? 'lastDone')));
            $from = match ($fromRaw) {
                'first', 'firstdone', 'first_done' => 'firstDone',
                'once', 'only' => 'lastDone',
                default => 'lastDone',
            };
            $mode = strtolower(trim((string) ($cfg['mode'] ?? 'merge'))) === 'replace'
                ? 'replace'
                : 'merge';
        } else {
            return null;
        }

        if ($keys !== true && $keys === []) {
            return null;
        }

        return [
            'keys' => $keys,
            'from' => $from,
            'mode' => $mode,
        ];
    }

    /**
     * Normalize stage.importRunBag → structured config or null (disabled).
     *
     * @param mixed $raw
     * @return array{keys: list<string>|true, into: string, overwrite: bool}|null
     */
    public function normalizeImportConfig(mixed $raw): ?array
    {
        if ($raw === null || $raw === false || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }

        $keys = true;
        $into = 'root';
        $overwrite = false;

        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
            // defaults
        } elseif (is_string($raw)) {
            $parsed = $this->parseKeysString($raw);
            if ($parsed === []) {
                return null;
            }
            $keys = $parsed;
        } elseif (is_array($raw) && array_is_list($raw)) {
            $keys = $this->normalizeKeyList($raw);
            if ($keys === []) {
                return null;
            }
        } elseif ($raw instanceof stdClass || is_array($raw)) {
            $cfg = $this->payloadBag->normalize($raw);
            if (array_key_exists('enabled', $cfg) && !$cfg['enabled']) {
                return null;
            }
            if (array_key_exists('keys', $cfg)) {
                $k = $cfg['keys'];
                if ($k === true || $k === '*' || $k === 'all') {
                    $keys = true;
                } elseif (is_string($k)) {
                    $keys = $this->parseKeysString($k);
                    if ($keys === []) {
                        $keys = true;
                    }
                } elseif (is_array($k)) {
                    $keys = $this->normalizeKeyList($k);
                    if ($keys === []) {
                        $keys = true;
                    }
                }
            }
            $intoRaw = strtolower(trim((string) ($cfg['into'] ?? 'root')));
            $into = in_array($intoRaw, ['runbag', 'bag', '_runbag'], true) ? 'runBag' : 'root';
            $overwrite = (bool) ($cfg['overwrite'] ?? false);
        } else {
            return null;
        }

        if ($keys !== true && $keys === []) {
            return null;
        }

        return [
            'keys' => $keys,
            'into' => $into,
            'overwrite' => $overwrite,
        ];
    }

    /**
     * After a stage finishes: export selected payload keys into run.dataBag.
     *
     * @param array{keys: list<string>|true, from: string, mode: string} $config
     */
    public function exportStageToRun(Entity $run, string $stageId, array $config): void
    {
        $payload = $this->selectStagePayload($run, $stageId, $config['from']);
        if ($payload === null) {
            return;
        }

        $this->exportFromPayload(
            $run,
            $payload,
            $config['keys'],
            $config['mode'],
            true,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectStagePayload(Entity $run, string $stageId, string $from): ?array
    {
        $order = $from === 'firstDone' ? 'ASC' : 'DESC';

        $item = $this->entityManager
            ->getRDBRepository(AutomationRunItem::ENTITY_TYPE)
            ->where([
                'runId' => $run->getId(),
                'stageId' => $stageId,
                'status' => AutomationRunItem::STATUS_DONE,
                'deleted' => false,
            ])
            ->order('finishedAt', $order)
            ->order('id', $order)
            ->findOne();

        if (!$item) {
            return null;
        }

        return $this->normalize($item->get('payload'));
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|true|null $keys
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $payload
     * @param list<string>|bool|null $keys
     * @return array<string, mixed>
     */
    public function pickPublic(array $payload, array|bool|null $keys = true): array
    {
        $public = [];
        foreach ($payload as $k => $v) {
            if (!is_string($k) || $k === '' || str_starts_with($k, '_')) {
                continue;
            }
            $public[$k] = $v;
        }

        if ($keys === true || $keys === null) {
            return $public;
        }
        if ($keys === false) {
            return [];
        }

        $out = [];
        foreach ($keys as $k) {
            if (!is_string($k) || $k === '' || str_starts_with($k, '_')) {
                continue;
            }
            if (array_key_exists($k, $public)) {
                $out[$k] = $public[$k];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $bag
     * @return array<string, mixed>
     */
    private function stripReservedRoots(array $bag): array
    {
        $out = [];
        foreach ($bag as $k => $v) {
            if (!is_string($k) || $k === '' || str_starts_with($k, '_')) {
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @param list<mixed> $raw
     * @return list<string>
     */
    private function normalizeKeyList(array $raw): array
    {
        $out = [];
        foreach ($raw as $k) {
            if (!is_string($k) && !is_int($k)) {
                continue;
            }
            $s = trim((string) $k);
            if ($s === '' || str_starts_with($s, '_')) {
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $s)) {
                throw new Error("Invalid run bag key '{$s}'.");
            }
            $out[$s] = true;
            if (count($out) >= self::MAX_KEYS) {
                break;
            }
        }

        return array_keys($out);
    }

    /**
     * @return list<string>
     */
    private function parseKeysString(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        return $this->normalizeKeyList($parts);
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function assertSize(array $bag): void
    {
        $json = json_encode($bag);
        if ($json === false) {
            throw new Error('Run data bag is not JSON-serializable.');
        }
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new Error(
                'Run data bag exceeds size limit (' . self::MAX_JSON_BYTES . ' bytes). Export fewer keys or smaller reports.'
            );
        }
        if (count($bag) > self::MAX_KEYS) {
            throw new Error('Run data bag exceeds key limit (' . self::MAX_KEYS . ').');
        }
    }
}
