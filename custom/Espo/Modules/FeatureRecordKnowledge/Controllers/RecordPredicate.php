<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureRecordKnowledge\Controllers;

use Espo\Core\Templates\Controllers\Base;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureRecordKnowledge\Services\Tenancy;
use Espo\Modules\FeatureRecordKnowledge\Services\PredicateRegistry;

class RecordPredicate extends Base
{
    public function putActionUpdate(Request $request, Response $response): \stdClass
    {
        $version = $request->getHeader('X-Version-Number');
        if ($version === null || !ctype_digit($version)) throw new BadRequest('X-Version-Number is required.');
        return parent::putActionUpdate($request, $response);
    }
    public function getActionContexts(Request $request): object
    {
        $tenancy = $this->injectableFactory->create(Tenancy::class);
        return (object) ['list' => $tenancy->contexts()];
    }
    public function getActionRegistry(Request $request): object
    {
        $tenancy = $this->injectableFactory->create(Tenancy::class);
        $id = $tenancy->select((string) $request->getQueryParam('tenantId'));
        return (object) ['tenantId' => $id, 'predicates' => (object) $this->injectableFactory->create(PredicateRegistry::class)->schema($id, false)];
    }
}
