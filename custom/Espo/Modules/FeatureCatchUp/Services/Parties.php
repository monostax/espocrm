<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;

/**
 * Who a contact or group is to the business: customer, partner, team, personal or automated. The label lives
 * on the Chatwoot contact as the "Em dia" list field, so people see and correct it in the contact panel. A
 * group is one contact per Chatwoot account, which keys a group by (account, group id). Labels are set only
 * when Em dia analyses a conversation the contact is in (the same model call as the verdict), and only into
 * an empty field: a person's choice is never overwritten.
 *
 * `catch_up_party` mirrors the labels Em dia has seen, so the Atendimento candidate list can leave personal,
 * automated and team contacts out without reading their chats. It is refreshed on every read and write, so a
 * correction made in Chatwoot reaches it the next time the conversation is read.
 */
class Parties
{
    public const KEY = 'em_dia_tipo';
    public const LABELS = [
        'customer' => 'Cliente',
        'partner' => 'Parceiro ou fornecedor',
        'team' => 'Equipe',
        'personal' => 'Pessoal',
        'automated' => 'Automático',
    ];

    public function __construct(
        private EntityManager $em,
        private ChatwootApiClient $chatwoot,
        private DataCache $cache,
        private Log $log,
    ) {}

    /** The class a Chatwoot contact payload carries, or null when nobody has labeled it. */
    public static function classOf(?array $contact): ?string
    {
        $label = $contact['custom_attributes'][self::KEY] ?? null;
        $class = is_string($label) ? array_search($label, self::LABELS, true) : false;
        return is_string($class) ? $class : null;
    }

    /**
     * Store the classes an analysis returned for the participants it was asked about. A label is written only
     * when the contact's field is still empty; one a person set meanwhile wins. Failures are logged: a label
     * sharpens later analyses and never blocks this one.
     *
     * @param list<array<string, mixed>> $participants the snapshot's participants
     * @param list<array<string, mixed>> $answers `[{id, class}]` from the analysis
     * @return array<string, string> contact id => the class now on the contact
     */
    public function store(array $participants, array $answers): array
    {
        $asked = [];
        foreach ($participants as $participant) {
            if (!empty($participant['classify'])) $asked[(string) $participant['id']] = $participant;
        }
        $stored = [];
        foreach ($answers as $answer) {
            $id = (string) ($answer['id'] ?? '');
            $class = (string) ($answer['class'] ?? '');
            if (!isset($asked[$id], self::LABELS[$class])) continue;
            try {
                $now = $this->write($asked[$id], $class);
                if ($now !== null) $stored[$id] = $now;
            } catch (\Throwable $e) {
                $this->log->warning("Em dia: could not label Chatwoot contact $id: " . $e->getMessage());
            }
        }
        return $stored;
    }

    /**
     * Mirror the labels a read just saw on these participants' contacts.
     *
     * @param list<array<string, mixed>> $participants
     */
    public function remember(array $participants): void
    {
        foreach ($participants as $participant) {
            $account = $this->em->getEntityById('ChatwootAccount', (string) ($participant['account'] ?? ''));
            if ($account && (int) $account->get('chatwootAccountId') === (int) ($participant['accountId'] ?? 0)) {
                $this->mirror($account, (int) $participant['id'], $participant['label'] ?? null);
            }
        }
    }

    /**
     * A person's choice from a card ("Não é cliente", "é da equipe"): it replaces whatever the field holds.
     * The caller has checked that the reader may open the conversation this participant came from.
     */
    public function set(array $participant, string $class): void
    {
        if (!isset(self::LABELS[$class])) throw new \InvalidArgumentException('Unknown class.');
        $account = $this->em->getEntityById('ChatwootAccount', (string) ($participant['account'] ?? ''));
        $platform = $account ? $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId')) : null;
        if (!$account || !$platform?->get('backendUrl') || !$account->get('apiKey')
            || (int) $account->get('chatwootAccountId') !== (int) ($participant['accountId'] ?? 0)) {
            throw new \RuntimeException('This contact cannot be labeled.');
        }
        [$url, $key, $accountId] = [(string) $platform->get('backendUrl'), (string) $account->get('apiKey'), (int) $account->get('chatwootAccountId')];
        $this->ensureDefinition($url, $key, $accountId);
        $this->chatwoot->updateContact($url, $key, $accountId, (int) $participant['id'], ['custom_attributes' => [self::KEY => self::LABELS[$class]]]);
        $this->mirror($account, (int) $participant['id'], $class);
    }

    /** @return ?string the class on the contact after the write */
    private function write(array $participant, string $class): ?string
    {
        $account = $this->em->getEntityById('ChatwootAccount', (string) ($participant['account'] ?? ''));
        $platform = $account ? $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId')) : null;
        if (!$account || !$platform?->get('backendUrl') || !$account->get('apiKey')) return null;
        [$url, $key, $accountId] = [(string) $platform->get('backendUrl'), (string) $account->get('apiKey'), (int) $account->get('chatwootAccountId')];
        // The participant came from this account's conversation; never label a contact in another account.
        if ($accountId !== (int) ($participant['accountId'] ?? 0)) return null;
        $this->ensureDefinition($url, $key, $accountId);
        $contactId = (int) $participant['id'];
        $current = $this->chatwoot->getContact($url, $key, $accountId, $contactId);
        $existing = self::classOf(is_array($current['payload'] ?? null) ? $current['payload'] : null);
        if ($existing !== null || !empty($current['payload']['custom_attributes'][self::KEY])) {
            $this->mirror($account, $contactId, $existing);
            return $existing;
        }
        $this->chatwoot->updateContact($url, $key, $accountId, $contactId, ['custom_attributes' => [self::KEY => self::LABELS[$class]]]);
        $this->mirror($account, $contactId, $class);
        return $class;
    }

    /** One row per (account, contact); the account's tenant owns it. */
    private function mirror(\Espo\ORM\Entity $account, int $contactId, ?string $class): void
    {
        if ($contactId <= 0 || !$account->get('tenantId')) return;
        $this->em->getPDO()->prepare(
            'INSERT INTO catch_up_party (id, deleted, tenant_id, chatwoot_account_id, chatwoot_contact_id, party_class, modified_at) '
            . 'VALUES (:id, 0, :tenant, :account, :contact, :class, UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE deleted = 0, party_class = VALUES(party_class), modified_at = VALUES(modified_at)'
        )->execute([
            'id' => substr(hash('sha256', $account->getId() . ':' . $contactId), 0, 17), 'tenant' => (string) $account->get('tenantId'),
            'account' => $account->getId(), 'contact' => $contactId, 'class' => isset(self::LABELS[(string) $class]) ? $class : null,
        ]);
    }

    /** Create the account's "Em dia" contact field once; Chatwoot's contact panel shows and edits it. */
    private function ensureDefinition(string $url, string $key, int $accountId): void
    {
        $cacheKey = 'catchUp/partyDefinition/' . $accountId;
        if ($this->cache->has($cacheKey)) return;
        foreach ($this->chatwoot->listCustomAttributeDefinitions($url, $key, $accountId, 'contact_attribute') as $definition) {
            if (($definition['attribute_key'] ?? null) === self::KEY) {
                $this->cache->store($cacheKey, true);
                return;
            }
        }
        $this->chatwoot->createCustomAttributeDefinition($url, $key, $accountId, [
            'attribute_display_name' => 'Em dia',
            'attribute_key' => self::KEY,
            'attribute_display_type' => 'list',
            'attribute_model' => 'contact_attribute',
            'attribute_values' => array_values(self::LABELS),
            'attribute_description' => 'Quem é este contato para a empresa. A IA preenche quando o Em dia analisa uma conversa; corrija se estiver errado.',
        ]);
        $this->cache->store($cacheKey, true);
    }
}
