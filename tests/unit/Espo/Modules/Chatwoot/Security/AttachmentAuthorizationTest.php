<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Security;

use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/SecurityTestCase.php';

class AttachmentAuthorizationTest extends SecurityTestCase
{
    public static function deniedNotes(): iterable
    {
        foreach ([false, true] as $portal) {
            foreach (['parent', 'related'] as $link) {
                foreach (['foreign', 'revoked', 'missing-note', 'missing-opportunity', 'tenantless', 'no-read', 'no-stream', 'hidden-event'] as $case) {
                    yield ($portal ? 'portal ' : 'internal ') . "$link $case" => [$portal, $link, $case];
                }
            }
        }
    }

    #[DataProvider('deniedNotes')]
    public function testNoteDenialCannotFallThroughToAttachmentOwnerOrTarget(bool $portal, string $link, string $case): void
    {
        $this->portal = $portal;
        $note = $this->note(['parentId' => $case === 'foreign' ? 'opp-b' : 'opp-a']);
        $attachment = $this->attachment($link === 'related'
            ? ['parentType' => null, 'parentId' => null, 'relatedType' => 'Note', 'relatedId' => 'note'] : []);
        if ($case === 'revoked') {
            self::assertTrue($this->attachments->check($this->user, $attachment));
            $this->explicitTenants = [];
        }
        if ($case === 'missing-note') {
            unset($this->entities['Note:note']);
        }
        if ($case === 'missing-opportunity') {
            unset($this->entities['Opportunity:opp-a']);
        }
        if ($case === 'tenantless') {
            $this->entities['Opportunity:opp-a']->set('tenantId', null);
        }
        if ($case === 'hidden-event') {
            $note->set(['type' => OpportunityStreamEvents::MESSAGE_RECEIVED, 'relatedType' => 'ChatwootConversation', 'relatedId' => 'hidden']);
            $this->entity('ChatwootConversation', ['id' => 'hidden']);
            $this->relatedReadAllowed = false;
        }
        $this->readAllowed = $case !== 'no-read';
        $this->streamAllowed = $case !== 'no-stream';
        $checker = $portal ? $this->portalAttachmentChecker : $this->attachmentChecker;
        // These are stock allow paths; none may override the Note's denial.
        foreach (['all', 'teams', 'users', 'portals', null] as $target) {
            $note->set('targetType', $target);
            self::assertFalse($checker->checkEntityRead($this->user, $attachment, $this->scope), (string) $target);
        }
    }

    public static function parentPrecedence(): iterable
    {
        yield 'foreign parent beats own related' => ['Note', 'foreign', 'Note', 'own', false];
        yield 'own parent beats foreign related' => ['Note', 'own', 'Note', 'foreign', true];
        yield 'missing parent beats own related' => ['Note', 'missing', 'Note', 'own', false];
        yield 'unrelated parent beats foreign related' => ['Account', 'account', 'Note', 'foreign', null];
        yield 'missing parent type uses related' => [null, 'own', 'Note', 'foreign', false];
        yield 'missing parent id uses related' => ['Note', null, 'Note', 'foreign', false];
        yield 'empty parent id uses related' => ['Note', '', 'Note', 'foreign', false];
        yield 'empty unrelated parent id uses related' => ['Account', '', 'Note', 'foreign', false];
        yield 'empty parent type uses related' => ['', 'account', 'Note', 'foreign', false];
        yield 'related opportunity denied' => [null, null, 'Opportunity', 'opp-b', false];
        yield 'direct opportunity denied' => ['Opportunity', 'opp-b', 'Note', 'own', false];
        yield 'direct opportunity missing' => ['Opportunity', 'missing', null, null, false];
        yield 'unlinked upload' => [null, null, null, null, null];
        yield 'incomplete note upload' => ['Note', null, null, null, null];
    }

    #[DataProvider('parentPrecedence')]
    public function testParentRelatedSelectionMatchesStockPrecedence(?string $parentType, ?string $parentId, ?string $relatedType, ?string $relatedId, ?bool $expected): void
    {
        $this->note(['id' => 'foreign', 'parentId' => 'opp-b']);
        $this->note(['id' => 'own']);
        $attachment = $this->attachment(compact('parentType', 'parentId', 'relatedType', 'relatedId'));
        self::assertSame($expected, $this->attachments->check($this->user, $attachment));
    }

    public function testAllowedNotesInBothTenantsAndGlobalAdminRemainReadable(): void
    {
        $this->teamTenants = ['tenant-b'];
        foreach (['opp-a', 'opp-b'] as $id) {
            $this->note(['parentId' => $id]);
            self::assertTrue($this->attachmentChecker->checkEntityRead($this->user, $this->attachment(), $this->scope));
        }
        $this->admin = true;
        $this->note(['parentId' => 'opp-c']);
        self::assertNull($this->attachments->check($this->user, $this->attachment()));
        self::assertTrue($this->attachmentChecker->checkEntityRead($this->user, $this->attachment(), $this->scope));
        self::assertSame([], $this->attachments->where($this->user));
    }

    public function testUnlinkedUploadsRetainStockOwnerAndRoleDecisions(): void
    {
        $this->explicitTenants = [];
        $attachment = $this->attachment(['parentType' => null, 'parentId' => null]);
        self::assertNull($this->attachments->check($this->user, $attachment));
        self::assertTrue($this->attachmentChecker->checkEntityRead($this->user, $attachment, $this->scope));
        $this->readAllowed = false;
        self::assertFalse($this->attachmentChecker->checkEntityRead($this->user, $attachment, $this->scope));
        $this->portal = true;
        self::assertTrue($this->portalAttachmentChecker->checkEntityRead($this->user, $attachment, $this->scope));
        $attachment->set('createdById', 'other');
        self::assertFalse($this->portalAttachmentChecker->checkEntityRead($this->user, $attachment, $this->scope));
    }

    public function testUnrelatedAttachmentRetainsFieldAclAndSettingsLogo(): void
    {
        $this->explicitTenants = [];
        $this->entity('Account', ['id' => 'account']);
        $attachment = $this->attachment(['parentType' => 'Account', 'parentId' => 'account']);
        self::assertNull($this->attachments->check($this->user, $attachment));
        self::assertTrue($this->attachmentChecker->checkEntityRead($this->user, $attachment, $this->scope));
        $attachment->set('field', 'privateDocument');
        $this->fieldAllowed = false;
        self::assertFalse($this->attachmentChecker->checkEntityRead($this->user, $attachment, $this->scope));
        self::assertFalse($this->portalAttachmentChecker->checkEntityRead($this->user, $attachment, $this->scope));
        $logo = $this->attachment(['parentType' => 'Settings', 'parentId' => null]);
        self::assertTrue($this->attachmentChecker->checkEntityRead($this->user, $logo, $this->scope));
        self::assertTrue($this->portalAttachmentChecker->checkEntityRead($this->user, $logo, $this->scope));
    }

    public function testPortalInternalNoteAttachmentDeniedEvenForMemberOwner(): void
    {
        $this->portal = true;
        $this->note(['isInternal' => true]);
        self::assertFalse($this->portalAttachmentChecker->checkEntityRead($this->user, $this->attachment(), $this->scope));
    }
}
