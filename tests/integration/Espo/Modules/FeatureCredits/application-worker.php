<?php

declare(strict_types=1);

// Keep the line protocol clean; the parent runtime reports existing installation deprecations.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
chdir($argv[1]); set_include_path($argv[1]); require 'bootstrap.php';
$system = new \Espo\Core\Application(new \Espo\Core\Application\ApplicationParams(noErrorHandler: true));
$system->setupSystemUser();
$user = $system->getContainer()->get('entityManager')->getEntityById('User',$argv[2]);
$app = new \Espo\Core\Application(new \Espo\Core\Application\ApplicationParams(noErrorHandler: true));
$app->getContainer()->set('user',$user);
$data = json_decode(base64_decode($argv[3]),false,8,JSON_THROW_ON_ERROR);
$class = match ($data->source ?? null) {
    'erase' => \Espo\Tools\DataPrivacy\Erasor::class,
    'field','rdap' => \Espo\Modules\FeatureCredits\Enrichment\FieldApplication::class,
    default => \Espo\Modules\FeatureCredits\Enrichment\CandidateApplication::class,
};
$service = $app->getContainer()->get('injectableFactory')->create($class);
fwrite(STDOUT,"ready\n");
try {
    if (($data->source ?? null) === 'erase') {
        $service->erase($data->type,$data->id,$data->fields); $result = ['result' => ['erased' => true]];
    } else {
        $input = isset($data->source) ? $data : new \Espo\Modules\FeatureCredits\Enrichment\ApplicationInput($data);
        $result = ['result' => $service->apply($input)];
    }
} catch (Throwable $e) { $result = ['error' => $e::class,'message' => $e->getMessage()]; }
fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR) . "\n");
