<?php
/************************************************************************
 * Simple script runner for module lifecycle scripts
 * Usage: php run-module-script.php <script-file-path> <class-name>
 ************************************************************************/

if ($argc < 3) {
    echo "Usage: php run-module-script.php <script-file-path> <class-name>\n";
    exit(1);
}

$scriptPath = $argv[1];
$className = $argv[2];

if (!file_exists($scriptPath)) {
    echo "Error: Script file not found: $scriptPath\n";
    exit(1);
}

// Bootstrap EspoCRM
require_once 'bootstrap.php';

$app = new \Espo\Core\Application();
$container = $app->getContainer();

// Set up system user context for CLI operations
// This is necessary because some operations (like saving entities) require a user context
$entityManager = $container->get('entityManager');
$systemUser = $entityManager->getRepository('User')->where(['userName' => 'system'])->findOne();

if (!$systemUser) {
    // If no system user exists, try to get admin user
    $systemUser = $entityManager->getRepository('User')->where(['userName' => 'admin'])->findOne();
}

if ($systemUser) {
    $container->set('user', $systemUser);
}

// Include and run the script
require_once $scriptPath;

if (!class_exists($className)) {
    echo "Error: Class $className not found in $scriptPath\n";
    exit(1);
}

$script = new $className();

if (!method_exists($script, 'run')) {
    echo "Error: Class $className does not have a run() method\n";
    exit(1);
}

try {
    $script->run($container);
    echo "✓ Script executed successfully\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

