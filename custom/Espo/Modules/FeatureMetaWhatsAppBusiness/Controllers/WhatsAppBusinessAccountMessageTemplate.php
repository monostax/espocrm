<?php

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\TemplateAccess;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppBusinessAccountMessageTemplate as Service;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateFailure;
use stdClass;

/** Explicit remote actions only. No generic local create/update/delete or link writes. */
class WhatsAppBusinessAccountMessageTemplate
{
    public function __construct(
        private Service $service,
        private TemplateAccess $access,
        private Log $log,
        private User $user,
    ) {}

    public function getActionIndex(Request $request, Response $response): stdClass
    {
        return $this->execute('list', $request, $response);
    }

    public function getActionListPage(Request $request, Response $response): stdClass
    {
        return $this->execute('listPage', $request, $response);
    }

    public function getActionRead(Request $request, Response $response): stdClass
    {
        return $this->execute('read', $request, $response);
    }

    public function postActionCreateTemplate(Request $request, Response $response): stdClass
    {
        return $this->execute('create', $request, $response);
    }

    public function postActionUpdateTemplate(Request $request, Response $response): stdClass
    {
        return $this->execute('update', $request, $response);
    }

    public function postActionDeleteTemplate(Request $request, Response $response): stdClass
    {
        return $this->execute('delete', $request, $response);
    }

    private function execute(string $action, Request $request, Response $response): stdClass
    {
        $start = hrtime(true);
        $deadline = new TemplateDeadline();
        $correlation = bin2hex(random_bytes(12));
        $response->setHeader('X-Correlation-Id', $correlation);
        $input = [];
        $samplePath = null;
        $context = null;
        $outcome = 'failed';
        $metaCode = $metaSubcode = null;
        try {
            if ($request->getMethod() === 'GET') {
                $input = $request->getQueryParams();
            } elseif (str_starts_with(strtolower($request->getHeader('Content-Type') ?? ''), 'multipart/form-data')) {
                // PHP consumes multipart input before RequestWrapper; php://input can be empty.
                $payload = $_POST['payload'] ?? '';
                $input = is_string($payload) ? json_decode($payload, true) : null;
                if (!is_array($input) || array_is_list($input)) {
                    throw new BadRequest('Multipart payload must be a JSON object. Check the server upload limit.');
                }
                if (array_diff(array_keys($_FILES), ['sample'])) {
                    throw new BadRequest('Only one sample file is accepted.');
                }
                $file = $_FILES['sample'] ?? null;
                if ($file) {
                    $path = $file['tmp_name'] ?? null;
                    if (is_string($path) && is_uploaded_file($path)) {
                        $samplePath = $path;
                    }
                    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !$samplePath) {
                        throw new BadRequest('Sample upload failed. Check file size and server upload limits.');
                    }
                }
            } else {
                $input = json_decode(json_encode($request->getParsedBody(), JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
            }
            if ($action !== 'read') {
                $context = $this->access->resolve($input, $deadline);
            }
            if ($action === 'read') {
                $parts = explode('_', $request->getRouteParam('id') ?? '', 2);
                if (count($parts) !== 2 || !$parts[0] || !$parts[1]) {
                    throw new BadRequest('Invalid composite template ID.');
                }
                if (!empty($input['oAuthAccountId']) && $input['oAuthAccountId'] !== $parts[0]) {
                    throw new BadRequest('Conflicting OAuth context.');
                }
                if (empty($input['credentialId'])) {
                    $input['oAuthAccountId'] = $parts[0];
                }
                $context = $this->access->resolve($input, $deadline);
                if ($context['oAuthAccountId'] !== $parts[0]) {
                    throw new Forbidden('Template belongs to a different connection.');
                }
                $input['templateId'] = $parts[1];
                $result = $this->service->read($context['oAuthAccountId'], $context['businessAccountId'], $parts[1], $deadline);
            } elseif ($action === 'list') {
                $records = $this->service->find($context['oAuthAccountId'], $context['businessAccountId'], $deadline);
                $result = (object) ['list' => $records->getValueMapList(), 'total' => $records->getTotal()];
            } else {
                // Credential access/restrictions were checked above. Resolve its secrets only once.
                $serviceInput = $input;
                unset($serviceInput['credentialId'], $serviceInput['wabaId']);
                $serviceInput['oAuthAccountId'] = $context['oAuthAccountId'];
                $serviceInput['businessAccountId'] = $context['businessAccountId'];
                $result = $action === 'listPage'
                    ? $this->service->listPage($serviceInput, $deadline)
                    : $this->service->mutate($action, $serviceInput, $samplePath, $deadline);
            }
            if (isset($result->templateId)) {
                $input['templateId'] = $result->templateId;
            }
            $outcome = isset($result->accepted) ? 'accepted' : 'success';
            return $result;
        } catch (TemplateFailure $e) {
            $outcome = $e->kind;
            $metaCode = $e->metaCode;
            $metaSubcode = $e->metaSubcode;
            $response->setStatus($e->httpStatus);
            return (object) ['error' => $e->kind, 'message' => $e->getMessage(), 'correlationId' => $correlation,
                'metaCode' => $metaCode, 'metaSubcode' => $metaSubcode];
        } catch (BadRequest|Forbidden|NotFound $e) {
            [$status, $outcome] = match (true) {
                $e instanceof Forbidden => [403, 'access'],
                $e instanceof NotFound => [404, 'missing'],
                default => [400, 'validation'],
            };
            $response->setStatus($status);
            return (object) ['error' => $outcome, 'message' => $e->getMessage(), 'correlationId' => $correlation];
        } catch (\Throwable) {
            $response->setStatus(500);
            return (object) ['error' => 'internal', 'message' => 'Template operation failed.', 'correlationId' => $correlation];
        } finally {
            if ($samplePath && is_file($samplePath)) {
                unlink($samplePath);
            }
            $ids = [];
            if ($context) {
                $input['oAuthAccountId'] = $context['oAuthAccountId'];
                $input['businessAccountId'] = $context['businessAccountId'];
            }
            foreach (['credentialId', 'oAuthAccountId', 'businessAccountId', 'wabaId', 'templateId'] as $field) {
                // Log only bounded identifiers, not arbitrary client strings or payloads.
                $value = $input[$field] ?? null;
                $ids[$field] = is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) ? $value : null;
            }
            $this->log->info('WhatsApp template operation', $ids + [
                'actor' => $this->user->getId(), 'action' => $action, 'outcome' => $outcome,
                'durationMs' => (int) ((hrtime(true) - $start) / 1e6), 'correlationId' => $correlation,
                'metaCode' => $metaCode, 'metaSubcode' => $metaSubcode,
            ]);
        }
    }
}
