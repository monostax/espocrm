<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Console\Commands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Modules\Chatwoot\Tools\DuplicateContactMerger;
use Espo\ORM\EntityManager;

/**
 * `php command.php merge-duplicate-contacts [--apply] < pairs.json`
 *
 * Input: [{"survivor": "<contact id>", "loser": "<contact id>"}, ...]. A loser
 * id with no contact row (purged) gets its Chatwoot bridges repaired instead.
 * Dry run by default (every pair runs and is rolled back); --apply commits.
 * Prints ids, statuses and counts only.
 */
final class MergeDuplicateContacts implements Command
{
    public function __construct(private EntityManager $entityManager) {}

    public function run(Params $params, IO $io): void
    {
        $apply = $params->hasFlag('apply');
        $pairs = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($pairs) || !array_is_list($pairs)) {
            $io->writeLine('Expected a JSON list of {"survivor": id, "loser": id} on stdin.');
            $io->setExitStatus(1);
            return;
        }
        $merger = new DuplicateContactMerger($this->entityManager->getPDO());
        $statuses = [];
        $totals = [];
        foreach ($pairs as $pair) {
            $survivor = (string) ($pair['survivor'] ?? '');
            $loser = (string) ($pair['loser'] ?? '');
            $result = $merger->mergeOrRepair($survivor, $loser, $apply);
            $label = $result['status'] . (isset($result['reason']) ? ': ' . $result['reason'] : '');
            $statuses[$label] = ($statuses[$label] ?? 0) + 1;
            foreach ($result['counts'] as $name => $n) $totals[$name] = ($totals[$name] ?? 0) + $n;
            $io->writeLine("$loser -> $survivor: $label");
        }
        $io->writeLine($apply ? 'APPLIED' : 'DRY RUN (nothing written)');
        foreach ($statuses as $label => $n) $io->writeLine("  $n x $label");
        foreach ($totals as $name => $n) $io->writeLine("  $name: $n");
    }
}
