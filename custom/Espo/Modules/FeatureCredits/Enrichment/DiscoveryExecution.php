<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\{Conflict,Forbidden,NotFound,ServiceUnavailable};
use Espo\Modules\FeatureCredits\Accounting\Amount;

/** Bounded synchronous traversal; the durable database, never the job delivery, owns supplier permission. */
final class DiscoveryExecution
{
    public function __construct(private DiscoveryCalls $calls,private DiscoverySuppliers $suppliers,private Purchases $purchases) {}

    public function run(string $tenant,string $quote): array
    {
        $this->calls->expire($tenant,$quote);
        try {
            $snapshot = $this->calls->snapshot($tenant,$quote);
            if ($snapshot['state'] === 'settled') return $this->purchases->close($tenant,$quote);
            if ($snapshot['expired']) return $this->purchases->close($tenant,$quote,'deadline');
            $terms = $snapshot['terms']; $product = $terms['request']['products'][0]; $goal = $terms['request']['count'] ?? 1;
            $deferred = []; $reason = 'route_exhausted';
            foreach ($terms['routes'][$product] as $route) {
                $cursor = null; $pageLimit = $product === 'cnpj' ? 1 : $terms['discovery']['limits']['pagesPerProvider'];
                for ($page = 1; $page <= $pageLimit; $page++) {
                    $snapshot = $this->calls->snapshot($tenant,$quote);
                    if ($snapshot['state'] === 'settled') return $this->purchases->close($tenant,$quote);
                    if ($snapshot['expired']) return $this->purchases->close($tenant,$quote,'deadline');
                    if ($product !== 'socios' && $snapshot['candidates'] >= $goal) return $this->purchases->close($tenant,$quote,'complete');
                    if (($snapshot['providerCounts'][$route['provider']] ?? 0) >= $goal) break;
                    if (Amount::fromString($snapshot['remaining'])->compareTo(Amount::fromString($route['credits'])) < 0) { $reason = 'budget'; break; }
                    // A saved page is read/reused here before any possibility of supplier dispatch.
                    $claim = $this->calls->page($tenant,$quote,$route['provider'],$page,$cursor);
                    $outcome = $this->execute($tenant,$quote,$claim);
                    if ($outcome['state'] === 'pending') return ['quoteId' => $quote,'state' => 'pending'];
                    if ($outcome['state'] === 'waived') return $this->purchases->close($tenant,$quote,'ambiguous');
                    if ($outcome['state'] === 'stopped') { $reason = $outcome['reason']; break; }
                    if ($outcome['state'] === 'failed') { $reason = 'provider_failed'; break; }
                    $payload = $this->calls->result($tenant,$quote,$claim['id']);
                    if ($payload === null) { $reason = 'continuation_expired'; break; }
                    foreach ($payload['items'] as $index => $item) {
                        $snapshot = $this->calls->snapshot($tenant,$quote);
                        if ($snapshot['state'] === 'settled') return $this->purchases->close($tenant,$quote);
                        if ($snapshot['expired']) return $this->purchases->close($tenant,$quote,'deadline');
                        if ($product !== 'socios' && $snapshot['candidates'] >= $goal) break;
                        if (isset($item['completionToken'])) {
                            $completion = $this->calls->completion($tenant,$quote,$claim['id'],$index);
                            $completed = $this->execute($tenant,$quote,$completion);
                            if ($completed['state'] === 'pending') return ['quoteId' => $quote,'state' => 'pending'];
                            if ($completed['state'] === 'waived') return $this->purchases->close($tenant,$quote,'ambiguous');
                            if ($completed['state'] === 'stopped') { $reason = $completed['reason']; continue; }
                            if ($completed['state'] === 'failed') { $reason = 'provider_failed'; continue; }
                            $resolved = $this->calls->result($tenant,$quote,$completion['id']);
                            $item = $resolved['items'][0] ?? null;
                            if ($item === null) continue;
                        }
                        try {
                            $this->purchases->purchaseCached($tenant,$quote,$item['cacheId'],true);
                        } catch (PurchaseDeferred $e) {
                            if ($e->reason === 'supplement_priority') $deferred[$item['cacheId']] = true;
                            if ($e->reason === 'budget') $reason = 'budget';
                            // Excess/new-but-over-count, already-selected-only and provider caps never authorize another debit.
                        }
                    }
                    $cursor = $payload['nextCursor'];
                    if ($cursor === null) break;
                    if ($page === $pageLimit) $reason = 'page_limit';
                }
            }
            // Only now can earlier useful additions spend the budget left after the distinct-person traversal.
            foreach (array_keys($deferred) as $cacheId) {
                try { $this->purchases->purchaseCached($tenant,$quote,$cacheId); }
                catch (PurchaseDeferred $e) { if ($e->reason === 'budget') $reason = 'budget'; }
            }
            $snapshot = $this->calls->snapshot($tenant,$quote);
            if ($snapshot['expired']) $reason = 'deadline';
            elseif ($snapshot['candidates'] >= $goal && $reason === 'route_exhausted') $reason = 'complete';
            return $this->purchases->close($tenant,$quote,$reason);
        } catch (ServiceUnavailable) {
            return $this->purchases->close($tenant,$quote,'unavailable');
        } catch (Forbidden|Conflict|NotFound) {
            // Revoke execution, never impersonate the daemon to bypass changed purchaser/identity access.
            // Financial/database failures are not swallowed; a later job can recover the original work.
            return $this->purchases->close($tenant,$quote,'authorization_changed');
        }
    }

    /** Scheduled no-I/O recovery; authenticated actor revocation must not strand a hold. */
    public function recover(string $tenant,string $quote): void
    {
        $this->calls->expire($tenant,$quote);
        try {
            $snapshot = $this->calls->snapshot($tenant,$quote);
            if ($snapshot['expired'] && $snapshot['state'] !== 'settled') $this->purchases->close($tenant,$quote,'deadline');
        } catch (Forbidden|Conflict|NotFound) {
            $this->purchases->close($tenant,$quote,'authorization_changed');
        }
    }

    private function execute(string $tenant,string $quote,array $claim): array
    {
        if (!isset($claim['token'])) {
            return in_array($claim['state'],['claimed','waiting'],true) ? ['state' => 'pending'] : $claim;
        }
        $request = $this->calls->dispatch($tenant,$quote,$claim['id'],$claim['token']);
        if ($request === null) return ['state' => 'pending'];
        try { $result = $this->suppliers->get($request['provider'])->execute($request,$request['timeoutMs']); }
        catch (\Throwable) { $result = new DiscoveryResult('unknown'); }
        // A crash/DB error here leaves the one-shot claim pending; it never reissues the paid call.
        return $this->calls->record($tenant,$quote,$claim['id'],$claim['token'],$result);
    }
}
