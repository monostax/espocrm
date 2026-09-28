<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\ORM\FunctionConverters;

use Espo\Core\Utils\Database\ConfigDataProvider;
use Espo\ORM\QueryComposer\Part\FunctionConverter;
use RuntimeException;

/** AI_RUN_OUTCOME:modelUsage reads the persisted terminal outcome, including legacy NULLs. */
class AgentRunOutcome implements FunctionConverter
{
    public function __construct(private ConfigDataProvider $config) {}

    public function convert(string ...$argumentList): string
    {
        if (count($argumentList) !== 1) {
            throw new RuntimeException('AI_RUN_OUTCOME requires one modelUsage argument.');
        }
        $usage = $argumentList[0];
        return match ($this->config->getPlatform()) {
            'Mysql' => "JSON_UNQUOTE(JSON_EXTRACT(NULLIF($usage, ''), '$.run.outcome'))",
            'Postgresql' => "(CAST(NULLIF($usage, '') AS jsonb) #>> '{run,outcome}')",
            default => throw new RuntimeException('Unsupported agent usage database platform.'),
        };
    }
}
