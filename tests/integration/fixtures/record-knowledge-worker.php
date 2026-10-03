<?php
declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';
chdir($argv[2]);
set_include_path($argv[2]);
$app = new \Espo\Core\Application(new \Espo\Core\Application\ApplicationParams(noErrorHandler: true));
$app->setupSystemUser();
$container = $app->getContainer();
$factory = $container->get('injectableFactory');
if ($argv[3] === 'ensure') {
    $record = $container->get('entityManager')->getEntityById($argv[4], $argv[5]);
    $factory->create(\Espo\Modules\FeatureRecordKnowledge\Services\Overviews::class)->ensure($record);
} else {
    $factory->create(\Espo\Modules\FeatureRecordKnowledge\Services\Relations::class)->submit(json_decode(base64_decode($argv[4])));
}
