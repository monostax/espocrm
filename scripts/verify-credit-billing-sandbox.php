<?php

declare(strict_types=1);

// Readiness only: do not create customers, payments, webhooks or database records.
// Also supports piping this local file to `kubectl exec -i ... -- php`.
$root = is_file(getcwd() . '/bootstrap.php') ? getcwd() : dirname(__DIR__);
chdir($root);
require 'bootstrap.php';

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Billing\Asaas\Connections;
use Espo\Modules\FeatureCredits\Billing\Providers;

try {
    $app = new Espo\Core\Application();
    $config = $app->getContainer()->getByClass(Config::class);
    $factory = $app->getInjectableFactory();
    $connections = $factory->create(Connections::class);
    $connection = $connections->get('asaas-sandbox');
    $token = getenv('BILLING_ASAAS_SANDBOX_WEBHOOK_TOKEN');
    $wallet = getenv('BILLING_ASAAS_SANDBOX_WALLET_ID');
    $providers = $factory->create(Providers::class);
    $checks = [
        'sandboxHost' => $connection->baseUrl() === 'https://api-sandbox.asaas.com/v3',
        'creationDisabled' => $connection->creationEnabled === false,
        'reconciliationDisabled' => $config->get('featureCreditsBillingReconciliationEnabled') === false,
        'walletIdPresent' => is_string($wallet) && $wallet !== '',
        'asaasSalesDisabled' => $providers->capability('asaas') === null,
        'stripeSalesDisabled' => $providers->capability('stripe') === null,
        'webhookAuthenticated' => is_string($token) && $connections->authenticate('asaas-sandbox', $token) === 'sandbox',
    ];
    try {
        $connections->authenticate('asaas-sandbox', 'invalid-readiness-probe-token');
        $checks['invalidWebhookRejected'] = false;
    } catch (Forbidden) {
        $checks['invalidWebhookRejected'] = true;
    }
    $ready = !in_array(false, $checks, true);
    echo json_encode(['checkedAt' => gmdate('c'), 'readyForProviderAcceptance' => $ready,
        'integrationId' => 'asaas-sandbox', 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($ready ? 0 : 1);
} catch (Throwable) {
    // Never dump an exception/trace, config or provider headers from a secret-bearing process.
    fwrite(STDERR, "Sandbox readiness failed: verify the deployed connection references and Secret bindings.\n");
    exit(1);
}
