<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Api;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureRecordKnowledge\Services\Access;
use Espo\ORM\EntityManager;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Resolve company favicons without changing the opportunity's native account link. */
final class OpportunityAccountFavicon implements MiddlewareInterface
{
    public function __construct(private EntityManager $em, private Acl $acl, private Access $access) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = preg_replace('~^/api/v1~', '', $request->getUri()->getPath());
        if ($response->getStatusCode() !== 200 ||
            !preg_match('~^/(?:Opportunity(?:/[a-zA-Z0-9_-]+)?|(?:ChatwootConversation|Contact|Account)/[a-zA-Z0-9_-]+/opportunities)$~D', $path)) return $response;

        $data = json_decode((string) $response->getBody());
        $rows = $data->list ?? (isset($data->id) ? [$data] : []);
        if (!$rows) return $response;

        $this->populate($rows);

        return $response->withBody(Utils::streamFor(json_encode($data, JSON_THROW_ON_ERROR)))
            ->withoutHeader('Content-Length')->withHeader('Cache-Control', 'private, no-store');
    }

    private function populate(array $rows): void
    {
        foreach ($rows as $row) $row->accountFaviconWebsite = null;
        if (!$this->acl->checkScope('Account', 'read') || !$this->acl->checkField('Account', 'website') ||
            !$this->acl->checkField('Opportunity', 'accountWebsite')) return;

        $websites = [];
        $unresolved = [];
        foreach ($rows as $row) {
            if (!empty($row->accountId) && $this->acl->checkField('Opportunity', 'account')) {
                $row->accountFaviconWebsite = $this->website($row->accountId, $websites);
            }
            if (!$row->accountFaviconWebsite) $unresolved[$row->id] = $row;
        }
        if (!$unresolved) return;

        // One relation query per response, including links authored in either direction.
        $relations = $this->em->getRDBRepository('RecordRelation')->where([
            'status' => 'confirmed',
            'OR' => [
                ['subjectType' => 'Opportunity', 'subjectId' => array_keys($unresolved), 'objectType' => 'Account'],
                ['objectType' => 'Opportunity', 'objectId' => array_keys($unresolved), 'subjectType' => 'Account'],
            ],
        ])->order('id')->find();

        foreach ($relations as $relation) {
            $outgoing = $relation->get('subjectType') === 'Opportunity';
            $opportunityId = $relation->get($outgoing ? 'subjectId' : 'objectId');
            $row = $unresolved[$opportunityId] ?? null;
            if (!$row) continue;
            try {
                // Match the relationships UI's record, tenant, evidence and predicate access rules.
                $this->access->claim($relation);
                $accountId = $relation->get($outgoing ? 'objectId' : 'subjectId');
                $website = $this->website($accountId, $websites);
            } catch (Forbidden|NotFound|BadRequest) {
                continue;
            }
            if (!$website) continue;
            $row->accountFaviconWebsite = $website;
            unset($unresolved[$opportunityId]);
            if (!$unresolved) break;
        }
    }

    /** Cache readable accounts only for this response, never across viewers. */
    private function website(string $id, array &$websites): ?string
    {
        if (array_key_exists($id, $websites)) return $websites[$id];
        try {
            $website = trim((string) $this->access->record('Account', $id)->get('website'));
            return $websites[$id] = $website !== '' ? $website : null;
        } catch (Forbidden|NotFound|BadRequest) {
            return $websites[$id] = null;
        }
    }
}
