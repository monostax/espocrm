<?php

declare(strict_types=1);

$database = getenv('TEST_DATABASE_NAME') ?: '';
if (!str_starts_with($database, 'live_records_')) {
    http_response_code(503);
    exit;
}
require trim(file_get_contents('/tmp/live-records-native-runtime')) . '/bootstrap.php';
$_SERVER['SCRIPT_NAME'] = '/api/v1/index.php';
$_SERVER['SCRIPT_FILENAME'] = getcwd() . '/public/api/v1/index.php';
(new \Espo\Core\Application())->run(\Espo\Core\ApplicationRunners\Api::class);
