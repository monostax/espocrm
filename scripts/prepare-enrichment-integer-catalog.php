<?php

declare(strict_types=1);

// Pure preparation; publish the output through CreditEnrichmentConfiguration.
require dirname(__DIR__) . '/vendor/autoload.php';

use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Enrichment\RateCard;

try {
    if ($argc !== 2) throw new InvalidArgumentException('Usage: php scripts/prepare-enrichment-integer-catalog.php "YYYY-MM-DD HH:MM:SS" (future UTC start)');
    GrantInput::timestamp($argv[1]);
    if ($argv[1] <= gmdate('Y-m-d H:i:s')) throw new InvalidArgumentException('Catalog effective time must be in the future.');
    $input = json_decode(file_get_contents(dirname(__DIR__) . '/custom/Espo/Modules/FeatureCredits/Resources/enrichment/integer-2026-10-14.json'), true, 32, JSON_THROW_ON_ERROR);
    $input['effectiveFrom'] = $argv[1];
    $card = new RateCard($input);
    echo json_encode($card->definition, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}
