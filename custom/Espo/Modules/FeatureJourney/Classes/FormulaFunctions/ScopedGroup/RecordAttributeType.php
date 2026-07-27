<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\FormulaFunctions\ScopedGroup;

use Espo\Core\Acl\Exceptions\NotImplemented as AclNotImplemented;
use Espo\Core\Di\AclManagerAware;
use Espo\Core\Di\AclManagerSetter;
use Espo\Core\Di\InjectableFactoryAware;
use Espo\Core\Di\InjectableFactorySetter;
use Espo\Core\Di\MetadataAware;
use Espo\Core\Di\MetadataSetter;
use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\ArgumentList;
use Espo\Core\Formula\Functions\BaseFunction;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Services\FormulaReadScope;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Throwable;

/**
 * scoped\recordAttribute(ENTITY_TYPE, ID, ATTRIBUTE)
 *
 * Read one attribute off a record the current formula is *not* targeting —
 * e.g. a User-targeted notification body reading the Opportunity that fired the
 * trigger.
 *
 * The core `record\*` family is blocked in RestrictedFormulaRunner: those
 * implementations call EntityManager directly with no tenant predicate and no
 * ACL, so allowing them would let any tenant's definition read any record by id.
 * This is the guarded replacement, and it is read-only by construction — there
 * is no write sibling in this group.
 *
 * Three gates, all mandatory:
 *  1. tenant  — TenantGuard::loadEntityInTenant against the active scope frame
 *  2. record  — AclManager read check for the scope's actor
 *  3. field   — attribute must not be in the actor's forbidden-attribute list
 *
 * Tenant and actor come from FormulaReadScope, never from arguments or formula
 * variables: `assign` is an allowed function and writes into the shared variables
 * object, so anything read out of formula scope is author-controlled. Same
 * principle as journey\signal refusing a free tenantId argument.
 *
 * Denials throw rather than return null. A silently empty value renders a
 * half-written notification and hides a misconfigured definition; every denial
 * here is either an authoring bug or an isolation breach, and both want to be
 * loud.
 */
class RecordAttributeType extends BaseFunction implements AclManagerAware, InjectableFactoryAware, MetadataAware
{
    use AclManagerSetter;
    use InjectableFactorySetter;
    use MetadataSetter;

    private const FN = 'scoped\\recordAttribute';

    public function process(ArgumentList $args)
    {
        if (count($args) < 3) {
            $this->throwTooFewArguments(3);
        }

        $evaluated = $this->evaluate($args);

        $entityType = $evaluated[0] ?? null;
        $id = $evaluated[1] ?? null;
        $attribute = $evaluated[2] ?? null;

        if (!is_string($entityType) || trim($entityType) === '') {
            $this->throwBadArgumentType(1, 'string');
        }
        if (!is_string($id) || trim($id) === '') {
            $this->throwBadArgumentType(2, 'string');
        }
        if (!is_string($attribute) || trim($attribute) === '') {
            $this->throwBadArgumentType(3, 'string');
        }

        /** @var string $entityType */
        $entityType = trim($entityType);
        /** @var string $id */
        $id = trim($id);
        /** @var string $attribute */
        $attribute = trim($attribute);

        // Reject anything that is not a plain attribute name up front, so this
        // can never be used to walk a path or smuggle an expression.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $attribute)) {
            throw new Error(
                'Formula: ' . self::FN . ": invalid attribute name '{$attribute}'."
            );
        }
        if ($attribute === 'deleted') {
            throw new Error('Formula: ' . self::FN . ': attribute not readable.');
        }

        if (!$this->metadata->get(['entityDefs', $entityType])) {
            throw new Error(
                'Formula: ' . self::FN . ": unknown entity type '{$entityType}'."
            );
        }

        $frame = FormulaReadScope::requireFrame(self::FN);
        $tenantId = $frame['tenantId'];
        $actor = $frame['actor'];

        $guard = $this->injectableFactory->create(TenantGuard::class);

        // Throws when missing or owned by another tenant.
        $entity = $guard->loadEntityInTenant($entityType, $id, $tenantId, self::FN);

        try {
            $allowed = $this->aclManager->createUserAcl($actor)->check($entity, 'read');
        } catch (AclNotImplemented) {
            // Scope has no ACL implementation — tenant check already passed and
            // there is nothing further to consult, so treat as readable.
            $allowed = true;
        }

        if (!$allowed) {
            throw new Error(
                'Formula: ' . self::FN . ": read denied on {$entityType} for the run-as user."
            );
        }

        if (in_array($attribute, $this->forbiddenAttributes($actor, $entityType), true)) {
            throw new Error(
                'Formula: ' . self::FN . ": attribute '{$attribute}' is forbidden for the run-as user."
            );
        }

        if (!$entity->hasAttribute($attribute)) {
            throw new Error(
                'Formula: ' . self::FN . ": {$entityType} has no attribute '{$attribute}'."
            );
        }

        return $entity->get($attribute);
    }

    /**
     * Field-level ACL. A lookup failure must not silently widen access, so a
     * throw here denies everything rather than returning an empty list.
     *
     * @return list<string>
     */
    private function forbiddenAttributes(User $actor, string $entityType): array
    {
        try {
            return $this->aclManager->getScopeForbiddenAttributeList($actor, $entityType);
        } catch (Throwable $e) {
            throw new Error(
                'Formula: ' . self::FN . ': forbidden-attribute lookup failed for '
                . $entityType . ' — ' . $e->getMessage()
            );
        }
    }
}
