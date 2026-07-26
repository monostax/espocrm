<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\Formula\Parser;
use Espo\Core\Formula\Parser\Ast\Attribute;
use Espo\Core\Formula\Parser\Ast\Node;
use Espo\Core\Formula\Parser\Ast\Value;
use Espo\Core\Formula\Parser\Ast\Variable;
use Espo\ORM\Entity;
use stdClass;

/**
 * Parse-time AST gate then execute via Formula\Manager.
 *
 * Modes:
 * - condition: pure / read-only (no journey* side effects) — used for conditions + paramFormulas
 * - action: condition set + journey enroll|moveToStage|signal|updateTarget
 */
class RestrictedFormulaRunner
{
    public const MODE_CONDITION = 'condition';
    public const MODE_ACTION = 'action';

    /** @var list<string> */
    private const ALLOWED_EXACT = [
        'assign',
        'bundle',
        'list',
        'ifThen',
        'ifThenElse',
        'null',
        'true',
        'false',
        'value',
        'variable',
        'attribute',
        'entity\\attribute',
        'entity\\isAttributeChanged',
        'entity\\isNew',
        'entity\\attributeFetched',
        'util\\empty',
        'json\\retrieve',
        'object\\get',
        'object\\create',
        'object\\cloneDeep',
        'array\\includes',
        'array\\length',
        'array\\at',
        'array\\push',
        'array\\join',
    ];

    /** @var list<string> */
    private const ALLOWED_PREFIXES = [
        'string\\',
        'number\\',
        'numeric\\',
        'datetime\\',
        'logical\\',
        'comparison\\',
    ];

    /**
     * Side-effect journey functions — MODE_ACTION only.
     * Each impl enforces TenantGuard + JourneyEffectDepth.
     *
     * @var list<string>
     */
    public const ACTION_ONLY_EXACT = [
        'journey\\enroll',
        'journey\\moveToStage',
        'journey\\signal',
        'journey\\updateTarget',
    ];

    /** @var list<string> */
    private const BLOCKED_EXACT = [
        'setAttribute', // bare `field = value` write
    ];

    /** @var list<string> */
    private const BLOCKED_PREFIXES = [
        'record\\',
        'ext\\',
        'password\\',
        'workflow\\',
        'bpm\\',
        'email\\',
        'entity\\set',
        'entity\\addLink',
        'entity\\removeLink',
        'entity\\clear',
        'entity\\save',
        'env\\',
    ];

    public function __construct(
        private FormulaManager $formulaManager,
    ) {}

    /**
     * @param array<string, mixed>|stdClass|null $variables
     */
    public function run(
        string $script,
        ?Entity $entity = null,
        array|stdClass|null $variables = null,
        string $mode = self::MODE_CONDITION,
    ): mixed {
        $script = trim($script);

        if ($script === '') {
            return null;
        }

        if ($mode !== self::MODE_CONDITION && $mode !== self::MODE_ACTION) {
            throw new Error("RestrictedFormulaRunner: unknown mode '{$mode}'.");
        }

        $parser = new Parser();
        $ast = $parser->parse($script);
        $this->assertAllowed($ast, $mode);

        return $this->formulaManager->run($script, $entity, $variables);
    }

    /**
     * Public for unit tests — validates AST without executing.
     */
    public function assertScriptAllowed(string $script, string $mode = self::MODE_CONDITION): void
    {
        $script = trim($script);

        if ($script === '') {
            return;
        }

        if ($mode !== self::MODE_CONDITION && $mode !== self::MODE_ACTION) {
            throw new Error("RestrictedFormulaRunner: unknown mode '{$mode}'.");
        }

        $parser = new Parser();
        $this->assertAllowed($parser->parse($script), $mode);
    }

    private function assertAllowed(mixed $node, string $mode): void
    {
        if ($node instanceof Value || $node instanceof Variable || $node instanceof Attribute) {
            return;
        }

        if (!$node instanceof Node) {
            return;
        }

        $type = $node->getType();

        if (in_array($type, self::BLOCKED_EXACT, true)) {
            throw new Error("RestrictedFormulaRunner: function '{$type}' is not allowed.");
        }

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                throw new Error("RestrictedFormulaRunner: function '{$type}' is not allowed.");
            }
        }

        if (str_starts_with($type, 'journey\\')) {
            if ($mode !== self::MODE_ACTION || !in_array($type, self::ACTION_ONLY_EXACT, true)) {
                throw new Error(
                    "RestrictedFormulaRunner: function '{$type}' is not allowed in {$mode} mode."
                );
            }
        } elseif (!$this->isAllowedType($type)) {
            throw new Error("RestrictedFormulaRunner: function '{$type}' is not allowed.");
        }

        foreach ($node->getChildNodes() as $child) {
            $this->assertAllowed($child, $mode);
        }
    }

    private function isAllowedType(string $type): bool
    {
        if (in_array($type, self::ALLOWED_EXACT, true)) {
            return true;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
