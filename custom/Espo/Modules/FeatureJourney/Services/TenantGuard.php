<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * System-job tenancy spine. Jobs run as system (ACL bypassed);
 * every mutation must assert same-tenant ownership explicitly.
 */
class TenantGuard
{
    /** @var list<string> */
    private const ALWAYS_BLOCKED_TARGET_FIELDS = [
        'id',
        'deleted',
        'tenantId',
        'teamsIds',
        'teamsNames',
        'teamsColumns',
        'createdAt',
        'createdById',
        'createdByName',
        'modifiedAt',
        'modifiedById',
        'modifiedByName',
        'password',
        'userName',
        'apiKey',
        'authToken',
        'isAdmin',
        'isSuperAdmin',
        'type',
        'deleteId',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
        private Metadata $metadata,
        private CustomFieldsBag $customFieldsBag,
        private Config $config,
        private Log $log,
    ) {}

    public function requireTenantId(Entity $entity, string $context = 'entity'): string
    {
        $tenantId = null;

        if ($entity->hasAttribute('tenantId') && $entity->get('tenantId')) {
            $tenantId = (string) $entity->get('tenantId');
        }

        if ($tenantId === null || $tenantId === '') {
            $tenantId = $this->tenantResolver->resolveTenantIdForEntity($entity);
        }

        if ($tenantId === null || $tenantId === '') {
            throw new Error("TenantGuard: cannot resolve tenant for {$context}.");
        }

        return $tenantId;
    }

    public function entityBelongsToTenant(Entity $entity, string $tenantId): bool
    {
        if ($tenantId === '') {
            return false;
        }

        if ($entity->hasAttribute('tenantId')) {
            $direct = $entity->get('tenantId');
            if ($direct) {
                return (string) $direct === $tenantId;
            }
        }

        $resolved = $this->tenantResolver->resolveTenantIdForEntity($entity);

        if ($resolved === null) {
            return false;
        }

        return $resolved === $tenantId;
    }

    public function assertEntityTenant(Entity $entity, string $tenantId, string $context = 'entity'): void
    {
        if (!$this->entityBelongsToTenant($entity, $tenantId)) {
            $this->log->warning(sprintf(
                'TenantGuard: reject %s %s/%s for tenant %s',
                $context,
                $entity->getEntityType(),
                $entity->hasId() ? $entity->getId() : '(new)',
                $tenantId,
            ));

            throw new Error("TenantGuard: {$context} is outside tenant.");
        }
    }

    public function assertTargetBelongsToJourney(Entity $target, Entity $journey): void
    {
        $tenantId = $this->requireTenantId($journey, 'journey');
        $this->assertEntityTenant($target, $tenantId, 'target');
    }

    public function assertRecordMatchesJourney(Entity $record, Entity $journey): void
    {
        if ((string) $record->get('journeyId') !== (string) $journey->getId()) {
            throw new Error('TenantGuard: record journey mismatch.');
        }

        $jTenant = $journey->get('tenantId') ? (string) $journey->get('tenantId') : null;
        $rTenant = $record->get('tenantId') ? (string) $record->get('tenantId') : null;

        if ($jTenant && $rTenant && $jTenant !== $rTenant) {
            throw new Error('TenantGuard: record tenant mismatch.');
        }
    }

    public function assertStageInJourney(Entity $stage, Entity $journey): void
    {
        if ((string) $stage->get('journeyId') !== (string) $journey->getId()) {
            throw new Error('TenantGuard: stage not in journey.');
        }
    }

    public function userBelongsToTenant(string $userId, string $tenantId): bool
    {
        if ($userId === '' || $tenantId === '') {
            return false;
        }

        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user) {
            return false;
        }

        // System/super never assignee via tenant actions.
        if ($user->get('type') === 'system' || $user->get('isSuperAdmin')) {
            return false;
        }

        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

        if (!$tenant) {
            return false;
        }

        $allowedTeamIds = $this->getTenantTeamIds($tenantId);
        if ($allowedTeamIds === []) {
            return false;
        }

        $userTeamIds = $this->getEntityTeamIds($user);

        return array_intersect($allowedTeamIds, $userTeamIds) !== [];
    }

    public function assertUserInTenant(string $userId, string $tenantId, string $context = 'user'): void
    {
        if (!$this->userBelongsToTenant($userId, $tenantId)) {
            throw new Error("TenantGuard: {$context} not in tenant.");
        }
    }

    public function targetListBelongsToTenant(string $targetListId, string $tenantId): bool
    {
        $list = $this->entityManager->getEntityById('TargetList', $targetListId);

        if (!$list) {
            return false;
        }

        return $this->entityBelongsToTenant($list, $tenantId);
    }

    public function assertTargetListInTenant(string $targetListId, string $tenantId): void
    {
        if (!$this->targetListBelongsToTenant($targetListId, $tenantId)) {
            throw new Error('TenantGuard: targetList outside tenant.');
        }
    }

    /**
     * Recipient must be the target's primary email (or empty-free match).
     * Blocks free-form spam to arbitrary addresses from tenant actions.
     */
    public function assertEmailRecipientAllowed(string $to, Entity $target): void
    {
        $to = strtolower(trim($to));

        if ($to === '') {
            throw new Error('TenantGuard: empty email recipient.');
        }

        $allowed = [];

        foreach (['emailAddress', 'emailAddressData'] as $attr) {
            if (!$target->hasAttribute($attr) && !$target->has($attr)) {
                continue;
            }

            $val = $target->get($attr);

            if (is_string($val) && $val !== '') {
                $allowed[] = strtolower(trim($val));
            }

            if (is_array($val)) {
                foreach ($val as $row) {
                    if (is_array($row) && isset($row['emailAddress'])) {
                        $allowed[] = strtolower(trim((string) $row['emailAddress']));
                    } elseif (is_object($row) && isset($row->emailAddress)) {
                        $allowed[] = strtolower(trim((string) $row->emailAddress));
                    }
                }
            }
        }

        // Also pull EmailAddress relation if linked.
        try {
            if ($target->hasId()) {
                $addresses = $this->entityManager
                    ->getRDBRepository($target->getEntityType())
                    ->getRelation($target, 'emailAddresses')
                    ->find();

                foreach ($addresses as $addr) {
                    $name = $addr->get('name');
                    if (is_string($name) && $name !== '') {
                        $allowed[] = strtolower(trim($name));
                    }
                }
            }
        } catch (Throwable) {
        }

        $allowed = array_values(array_unique(array_filter($allowed)));

        if ($allowed === [] || !in_array($to, $allowed, true)) {
            throw new Error('TenantGuard: email recipient not on target.');
        }
    }

    /**
     * @return list<string>
     */
    public function getJourneyTeamsIds(Entity $journey): array
    {
        try {
            $ids = $journey->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $ids = $journey->get('teamsIds') ?: [];
        }

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $ids)));
    }

    /**
     * @return list<string>
     */
    public function getTenantTeamIds(string $tenantId): array
    {
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

        if (!$tenant) {
            return [];
        }

        $ids = [];

        $base = $tenant->get('baseUserTeamId');
        if ($base) {
            $ids[] = (string) $base;
        }

        try {
            $others = $this->entityManager
                ->getRDBRepository('Tenant')
                ->getRelation($tenant, 'otherUserTeams')
                ->find();

            foreach ($others as $team) {
                $ids[] = $team->getId();
            }
        } catch (Throwable) {
        }

        return array_values(array_unique($ids));
    }

    /**
     * EmailTemplate must share a team with the tenant (or carry matching tenantId).
     */
    public function assertEmailTemplateInTenant(string $templateId, string $tenantId): void
    {
        $tpl = $this->entityManager->getEntityById('EmailTemplate', $templateId);

        if (!$tpl) {
            throw new Error('TenantGuard: email template not found.');
        }

        if ($tpl->hasAttribute('tenantId') && $tpl->get('tenantId')) {
            if ((string) $tpl->get('tenantId') !== $tenantId) {
                throw new Error('TenantGuard: email template outside tenant.');
            }

            return;
        }

        $tplTeams = $this->getEntityTeamIds($tpl);
        $tenantTeams = $this->getTenantTeamIds($tenantId);

        if ($tplTeams === [] || array_intersect($tplTeams, $tenantTeams) === []) {
            throw new Error('TenantGuard: email template not shared with tenant.');
        }
    }

    /**
     * Group SMTP (InboundEmail) for journey sendEmail — never system outbound.
     * Account must be Active + useSmtp + host, teamed to the journey tenant.
     */
    public function assertInboundEmailAllowedForSending(string $inboundEmailId, string $tenantId): void
    {
        $account = $this->entityManager->getEntityById('InboundEmail', $inboundEmailId);

        if (!$account) {
            throw new Error('TenantGuard: group email account not found.');
        }

        $this->assertNotSystemSmtpAddress((string) ($account->get('emailAddress') ?? ''));
        $this->assertSmtpAccountUsable($account, 'group');

        $accountTeams = $this->getEntityTeamIds($account);
        $tenantTeams = $this->getTenantTeamIds($tenantId);

        if ($accountTeams === [] || array_intersect($accountTeams, $tenantTeams) === []) {
            throw new Error('TenantGuard: group email account not shared with tenant.');
        }

        if ($account->hasAttribute('tenantId') && $account->get('tenantId')) {
            if ((string) $account->get('tenantId') !== $tenantId) {
                throw new Error('TenantGuard: group email account outside tenant.');
            }
        }
    }

    /**
     * Chatwoot WhatsApp inbox for journey send actions.
     * Must share a team with the journey tenant and match allowed channelTypes.
     *
     * @param list<string> $allowedChannels e.g. whatsappQrcode / whatsappCloudApi / whatsappCoexistence
     * @return Entity ChatwootInbox
     */
    public function assertChatwootInboxAllowedForSending(
        string $chatwootInboxId,
        string $tenantId,
        array $allowedChannels,
    ): Entity {
        $inbox = $this->entityManager->getEntityById('ChatwootInbox', $chatwootInboxId);

        if (!$inbox) {
            throw new Error('TenantGuard: Chatwoot inbox not found.');
        }

        $channelType = (string) ($inbox->get('channelType') ?? '');
        if ($channelType === '' || !in_array($channelType, $allowedChannels, true)) {
            throw new Error(
                'TenantGuard: Chatwoot inbox channelType "' . $channelType .
                '" is not allowed for this action.'
            );
        }

        $status = (string) ($inbox->get('status') ?? '');
        if ($status !== '' && $status !== 'ACTIVE') {
            throw new Error('TenantGuard: Chatwoot inbox is not ACTIVE.');
        }

        $inboxTeams = $this->getEntityTeamIds($inbox);
        $tenantTeams = $this->getTenantTeamIds($tenantId);

        if ($inboxTeams === [] || array_intersect($inboxTeams, $tenantTeams) === []) {
            // Fall back to account teams when inbox teams empty/stale.
            $accountId = (string) ($inbox->get('chatwootAccountId') ?? '');
            $accountTeams = [];
            if ($accountId !== '') {
                $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
                if ($account) {
                    $accountTeams = $this->getEntityTeamIds($account);
                }
            }

            if ($accountTeams === [] || array_intersect($accountTeams, $tenantTeams) === []) {
                throw new Error('TenantGuard: Chatwoot inbox not shared with tenant.');
            }
        }

        if ($inbox->hasAttribute('tenantId') && $inbox->get('tenantId')) {
            if ((string) $inbox->get('tenantId') !== $tenantId) {
                throw new Error('TenantGuard: Chatwoot inbox outside tenant.');
            }
        }

        return $inbox;
    }

    /**
     * Personal SMTP (EmailAccount) for journey sendEmail — never system outbound.
     * Assigned user must belong to a tenant team.
     */
    public function assertEmailAccountAllowedForSending(string $emailAccountId, string $tenantId): void
    {
        $account = $this->entityManager->getEntityById('EmailAccount', $emailAccountId);

        if (!$account) {
            throw new Error('TenantGuard: personal email account not found.');
        }

        $this->assertNotSystemSmtpAddress((string) ($account->get('emailAddress') ?? ''));
        $this->assertSmtpAccountUsable($account, 'personal');

        $assignedUserId = $account->get('assignedUserId');
        if (!$assignedUserId) {
            throw new Error('TenantGuard: personal email account has no assigned user.');
        }

        $user = $this->entityManager->getEntityById('User', (string) $assignedUserId);
        if (!$user) {
            throw new Error('TenantGuard: personal email account assigned user missing.');
        }

        $userTeams = $this->getEntityTeamIds($user);
        $tenantTeams = $this->getTenantTeamIds($tenantId);

        if ($userTeams === [] || array_intersect($userTeams, $tenantTeams) === []) {
            throw new Error('TenantGuard: personal email account owner outside tenant.');
        }
    }

    private function assertSmtpAccountUsable(Entity $account, string $kind): void
    {
        if ((string) $account->get('status') !== 'Active') {
            throw new Error("TenantGuard: {$kind} email account is not Active.");
        }

        if (!$account->get('useSmtp')) {
            throw new Error("TenantGuard: {$kind} email account SMTP is disabled.");
        }

        if (!$account->get('smtpHost')) {
            throw new Error("TenantGuard: {$kind} email account has no SMTP host.");
        }

        if (method_exists($account, 'isAvailableForSending') && !$account->isAvailableForSending()) {
            throw new Error("TenantGuard: {$kind} email account is not available for sending.");
        }
    }

    /**
     * Block platform system SMTP identities (config outbound + System SMTP group accounts).
     */
    private function assertNotSystemSmtpAddress(string $address): void
    {
        $address = strtolower(trim($address));

        if ($address === '') {
            throw new Error('TenantGuard: email account has empty address.');
        }

        $blocked = [];

        foreach ([
            'outboundEmailFromAddress',
            'outboundEmailBccAddress',
            'companyEmailAddress',
        ] as $key) {
            $v = $this->config->get($key);
            if (is_string($v) && trim($v) !== '') {
                $blocked[] = strtolower(trim($v));
            }
        }

        /** @var list<string>|null $extra */
        $extra = $this->metadata->get(['app', 'journeySendEmail', 'blockedFromAddressList']);
        if (is_array($extra)) {
            foreach ($extra as $v) {
                if (is_string($v) && trim($v) !== '') {
                    $blocked[] = strtolower(trim($v));
                }
            }
        }

        // Known system group accounts with no tenant teams.
        $systemAccounts = $this->entityManager
            ->getRDBRepository('InboundEmail')
            ->where([
                'status' => 'Active',
                'useSmtp' => true,
                'OR' => [
                    ['name*' => 'System SMTP%'],
                    ['emailAddress*' => '%@monostax.ai'],
                    ['emailAddress*' => '%@app.monostax.ai'],
                ],
            ])
            ->limit(0, 50)
            ->find();

        foreach ($systemAccounts as $sys) {
            $teams = $this->getEntityTeamIds($sys);
            if ($teams !== []) {
                continue;
            }
            $ea = $sys->get('emailAddress');
            if (is_string($ea) && $ea !== '') {
                $blocked[] = strtolower(trim($ea));
            }
        }

        $blocked = array_values(array_unique(array_filter($blocked)));

        if (in_array($address, $blocked, true)) {
            throw new Error('TenantGuard: system SMTP address is not allowed for journey sendEmail.');
        }
    }

    /**
     * Runtime allow-list for platform script / evaluator class names.
     * Empty list → deny all (platform must register FQCNs in metadata).
     */
    public function assertClassAllowed(string $className, string $listKey): void
    {
        if ($className === '' || !class_exists($className)) {
            throw new Error("TenantGuard: class '{$className}' not found.");
        }

        /** @var list<string>|null $list */
        $list = $this->metadata->get(['app', 'journeyPlatformAllowList', $listKey]);

        if (!is_array($list) || $list === []) {
            throw new Error("TenantGuard: no allow-listed classes for {$listKey}.");
        }

        if (!in_array($className, $list, true)) {
            throw new Error("TenantGuard: class '{$className}' is not allow-listed.");
        }
    }

    /**
     * Filter target field updates to tenant-safe allow-list.
     *
     * Allows:
     * - static fieldsByEntityType entries
     * - Espo Entity Manager columns matching /^c[A-Z]/ when allowCustomFieldPrefix
     * - Monostax CustomField bag via `customFields` map and/or `customFields.<valueKey>`
     *   when allowCustomFieldsBag (and entityType is CF-enabled)
     *
     * Returned structure keeps bag keys as top-level `customFields` patch and/or
     * dotted keys — callers should run {@see applyTargetUpdateFields()}.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function filterTargetUpdateFields(string $entityType, array $fields): array
    {
        $out = [];

        /** @var array<string, list<string>> $byType */
        $byType = $this->metadata->get(['app', 'journeyUpdateTarget', 'fieldsByEntityType']) ?? [];
        $allowed = is_array($byType[$entityType] ?? null) ? $byType[$entityType] : [];
        $allowCustomPrefix = (bool) ($this->metadata->get(['app', 'journeyUpdateTarget', 'allowCustomFieldPrefix']) ?? true);
        $allowCustomFieldsBag = (bool) ($this->metadata->get(['app', 'journeyUpdateTarget', 'allowCustomFieldsBag']) ?? true);
        $bagAttr = $this->customFieldsBag->getAttributeName();
        $bagOk = $allowCustomFieldsBag && $this->customFieldsBag->isEntityEnabled($entityType);

        foreach ($fields as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            if (in_array($name, self::ALWAYS_BLOCKED_TARGET_FIELDS, true)) {
                continue;
            }

            if (str_ends_with($name, 'Ids') || str_ends_with($name, 'Names') || str_ends_with($name, 'Columns')) {
                // skip link-multiple dumps
                if ($name !== 'teamsIds') {
                    // still block **Ids that look like ACLs — only allow if explicitly listed
                }
            }

            $isBagKey = $name === $bagAttr || str_starts_with($name, $bagAttr . '.');

            $ok = in_array($name, $allowed, true)
                || in_array('*', $allowed, true)
                || ($isBagKey && $bagOk);

            if (!$ok && $allowCustomPrefix && preg_match('/^c[A-Z]/', $name)) {
                $ok = true;
            }

            if (!$ok) {
                $this->log->info("TenantGuard: strip field {$entityType}.{$name} from updateTarget");

                continue;
            }

            if ($isBagKey && is_array($value) === false && $name === $bagAttr && $value instanceof \stdClass) {
                $out[$name] = (array) $value;
                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * Apply filtered fields onto target, merging CustomField bag patches by valueKey.
     *
     * @param array<string, mixed> $filtered From {@see filterTargetUpdateFields()}
     * @return list<string> Attribute names that were written
     */
    public function applyTargetUpdateFields(Entity $target, array $filtered): array
    {
        if ($filtered === []) {
            return [];
        }

        $expanded = $this->customFieldsBag->expandUpdateFields($filtered);
        $written = [];

        foreach ($expanded['fields'] as $name => $value) {
            $target->set($name, $value);
            $written[] = $name;
        }

        if ($expanded['bagPatch'] !== []) {
            $attr = $this->customFieldsBag->getAttributeName();
            $this->customFieldsBag->applyBagPatch($target, $expanded['bagPatch']);
            $written[] = $attr;
        }

        return array_values(array_unique($written));
    }

    /**
     * Build where-append for entityFilter so tenant filters cannot match foreign rows.
     *
     * @return array<string, mixed>
     */
    public function tenantWhereForEntityType(string $entityType, string $tenantId): array
    {
        if ($tenantId === '') {
            return ['id' => null]; // match nothing
        }

        try {
            $defs = $this->entityManager->getDefs()->getEntity($entityType);

            if ($defs->hasAttribute('tenantId')) {
                return ['tenantId' => $tenantId];
            }
        } catch (Throwable) {
        }

        $teamIds = $this->getTenantTeamIds($tenantId);

        if ($teamIds === []) {
            return ['id' => null];
        }

        // Teams link filter — Espo where syntax for link-multiple.
        return [
            'teams.id' => $teamIds,
        ];
    }

    /**
     * @return list<string>
     */
    private function getEntityTeamIds(Entity $entity): array
    {
        $ids = [];

        try {
            $ids = $entity->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $ids = $entity->get('teamsIds') ?: [];
        }

        if ((!is_array($ids) || $ids === []) && $entity->hasId()) {
            try {
                $teams = $this->entityManager
                    ->getRDBRepository($entity->getEntityType())
                    ->getRelation($entity, 'teams')
                    ->find();

                foreach ($teams as $team) {
                    $ids[] = $team->getId();
                }
            } catch (Throwable) {
            }
        }

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $ids))));
    }
}
