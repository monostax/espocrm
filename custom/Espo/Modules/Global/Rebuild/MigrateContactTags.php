<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Contact and Account called their CrmTag link `crmTags`; it is now `tags`, as on every other tagged record, and the
 * free-text list that held Contact.tags before (a multiEnum) is gone.
 *
 * Each old free-text value becomes a team tag in the contact's workspace that applies to contacts only, reusing a team
 * tag of the same name (the column collation already matches case and accents), and is linked to the contact. The old column is then cleared,
 * so a tag someone removes later never comes back on the next rebuild. The column itself stays (a soft rebuild never
 * drops it). Saved filters that named `crmTags` are renamed too.
 */
class MigrateContactTags implements RebuildAction
{
    private const NAME_LENGTH = 100;

    /** @var array<string, ?Entity> */
    private array $tags = [];
    /** @var array<string, list<string>> */
    private array $teams = [];

    public function __construct(private EntityManager $em, private Log $log) {}

    public function process(): void
    {
        $this->freeTextTags();
        $this->savedFilters();
    }

    private function savedFilters(): void
    {
        $pdo = $this->em->getPDO();
        $rows = $pdo->query("SELECT id, definition FROM record_filter
            WHERE entity_type IN ('Account', 'Contact') AND definition LIKE '%crmTags%'")->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare('UPDATE record_filter SET definition = ? WHERE id = ?');
        foreach ($rows as $row) {
            $definition = json_decode($row['definition']);
            if (!is_object($definition) || !is_array($definition->conditions ?? null)) continue;
            foreach ($definition->conditions as $condition) {
                if (!is_object($condition)) continue;
                if (($condition->attributeKey ?? null) === 'crmTags') $condition->attributeKey = 'tags';
                if (($condition->attributeKey ?? null) === 'recordQuery' && is_string($condition->values ?? null)) {
                    $condition->values = preg_replace('/\bcrmTags\b/', 'tags', $condition->values);
                }
            }
            $update->execute([json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['id']]);
        }
    }

    private function freeTextTags(): void
    {
        $pdo = $this->em->getPDO();
        if (!$pdo->query("SHOW COLUMNS FROM `contact` LIKE 'tags'")->fetch()) return;
        $rows = $pdo->query("SELECT id, tenant_id, tags FROM contact
            WHERE deleted = 0 AND tenant_id IS NOT NULL AND tags IS NOT NULL AND tags NOT IN ('', '[]', 'null')")
            ->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        $clear = $pdo->prepare('UPDATE contact SET tags = NULL WHERE id = ?');
        $contacts = $links = 0;
        foreach ($rows as $row) {
            try {
                $links += $this->em->getTransactionManager()->run(function () use ($row, $clear): int {
                    $linked = $this->migrate($row['id'], $row['tenant_id'], $this->names($row['tags']));
                    $clear->execute([$row['id']]);
                    return $linked;
                });
                $contacts++;
            } catch (Throwable $e) {
                $this->log->error("MigrateContactTags: contact {$row['id']} kept its old tags: " . $e->getMessage());
            }
        }
        $this->log->info("MigrateContactTags: $contacts contacts, $links tag links added.");
        // The free-text field kept a search copy of its values (storeArrayValues).
        $pdo->exec("DELETE FROM array_value WHERE entity_type = 'Contact' AND attribute = 'tags'");
    }

    /** @param list<string> $names */
    private function migrate(string $contactId, string $tenantId, array $names): int
    {
        $contact = $this->em->getEntityById('Contact', $contactId);
        if (!$contact || !$names) return 0;
        $relation = $this->em->getRDBRepository('Contact')->getRelation($contact, CrmTags::FIELD);
        $linked = 0;
        foreach ($names as $name) {
            $tag = $this->tag($tenantId, $name);
            if (!$tag || $relation->isRelated($tag)) continue;
            $relation->relate($tag);
            $linked++;
        }
        return $linked;
    }

    private function tag(string $tenantId, string $name): ?Entity
    {
        $key = $tenantId . "\0" . mb_strtolower($name);
        if (array_key_exists($key, $this->tags)) return $this->tags[$key];
        $tag = $this->em->getRDBRepository('CrmTag')
            ->where(['tenantId' => $tenantId, 'name' => $name, 'visibility' => 'team'])->findOne();
        if (!$tag && $teams = $this->tenantTeams($tenantId)) {
            $tag = $this->em->createEntity('CrmTag', [
                'name' => $name, 'tenantId' => $tenantId, 'visibility' => 'team', 'teamsIds' => $teams,
                'appliesTo' => ['Contact'],
            ]);
            // During a rebuild the save above validates teamsIds but does not store them.
            $relation = $this->em->getRDBRepository('CrmTag')->getRelation($tag, 'teams');
            foreach ($teams as $teamId) {
                if (!$relation->isRelatedById($teamId)) $relation->relateById($teamId);
            }
        }
        return $this->tags[$key] = $tag;
    }

    /** @return list<string> Team tags are shared through the workspace's base team, like the ones made in the app. */
    private function tenantTeams(string $tenantId): array
    {
        if (isset($this->teams[$tenantId])) return $this->teams[$tenantId];
        $tenant = $this->em->getEntityById('Tenant', $tenantId);
        return $this->teams[$tenantId] = $tenant instanceof CoreEntity ? $tenant->getLinkMultipleIdList('teams') : [];
    }

    /** @return list<string> */
    private function names(string $raw): array
    {
        $names = [];
        foreach ((array) json_decode($raw, true) as $value) {
            if (!is_scalar($value)) continue;
            $name = trim(str_replace(['<', '>', '='], ' ', preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
            $name = trim(mb_substr($name, 0, self::NAME_LENGTH));
            if ($name !== '') $names[mb_strtolower($name)] = $name;
        }
        return array_values($names);
    }
}
