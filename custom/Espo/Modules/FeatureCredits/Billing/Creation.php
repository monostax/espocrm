<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Modules\FeatureCredits\Billing\Asaas\Adapter;

/** Internal orchestration: never retry creation after a committed claim, even if delivery is uncertain. */
final class Creation
{
    public function __construct(private CreationStore $store, private Adapter $adapter) {}

    public function customer(string $tenant, string $obligation, #[\SensitiveParameter] array $profile): array
    {
        return $this->execute($tenant, $this->store->customer($tenant, $obligation, $profile));
    }

    public function charge(string $tenant, string $obligation, string $customerCreation, string $billingType, string $dueDate): array
    {
        return $this->execute($tenant, $this->store->charge($tenant, $obligation, $customerCreation, $billingType, $dueDate));
    }

    private function execute(string $tenant, array $claim): array
    {
        if (!$claim['dispatchAllowed']) return $claim['receipt'];
        // Transport failures are ambiguous. Storage errors propagate, retaining the original claim for lookup.
        $result = $this->adapter->create($claim['connection'], $claim['command']);
        return $this->store->record($tenant, $claim['receipt']['creationId'], $result);
    }

    public function reconcile(string $tenant, string $creation): array
    {
        $claim = $this->store->recovery($tenant, $creation);
        if (!$claim['lookupAllowed']) return $claim['receipt'];
        return $this->store->record($tenant, $creation, $this->adapter->reconcile($claim['connection'], $claim['command']));
    }
}
