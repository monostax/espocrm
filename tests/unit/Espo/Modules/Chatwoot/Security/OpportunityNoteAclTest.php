<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Security;

use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/SecurityTestCase.php';

class OpportunityNoteAclTest extends SecurityTestCase
{
    public static function noteActions(): iterable
    {
        foreach ([false, true] as $portal) {
            foreach (['Create', 'Read', 'Edit', 'Delete'] as $action) {
                yield ($portal ? 'portal ' : 'internal ') . $action => [$portal, "checkEntity$action"];
            }
        }
    }

    #[DataProvider('noteActions')]
    public function testForeignTenantDeniedDespiteBroadRoleAuthorAndTargets(bool $portal, string $method): void
    {
        $this->portal = $portal;
        $note = $this->note(['parentId' => 'opp-b']);
        $checker = $portal ? $this->portalNoteChecker : $this->noteChecker();
        self::assertFalse($checker->$method($this->user, $note, $this->scope));
    }

    #[DataProvider('noteActions')]
    public function testRevocationTakesEffectOnSameObjectsWithoutAuthorBypass(bool $portal, string $method): void
    {
        $this->portal = $portal;
        $note = $this->note();
        $checker = $portal ? $this->portalNoteChecker : $this->noteChecker();
        self::assertTrue($checker->$method($this->user, $note, $this->scope));
        $this->explicitTenants = [];
        self::assertFalse($checker->$method($this->user, $note, $this->scope));
    }

    public static function missingParents(): iterable
    {
        foreach (self::noteActions() as $name => [$portal, $method]) {
            foreach ([null, '', 'deleted-parent', 'tenantless'] as $id) {
                yield "$name " . var_export($id, true) => [$portal, $method, $id];
            }
        }
    }

    #[DataProvider('missingParents')]
    public function testMissingParentOrTenantFailsClosed(bool $portal, string $method, ?string $id): void
    {
        $this->portal = $portal;
        $this->entity('Opportunity', ['id' => 'tenantless', 'tenantId' => null]);
        $checker = $portal ? $this->portalNoteChecker : $this->noteChecker();
        self::assertFalse($checker->$method($this->user, $this->note(['parentId' => $id]), $this->scope));
    }

    #[DataProvider('noteActions')]
    public function testParentReadAndStreamAreIndependentlyRequired(bool $portal, string $method): void
    {
        $this->portal = $portal;
        $checker = $portal ? $this->portalNoteChecker : $this->noteChecker();
        $note = $this->note();
        $this->readAllowed = false;
        self::assertFalse($checker->$method($this->user, $note, $this->scope));
        $this->readAllowed = true;
        $this->streamAllowed = false;
        self::assertFalse($checker->$method($this->user, $note, $this->scope));
        $this->streamAllowed = true;
        self::assertTrue($checker->$method($this->user, $note, $this->scope));
    }

    #[DataProvider('noteActions')]
    public function testExplicitAndTeamTenantsAreBothAllowedButThirdTenantIsNot(bool $portal, string $method): void
    {
        $this->portal = $portal;
        $this->teamTenants = ['tenant-b'];
        $checker = $portal ? $this->portalNoteChecker : $this->noteChecker();
        foreach (['a' => true, 'b' => true, 'c' => false] as $tenant => $expected) {
            self::assertSame($expected, $checker->$method($this->user, $this->note(['parentId' => "opp-$tenant"]), $this->scope));
        }
        $this->explicitTenants = [];
        self::assertTrue($checker->$method($this->user, $this->note(['parentId' => 'opp-b']), $this->scope));
        $this->teamTenants = [];
        self::assertFalse($checker->$method($this->user, $this->note(['parentId' => 'opp-b']), $this->scope));
    }

    public function testGlobalAdminWithoutMembershipMayAccessForeignOpportunityPosts(): void
    {
        $this->admin = true;
        $this->explicitTenants = [];
        $note = $this->note(['parentId' => 'opp-c', 'createdById' => 'someone-else', 'createdAt' => '2000-01-01 00:00:00']);
        foreach (['Create', 'Read', 'Edit', 'Delete'] as $action) {
            self::assertTrue($this->noteChecker()->{'checkEntity' . $action}($this->user, $note, $this->scope));
        }
        self::assertSame([], $this->parents->where($this->user));
    }

    public static function stockMutations(): iterable
    {
        foreach ([false, true] as $portal) {
            foreach (['Edit', 'Delete'] as $action) {
                foreach (['not-owner', 'expired', 'invalid-date', 'role-denied', 'recent', 'no-date'] as $case) {
                    yield ($portal ? 'portal ' : 'internal ') . "$action $case" => [$portal, $action, $case];
                }
            }
        }
    }

    #[DataProvider('stockMutations')]
    public function testMembershipDoesNotReplaceStockOwnershipRoleAndTimeChecks(bool $portal, string $action, string $case): void
    {
        $this->portal = $portal;
        $note = $this->note([
            'createdById' => $case === 'not-owner' ? 'other' : 'agent',
            'createdAt' => match ($case) {
                'expired' => '2000-01-01 00:00:00',
                'invalid-date' => 'not-a-date',
                'no-date' => null,
                default => gmdate('Y-m-d H:i:s'),
            },
        ]);
        $this->editAllowed = $this->deleteAllowed = $case !== 'role-denied';
        $checker = $portal ? $this->portalNoteChecker : $this->noteChecker();
        self::assertSame(in_array($case, ['recent', 'no-date'], true), $checker->{'checkEntity' . $action}($this->user, $note, $this->scope));
    }

    public function testEditAndDeleteRetainDifferentStockThresholds(): void
    {
        $note = $this->note(['createdAt' => gmdate('Y-m-d H:i:s', time() - 14 * 86400)]);
        foreach ([$this->noteChecker(), $this->portalNoteChecker] as $checker) {
            self::assertFalse($checker->checkEntityEdit($this->user, $note, $this->scope));
            self::assertTrue($checker->checkEntityDelete($this->user, $note, $this->scope));
        }
    }

    public function testUnrelatedAndUnparentedPostsKeepStockAuthorization(): void
    {
        $this->explicitTenants = [];
        $note = $this->note(['parentType' => null, 'parentId' => null]);
        self::assertTrue($this->noteChecker()->checkEntityRead($this->user, $note, $this->scope));
        self::assertTrue($this->noteChecker()->checkEntityEdit($this->user, $note, $this->scope));
        $this->entity('Account', ['id' => 'account']);
        $note = $this->note(['parentType' => 'Account', 'parentId' => 'account']);
        self::assertTrue($this->parents->canReadNote($this->user, $note));
        $this->streamAllowed = false;
        self::assertFalse($this->noteChecker()->checkEntityRead($this->user, $note, $this->scope));
    }

    public function testPortalInternalNoteIsDeniedEvenToMemberAuthor(): void
    {
        $this->portal = true;
        $note = $this->note(['isInternal' => true]);
        self::assertFalse($this->portalNoteChecker->checkEntityRead($this->user, $note, $this->scope));
        self::assertFalse($this->portalNoteChecker->checkEntityDelete($this->user, $note, $this->scope));
    }

    public function testEventsRetainRelatedAclAndCannotBeForgedOrEdited(): void
    {
        foreach (OpportunityStreamEvents::EVENT_TYPES as $type) {
            $relatedType = $type === OpportunityStreamEvents::MESSAGE_RECEIVED ? 'ChatwootConversation' : 'Task';
            $this->entity($relatedType, ['id' => 'related']);
            $note = $this->note(['type' => $type, 'relatedType' => $relatedType, 'relatedId' => 'related']);
            self::assertTrue($this->noteChecker()->checkEntityRead($this->user, $note, $this->scope));
            $this->relatedReadAllowed = false;
            self::assertFalse($this->noteChecker()->checkEntityRead($this->user, $note, $this->scope));
            self::assertFalse($this->noteChecker()->checkEntityDelete($this->user, $note, $this->scope));
            $this->relatedReadAllowed = true;
            $note->set('relatedId', 'missing');
            self::assertFalse($this->noteChecker()->checkEntityRead($this->user, $note, $this->scope));
            foreach ([$this->noteChecker(), $this->portalNoteChecker] as $checker) {
                self::assertFalse($checker->checkEntityCreate($this->user, $note, $this->scope));
                self::assertFalse($checker->checkEntityEdit($this->user, $note, $this->scope));
            }
        }
    }

    public static function opportunityActions(): iterable
    {
        foreach ([false, true] as $portal) {
            foreach (['Read', 'Stream', 'Edit', 'Delete'] as $action) {
                yield ($portal ? 'portal ' : 'internal ') . $action => [$portal, "checkEntity$action"];
            }
        }
    }

    #[DataProvider('opportunityActions')]
    public function testOpportunityTenantGateAndStockReadAreMandatory(bool $portal, string $method): void
    {
        $this->portal = $portal;
        $checker = $portal ? $this->portalOpportunityChecker : $this->opportunityChecker;
        $own = $this->entities['Opportunity:opp-a'];
        self::assertFalse($checker->$method($this->user, $this->entities['Opportunity:opp-b'], $this->scope));
        self::assertTrue($checker->$method($this->user, $own, $this->scope));
        $this->readAllowed = false;
        self::assertFalse($checker->$method($this->user, $own, $this->scope));
        $this->readAllowed = true;
        $this->explicitTenants = [];
        self::assertFalse($checker->$method($this->user, $own, $this->scope));
        $own->set('tenantId', null);
        self::assertFalse($checker->$method($this->user, $own, $this->scope));
    }

    public function testMembershipStorageFailureCannotDegradeToBroadAclOrTeamMembership(): void
    {
        $this->teamTenants = ['tenant-a'];
        $this->membershipQueryFails = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Membership storage unavailable');
        $this->parents->canReadNote($this->user, $this->note());
    }

    public function testOpportunityGlobalAdminAndMultiTenantUsersRemainAllowed(): void
    {
        $this->teamTenants = ['tenant-b'];
        foreach (['Read', 'Stream', 'Edit', 'Delete'] as $action) {
            $method = "checkEntity$action";
            self::assertTrue($this->opportunityChecker->$method($this->user, $this->entities['Opportunity:opp-a'], $this->scope));
            self::assertTrue($this->opportunityChecker->$method($this->user, $this->entities['Opportunity:opp-b'], $this->scope));
            self::assertFalse($this->opportunityChecker->$method($this->user, $this->entities['Opportunity:opp-c'], $this->scope));
        }
        $this->admin = true;
        $this->explicitTenants = $this->teamTenants = [];
        foreach (['Read', 'Stream', 'Edit', 'Delete'] as $action) {
            self::assertTrue($this->opportunityChecker->{'checkEntity' . $action}($this->user, $this->entities['Opportunity:opp-c'], $this->scope));
        }
    }

    public function testPortalEventDirectReadMustNotExposeRelatedRecordDeniedByAcl(): void
    {
        $this->portal = true;
        $this->relatedReadAllowed = false;
        $this->entity('ChatwootConversation', ['id' => 'hidden']);
        $note = $this->note(['type' => OpportunityStreamEvents::MESSAGE_RECEIVED, 'relatedType' => 'ChatwootConversation', 'relatedId' => 'hidden']);
        self::assertFalse($this->portalNoteChecker->checkEntityRead($this->user, $note, $this->scope));
    }

    public function testSharedParentGuardRequiresBothPermissionsAndCurrentMembership(): void
    {
        $note = $this->note();
        self::assertTrue($this->parents->canReadNote($this->user, $note));
        $this->readAllowed = false;
        self::assertFalse($this->parents->canReadNote($this->user, $note));
        $this->readAllowed = true;
        $this->streamAllowed = false;
        self::assertFalse($this->parents->canReadNote($this->user, $note));
        $this->streamAllowed = true;
        $this->explicitTenants = [];
        self::assertFalse($this->parents->canReadNote($this->user, $note));
        $this->teamTenants = ['tenant-a', 'tenant-b'];
        self::assertTrue($this->parents->canReadNote($this->user, $note));
        self::assertTrue($this->parents->canReadNote($this->user, $this->note(['parentId' => 'opp-b'])));
        self::assertFalse($this->parents->canReadNote($this->user, $this->note(['parentId' => 'opp-c'])));
        $this->admin = true;
        self::assertTrue($this->parents->canReadNote($this->user, $this->note(['parentId' => 'opp-c'])));
    }
}
