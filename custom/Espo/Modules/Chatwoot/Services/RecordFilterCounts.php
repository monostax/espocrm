<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Modules\FeatureInitiative\Services\Inbox as InitiativeInbox;

/**
 * Sidebar badges of the saved filters (2026-11-24): for each filter this person sees in the workspace, how many records
 * it holds and how many of those are unread, scoped like the list it opens (workspace, their own access, the
 * Todas/Minhas/Não atribuídas pill).
 *
 * A saved filter stores the filter panel's conditions. Chatwoot compiles them with the list's own where builder and
 * posts the result per filter, so a badge and its list cannot drift apart. Counts are computed when asked, never kept as
 * live queries: the sidebar asks again when that list's own counts change.
 */
class RecordFilterCounts
{
    private const SERVICES = [
        'Contact' => ContactInbox::class,
        'Account' => AccountInbox::class,
        'Initiative' => InitiativeInbox::class,
        'Opportunity' => OpportunityReadStateService::class,
    ];

    public function __construct(
        private RecordFilters $filters,
        private OpportunityBulkPostAccess $workspaces,
        private InjectableFactory $factory,
    ) {}

    /** @return object{counts: object} Per filter id, {all, unread}, or null when that filter can't be counted. */
    public function counts(int $accountId, object $data): object
    {
        $assignee = $data->assigneeTab ?? 'all';
        if (!in_array($assignee, ['all', 'me', 'unassigned'], true) || !is_array($data->filters ?? null)) {
            throw new BadRequest('Invalid saved filter counts request.');
        }
        $workspace = $this->workspaces->workspace($accountId);
        $types = [];
        foreach ($this->filters->visible($workspace) as $filter) $types[$filter->getId()] = $filter->get('entityType');
        $services = [];
        $counts = [];
        foreach ($data->filters as $item) {
            $id = is_object($item) && is_string($item->id ?? null) ? $item->id : null;
            $type = $id !== null ? ($types[$id] ?? null) : null;
            // Only this person's visible filters, each once: the request can't ask for more counts than they have filters.
            if ($type === null || array_key_exists($id, $counts) || !is_array($item->where ?? null)) continue;
            $where = json_decode((string) json_encode($item->where), true);
            $services[$type] ??= $this->factory->create(self::SERVICES[$type]);
            try {
                $counts[$id] = $services[$type]->filterCounts($workspace, $where, $assignee);
            } catch (BadRequest | Forbidden) {
                // A condition this person may not use, or one no longer valid: no badge here, the others still count.
                $counts[$id] = null;
            }
        }
        return (object) ['counts' => (object) $counts];
    }
}
