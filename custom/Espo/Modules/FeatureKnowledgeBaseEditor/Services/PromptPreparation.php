<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\EntityManager;
use Espo\Tools\Email\Util;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;

class PromptPreparation
{
    public function __construct(private EntityManager $em, private Acl $acl,
        private SelectBuilderFactory $select, private EditorReferences $references) {}

    public function prepare(string $membershipId, array $bindings): string
    {
        $type = 'ChatwootAccountUserMembership';
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $membershipId)) throw new BadRequest('Invalid membership.');
        if (!$this->acl->checkScope($type, 'read') || !$this->acl->checkField($type, 'aiPrompt')) throw new Forbidden();
        $query = $this->select->create()->from($type)->withStrictAccessControl()->buildQueryBuilder()
            ->where(['id' => $membershipId])->build();
        $membership = $this->em->getRDBRepository($type)->clone($query)->findOne();
        if (!$membership || !$this->acl->checkEntityRead($membership)) throw new Forbidden();

        $state = $this->acl->checkField($type, 'aiPromptEditorState') ? $membership->get('aiPromptEditorState') : null;
        try { $refs = References::fromState($state); }
        catch (BadRequest) { $state = null; $refs = []; }
        if ($state) {
            $resolved = [];
            foreach ($this->references->resolve($refs) as $ref) $resolved[References::url($ref)] = $ref;
            foreach ($refs as $ref) {
                if ($ref['kind'] === 'context') $resolved[References::url($ref)] = $this->references->context($ref, $bindings);
            }
            $walk = function (array $node) use (&$walk, $resolved): string {
                if (($node['type'] ?? '') === 'crm-mention') {
                    $ref = $node['reference'];
                    return $this->describe($ref, $resolved[References::url($ref)] ?? null);
                }
                if (isset($node['text'])) return (string) $node['text'];
                if (($node['type'] ?? '') === 'linebreak') return "\n";
                $value = implode('', array_map($walk, $node['children'] ?? []));
                return $value . (($node['type'] ?? '') === 'tablecell' ? "\t" :
                    (in_array($node['type'] ?? '', ['paragraph', 'heading', 'quote', 'listitem', 'code', 'tablerow'], true) ? "\n" : ''));
            };
            return trim($walk(json_decode($state, true)['root']));
        }

        // Legacy HTML remains supported; portable references still undergo fresh ACL resolution.
        $html = (string) ($membership->get('aiPrompt') ?? '');
        if (!str_contains($html, '#crm-reference/')) return Util::stripHtml($html);
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">' . $html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $links = [];
        $records = [];
        foreach ($dom->getElementsByTagName('a') as $link) {
            if (!str_starts_with($link->getAttribute('href'), '#crm-reference/')) continue;
            $ref = References::fromUrl($link->getAttribute('href'));
            $links[] = [$link, $ref];
            if ($ref && $ref['kind'] === 'record') $records[References::url($ref)] = $ref;
        }
        $resolved = [];
        foreach ($this->references->resolve(array_values($records)) as $ref) $resolved[References::url($ref)] = $ref;
        foreach ($links as [$link, $ref]) {
            $result = $ref ? ($ref['kind'] === 'context' ? $this->references->context($ref, $bindings) : ($resolved[References::url($ref)] ?? null)) : null;
            $link->parentNode->replaceChild($dom->createTextNode($ref ? $this->describe($ref, $result) : '[Unavailable reference]'), $link);
        }
        return Util::stripHtml($dom->saveHTML());
    }

    private function describe(array $ref, ?array $result): string
    {
        if (!($result['available'] ?? false)) return $ref['kind'] === 'context'
            ? '[Unresolved context: ' . References::CONTEXT[$ref['key']] . ']' : '[Unavailable reference]';
        return $result['label'] . ' [' . $result['entityType'] . ':' . $result['recordId'] . ']';
    }
}
