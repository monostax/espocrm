<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\Metadata;

use Espo\Core\Utils\Metadata\AdditionalBuilder;
use Espo\Modules\FeatureCredits\Classes\Api\CustomerResponse;
use Espo\Modules\FeatureCredits\Classes\Api\PrivateRecords;
use Espo\Modules\FeatureCredits\Classes\Record\PrivateOutput;
use stdClass;

/** Covers new accounting entities automatically, without changing ORM storage or service reads. */
final class CustomerBoundaries implements AdditionalBuilder
{
    public const CONTROLLERS = ['Credits', 'CreditWorkspace', 'CreditBalance', 'CreditHistory', 'CreditGrants',
        'CreditReservations', 'CreditRequests', 'CreditOperations', 'CreditOperationSource', 'CreditBilling',
        'CreditCheckout', 'CreditSubscriptions', 'CreditEnrichmentPreview', 'CreditEnrichmentQuotes',
        'CreditEnrichmentReview', 'CreditEnrichmentHistory', 'CreditEnrichmentFields', 'CreditEnrichmentApplication',
        'CreditPhoneVerification', 'AiUsage'];

    public function build(stdClass $data): void
    {
        $data->app ??= new stdClass();
        $data->app->metadata ??= new stdClass();
        $data->app->metadata->frontendHiddenPathList ??= [];
        $data->app->api ??= new stdClass();
        $data->app->api->controllerMiddlewareClassNameListMap ??= new stdClass();
        foreach ($data->scopes ?? new stdClass() as $type => $scope) {
            if (($scope->module ?? null) !== 'FeatureCredits' || !($scope->entity ?? false)) continue;
            $data->recordDefs ??= new stdClass();
            $data->recordDefs->$type ??= new stdClass();
            $defs = $data->recordDefs->$type;
            $defs->exportDisabled = true;
            $data->app->api->controllerMiddlewareClassNameListMap->$type = [PrivateRecords::class];
            $defs->outputFilterClassNameList = array_values(array_unique([
                ...($defs->outputFilterClassNameList ?? []), PrivateOutput::class,
            ]));
            foreach (['entityDefs', 'clientDefs', 'recordDefs'] as $section) {
                $data->app->metadata->frontendHiddenPathList[] = [$section, $type];
            }
        }
        $data->app->api ??= new stdClass();
        $data->app->api->controllerMiddlewareClassNameListMap ??= new stdClass();
        foreach (self::CONTROLLERS as $controller) {
            $map = $data->app->api->controllerMiddlewareClassNameListMap;
            $map->$controller = array_values(array_unique([CustomerResponse::class, ...($map->$controller ?? [])]));
        }
        foreach ($data->entityDefs->ChatwootAiAgentRun->fields ?? new stdClass() as $field => $defs) {
            $public = \Espo\Modules\FeatureAiUsage\Classes\Record\CustomerOutput::class;
            if ($public::allows($field) || $public::allows($field . 'Id') || $public::allows($field . 'Ids')) continue;
            $defs->exportDisabled = $defs->directAccessDisabled = $defs->filterDisabled = true;
            $data->app->metadata->frontendHiddenPathList[] = ['entityDefs', 'ChatwootAiAgentRun', 'fields', $field];
        }
    }
}
