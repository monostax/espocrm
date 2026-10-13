<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\ORM\EntityManager;
use PDO;

/** Ordinary jobs select the executor from the persisted quote, never a caller-supplied product. */
final class ExecutionRouter
{
    public function __construct(private EntityManager $entityManager,private Execution $fields,private DiscoveryExecution $discovery) {}

    public function run(string $tenant,string $quote): void
    {
        if ($this->isDiscovery($tenant,$quote)) $this->discovery->run($tenant,$quote);
        else $this->fields->run($tenant,$quote);
    }

    public function recover(string $tenant,string $quote): void
    {
        if ($this->isDiscovery($tenant,$quote)) $this->discovery->recover($tenant,$quote);
        else $this->fields->claim($tenant,$quote,false);
    }

    private function isDiscovery(string $tenant,string $quote): bool
    {
        GrantInput::identity($tenant); GrantInput::identity($quote);
        $q = $this->entityManager->getPDO()->prepare('SELECT * FROM enrichment_quote WHERE id=? AND tenant_id=?');
        $q->execute([$quote,$tenant]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Unknown enrichment job.');
        return (new QuoteInput((object) Quotes::decode($row)['request']))->isDiscovery();
    }
}
