<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords;

/** Explicit Chatwoot surface coverage. New CRM scopes do not opt in automatically. */
final class Scope
{
    public const RECORD_TYPES = [
        'Account', 'Call', 'Contact', 'Initiative',
        'JourneyRecord', 'Meeting', 'Opportunity', 'Task',
    ];

    // These rows emit only when their reference graph reaches an in-scope record.
    public const DEPENDENCIES = [
        'ContactChannelIdentity', 'EmailAddress', 'PhoneNumber',
        'Note', 'UserReaction', 'ActivityReadState', 'OpportunityReadState',
        'Document', 'DocumentRevision', 'RecordDocument', 'RecordRelation',
        'InitiativeRelation', 'PlaybookRun', 'PlaybookRunStep',
    ];

    // Shared definitions displayed in filters, labels and related-record panels.
    public const SHARED = [
        'CrmTag', 'RecordPredicate', 'Playbook', 'PlaybookStep', 'Funnel',
        'OpportunityStage', 'InitiativeStage', 'InitiativeType', 'Journey', 'JourneyStage',
    ];

    public const ACCESS = [
        'Tenant', 'User', 'Team', 'Role', 'PortalRole', 'ChatwootAccount', 'ChatwootAccountUserMembership',
    ];

    public static function all(): array
    {
        return [...self::RECORD_TYPES, ...self::DEPENDENCIES, ...self::SHARED, ...self::ACCESS];
    }
}
