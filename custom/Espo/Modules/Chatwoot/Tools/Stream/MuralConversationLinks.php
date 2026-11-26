<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\ORM\Query\SelectBuilder;

/** One set of relationship paths for event delivery and viewer-specific reads. */
final class MuralConversationLinks
{
    /** A Task's own conversation link, as opposed to an entity scope reached through a relation. */
    public const TASK_CONVERSATIONS = 'chatwootConversations';
    /**
     * Parents without a tenant column (task rows are tenant-scoped through ACL only). Their links
     * bind to the tenant of the conversation's workspace instead of the parent's.
     */
    private const TENANTLESS = ['Task'];

    /** @return array<string, SelectBuilder> Each branch projects the parent id. */
    public static function paths(string $type): array
    {
        $links = [
            'manual' => self::manual($type, false),
            'manualReverse' => self::manual($type, true),
        ];
        if ($type === 'Account') {
            $links['Contact'] = self::parent($type)->join('contacts', 'muralContact')
                ->join('ChatwootConversation', 'muralConversation', [
                    'muralConversation.contactId:' => 'muralContact.id', 'muralConversation.deleted' => false,
                ])->where(['muralContact.tenantId:' => 'muralParent.tenantId']);
        } elseif ($type === 'Task') {
            // The conversations attached to the task (2026-11-26); the relation join skips deleted rows.
            $links[self::TASK_CONVERSATIONS] = self::parent($type)->join('chatwootConversations', 'muralConversation');
        } elseif ($type === 'Initiative') {
            $links['InitiativeRelation'] = self::parent($type)->join('relations', 'muralLink')
                ->join('ChatwootConversation', 'muralConversation', [
                    'muralConversation.id:' => 'muralLink.parentId', 'muralConversation.deleted' => false,
                ])->where(['muralLink.parentType' => 'ChatwootConversation', 'muralLink.tenantId:' => 'muralParent.tenantId']);
            foreach (['Task', 'Meeting', 'Call'] as $activity) {
                $links[$activity] = SelectBuilder::create()->from('ChatwootConversation', 'muralConversation')
                    ->join(strtolower($activity) . 's', 'muralActivity')
                    ->join('Initiative', 'muralParent', [
                        'muralParent.id:' => 'muralActivity.parentId', 'muralParent.deleted' => false,
                    ])->where(['muralActivity.parentType' => 'Initiative'])->select('muralParent.id', 'id');
                if ($activity === 'Call') $links[$activity]->where(['OR' => [
                    ['muralActivity.tenantId' => null], ['muralActivity.tenantId:' => 'muralParent.tenantId'],
                ]]);
            }
        }
        foreach ($links as $link) {
            $link->join('ChatwootAccount', 'muralWorkspace', [
                'muralWorkspace.id:' => 'muralConversation.chatwootAccountId', 'muralWorkspace.deleted' => false,
            ] + (self::tenanted($type) ? ['muralWorkspace.tenantId:' => 'muralParent.tenantId'] : []));
        }
        return $links;
    }

    private static function manual(string $type, bool $reverse): SelectBuilder
    {
        [$parent, $conversation] = $reverse ? ['object', 'subject'] : ['subject', 'object'];
        // Knowledge claims with document evidence have additional document ACLs. They
        // are not the manual conversation links created by the relationship panel.
        $tenanted = self::tenanted($type);
        $query = self::parent($type)->join('RecordRelation', 'muralLink', [
            "muralLink.{$parent}Type" => $type, "muralLink.{$parent}Id:" => 'muralParent.id',
            "muralLink.{$conversation}Type" => 'ChatwootConversation',
            'muralLink.status' => 'confirmed', 'muralLink.origin' => 'manual', 'muralLink.deleted' => false,
            'muralLink.sourceDocumentId' => null, 'muralLink.sourceRevisionId' => null,
        ] + ($tenanted ? ['muralLink.tenantId:' => 'muralParent.tenantId'] : []))->join('ChatwootConversation', 'muralConversation', [
            "muralConversation.id:" => "muralLink.{$conversation}Id", 'muralConversation.deleted' => false,
        ]);
        // The workspace is joined after this; a WHERE may refer to it, an ON may not.
        return $tenanted ? $query : $query->where(['muralLink.tenantId:' => 'muralWorkspace.tenantId']);
    }

    /** Whether the parent row carries the tenant. */
    public static function tenanted(string $type): bool
    {
        return !in_array($type, self::TENANTLESS, true);
    }

    private static function parent(string $type): SelectBuilder
    {
        return SelectBuilder::create()->from($type, 'muralParent')->select('id');
    }
}
