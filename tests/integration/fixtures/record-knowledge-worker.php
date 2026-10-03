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
} elseif ($argv[3] === 'predicate') {
    try {
        $container->get('entityManager')->createEntity('RecordPredicate', (array) json_decode(base64_decode($argv[4])));
    } catch (\Espo\Core\Exceptions\Conflict) {
        // A deterministic collision is the expected result for concurrent retries.
    }
} elseif ($argv[3] === 'first-use') {
    $input = json_decode(base64_decode($argv[4]));
    try {
        if ($argv[5] === '0') {
            $predicate = $container->get('entityManager')->getEntityById('RecordPredicate', $input->predicateId);
            $predicate->set('objectTypes', ['Contact']);
            $container->get('entityManager')->saveEntity($predicate);
        } else {
            $factory->create(\Espo\Modules\FeatureRecordKnowledge\Services\Relations::class)->submit($input->proposal);
        }
    } catch (\Espo\Core\Exceptions\Conflict|\Espo\Core\Exceptions\BadRequest) {}
} else {
    $factory->create(\Espo\Modules\FeatureRecordKnowledge\Services\Relations::class)->submit(json_decode(base64_decode($argv[4])));
}
