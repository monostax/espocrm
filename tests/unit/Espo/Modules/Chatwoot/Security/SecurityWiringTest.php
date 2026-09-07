<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Security;

use Espo\Core\Binding\Binder;
use Espo\Modules\Chatwoot\Binding;
use Espo\Modules\Chatwoot\Classes\Select\Attachment\OpportunityAccess as AttachmentApplier;
use Espo\Modules\Chatwoot\Classes\Select\Note\OpportunityEventAccess as NoteApplier;
use Espo\Modules\Chatwoot\Classes\Select\Opportunity\AccessControlFilters\Tenant;
use Espo\Modules\Chatwoot\Tools\Stream\QueryHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecurityWiringTest extends TestCase
{
    private function metadata(string $path): array
    {
        return json_decode(file_get_contents(
            dirname(__DIR__, 6) . '/custom/Espo/Modules/Chatwoot/Resources/metadata/' . $path . '.json',
        ), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function guardedEntities(): iterable
    {
        foreach (['Note', 'Opportunity', 'Attachment'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('guardedEntities')]
    public function testInternalAndPortalAclMetadataUsesTenantGuard(string $type): void
    {
        $defs = $this->metadata("aclDefs/$type");
        self::assertSame("Espo\\Modules\\Chatwoot\\Classes\\Acl\\$type\\AccessChecker", $defs['accessCheckerClassName']);
        self::assertSame("Espo\\Modules\\Chatwoot\\Classes\\AclPortal\\$type\\AccessChecker", $defs['portalAccessCheckerClassName']);
    }

    public function testTenantFilterIsMandatoryRatherThanUserSelectable(): void
    {
        $defs = $this->metadata('selectDefs/Opportunity');
        self::assertSame(Tenant::class, $defs['accessControlFilterClassNameMap']['mandatory']);
    }

    public function testNoteAndAttachmentListGuardsAreAdditionalAppliers(): void
    {
        self::assertContains(NoteApplier::class, $this->metadata('selectDefs/Note')['additionalApplierClassNameList']);
        self::assertContains(AttachmentApplier::class, $this->metadata('selectDefs/Attachment')['additionalApplierClassNameList']);
    }

    public function testStockStreamQueryHelperIsBoundToGuardedImplementation(): void
    {
        $bindings = [];
        $binder = $this->createMock(Binder::class);
        $binder->method('bindImplementation')->willReturnCallback(function ($base, $implementation) use (&$bindings, $binder) {
            $bindings[$base] = $implementation;
            return $binder;
        });
        (new Binding())->process($binder);
        self::assertSame(QueryHelper::class, $bindings[\Espo\Tools\Stream\RecordService\QueryHelper::class]);
    }
}
