<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\OperationHistoryQuery;
use Espo\Modules\FeatureCredits\Pricing\AiModelPolicy;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/** Opt-in disposable-DB HTTP bridge for the real backend conversation test.
 * No application route, production configuration or financial implementation. */
trait ConversationCallerCases
{
    #[DataProvider('dialects')]
    public function testRealConversationCallerBridge(string $dialect): void
    {
        if (getenv('FEATURE_CREDITS_CALLER_BRIDGE') !== '1') {
            $this->markTestSkipped('Opt-in caller bridge requires the backend acceptance client.');
        }
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $this->reservations($pdo);
            $pdo->exec("INSERT INTO tenant_credit_billing_rate
                (id, tenant_id, version, effective_from, currency, credit_unit_price, monthly_credits, created_at)
                VALUES ('other-rate', 'other', 'v1', '2026-10-01 00:00:00', 'USD', '1.00000000', '0.0000', '2026-10-01 00:00:00')");
            (new Funding($this->lock($pdo)))->grant($this->input());
            foreach (['tenant', 'other'] as $tenant) {
                $this->cutovers($pdo)->schedule(new CutoverInput($tenant, $this->now, (object) ['testFixture' => true]));
            }
            // Explicit synthetic test pricing, never a production approval.
            $entry = ['provider' => 'google', 'model' => 'gemini-3.8-flash', 'multiplier' => '1',
                'inputTokenBound' => 10000, 'outputTokenLimit' => 1024, 'boundProfile' => 'gemini38-flash-medium-function-count-v1'];
            $id = (new AiModelPolicy($entry))->rate->id;
            $pdo->prepare('INSERT INTO ai_model_credit_rate (id, name, policy_id, definition, created_at) VALUES (?,?,?,?,?)')
                ->execute([substr($id, 0, 17), 'main', $id, json_encode($entry), $this->now]);
            $pdo->prepare('UPDATE tenant_credit_billing_rate SET ai_model_credit_rate_id=?, ai_max_requests=4')
                ->execute([substr($id, 0, 17)]);
            $native = getenv('FEATURE_CREDITS_CALLER_NATIVE') === '1';
            $catalog = [$id => $entry];
            if ($native) {
                $helper = [...$entry, 'outputTokenLimit' => 2048, 'boundProfile' => \Espo\Modules\FeatureCredits\Accounting\NativeSearchDebt::PROFILE];
                $helperId = (new AiModelPolicy($helper))->rate->id;
                $pdo->prepare('INSERT INTO ai_model_credit_rate (id, name, policy_id, definition, created_at) VALUES (?,?,?,?,?)')
                    ->execute([substr($helperId, 0, 17), 'native', $helperId, json_encode($helper), $this->now]);
                $pdo->prepare('UPDATE tenant_credit_billing_rate SET ai_native_search_model_credit_rate_id=?')->execute([substr($helperId, 0, 17)]);
                $catalog[$helperId] = $helper;
            }
            $server = stream_socket_server('tcp://0.0.0.0:18880', $errno, $error);
            $this->assertNotFalse($server, $error);
            $finished = false;
            try {
                while (!$finished && ($client = stream_socket_accept($server, 90))) {
                    stream_set_timeout($client, 15);
                    $line = fgets($client);
                    $path = explode(' ', trim($line))[1];
                    $headers = [];
                    while (($line = fgets($client)) !== false && trim($line) !== '') {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[strtolower($name)] = trim($value);
                    }
                    $raw = ''; $length = (int) ($headers['content-length'] ?? 0);
                    $this->assertLessThanOrEqual(65536, $length);
                    while (strlen($raw) < $length) $raw .= fread($client, $length - strlen($raw));
                    $status = 200;
                    try {
                        if ($path === '/fixture') {
                            $result = ['modelRateId' => $id, 'native' => $native];
                        } elseif ($path === '/views') {
                            $result = ['balance' => $this->balanceStatus($pdo)->inspect('tenant'),
                                'operations' => $this->operationHistory($pdo)->inspect(new OperationHistoryQuery('tenant')),
                                'debits' => (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn()];
                        } elseif ($path === '/stop') {
                            $finished = true; $result = ['stopped' => true];
                        } else {
                            $dispatch = $path === '/api/v1/CreditDispatch';
                            $this->assertContains($path, ['/api/v1/CreditExecution', '/api/v1/CreditDispatch']);
                            $domain = $dispatch ? 'credit-dispatch-v1' : 'credit-execution-v1';
                            $timestamp = $headers['x-credit-execution-timestamp'];
                            $this->assertSame('sha256=' . hash_hmac('sha256', "$domain.$timestamp.$raw", 'credit-test'),
                                $headers['x-credit-execution-signature']);
                            $command = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                            if ($dispatch) {
                                $fields = get_object_vars($command); unset($fields['operation']);
                                $result = ['operation' => $command->operation, 'result' => $this->dispatch($pdo, $command->operation, $fields)];
                            } else {
                                $result = $this->terminalCommand($pdo, $command, $catalog);
                            }
                        }
                    } catch (\Espo\Core\Exceptions\Conflict $e) {
                        $status = 409; $result = ['error' => $e->getMessage()];
                    }
                    $json = json_encode($result, JSON_THROW_ON_ERROR);
                    fwrite($client, "HTTP/1.1 $status OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n$json");
                    fclose($client);
                }
                $this->assertTrue($finished, 'Backend client did not complete acceptance before the bridge deadline.');
                $this->assertSame($native ? 4 : 1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
                $this->assertConsistent($pdo, $native ? '9.9606' : '9.9906');
                $this->assertHeld($pdo, '0.0000');
            } finally {
                fclose($server);
            }
        });
    }
}
