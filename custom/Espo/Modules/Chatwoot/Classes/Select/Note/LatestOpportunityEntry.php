<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Note;

use Espo\Core\Select\Primary\Filter;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;

/** One preview per opportunity; a busy conversation must not starve other cards. */
class LatestOpportunityEntry implements Filter
{
    public function __construct(private User $user, private OpportunityEventAccess $access) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $parents = SelectBuilder::create()->clone($queryBuilder->build())
            ->select(['parentId'])->order([])->limit(null, null)->build();
        $queryBuilder->join($this->latestNumbers($parents), 'latestOpportunityEntry',
            Expr::equal(Expr::alias('latestOpportunityEntry.lastNumber'), Expr::column('number')));
        $queryBuilder->where(['parentType' => 'Opportunity']);
    }

    /** Evaluate event visibility once per note set, rather than once per parent row. */
    public function latestNumbers(?Select $opportunities = null): Select
    {
        $query = SelectBuilder::create()->from('Note', 'latestEntry')
            ->select(['parentId', ['MAX:number', 'lastNumber']])->where([
                'parentType' => 'Opportunity',
                'type' => OpportunityStreamEvents::TYPES,
            ])->where($this->access->where($this->user))->group('parentId');
        if ($opportunities) {
            $query->where(['parentId=s' => $opportunities]);
        }
        return $query->build();
    }

    /**
     * Latest message either way (private notes included, system activity excluded)
     * in each opportunity's conversations the user can read; null when none.
     */
    public function latestMessages(): ?Select
    {
        $conversations = $this->access->conversations($this->user);
        if (!$conversations) {
            return null;
        }
        $sent = Expr::column('latestConversation.lastMessageSentAt');
        $received = Expr::column('latestConversation.lastMessageReceivedAt');
        return SelectBuilder::create()->from('ChatwootConversationOpportunity', 'latestLink')
            ->join('ChatwootConversation', 'latestConversation', Expr::and(
                Expr::equal(Expr::column('latestConversation.id'), Expr::column('latestLink.chatwootConversationId')),
                Expr::equal(Expr::column('latestConversation.deleted'), false),
            ))
            ->join('ChatwootAccount', 'latestAccount', Expr::and(
                Expr::equal(Expr::column('latestAccount.id'), Expr::column('latestConversation.chatwootAccountId')),
                Expr::equal(Expr::column('latestAccount.deleted'), false),
            ))
            ->select('latestLink.opportunityId', 'opportunityId')
            ->select('latestAccount.tenantId', 'tenantId')
            ->select(Expr::max(Expr::greatest(Expr::coalesce($sent, $received), Expr::coalesce($received, $sent))), 'lastMessageAt')
            ->where(['latestLink.chatwootConversationId=s' => $conversations])
            ->group(['latestLink.opportunityId', 'latestAccount.tenantId'])
            ->build();
    }
}
