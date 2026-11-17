<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Enrichment\Providers\Result;

/**
 * A Contact's enriched emails must be the contact's own. Suppliers return relatives', colleagues',
 * company and junk addresses beside them; a decision model reads each address against the contact
 * (name, birth year, company) and only the ones it places as the contact's own are kept. The rest
 * are dropped before anything is stored, so a lookup with no own address is "no match" and free.
 * Company (Account) emails are out of scope: a shared inbox is the expected answer there.
 *
 * Model and cutoff (benchmark, 2026-11-17): on 612 emails returned for 133 contacts, judged by
 * Claude Opus, Drex Max at 0.6 kept 5 of 248 wrong addresses and lost 5 of 332 own ones; the old
 * prefix list kept 209. Without the company and birth year it lost 18 own ones.
 */
class EmailOwners
{
    /** Contact email capability marker; changing it retires earlier unchecked sibling caches. */
    public const REVISION = 'contact-email-own-only-v1';
    public const MODEL = 'nace-ai/drex-max-20261016';

    private const QUESTION = 'De quem é este e-mail, em relação ao contato?';
    private const CRITERIA = [
        'proprio' => 'Endereço pessoal ou profissional do próprio contato: traz o nome, sobrenome, iniciais ou apelido dele, ou é o endereço nominal dele em uma empresa.',
        'empresa' => 'Caixa compartilhada ou de departamento de uma empresa (contato@, financeiro@, vendas@, adm@), não de uma pessoa.',
        'outra_pessoa' => 'Endereço de outra pessoa: traz um nome diferente do contato (parente, sócio, colega, contador).',
        'sem_relacao' => 'Endereço gerado por sistema, alias de marketplace, endereço de outro serviço, ou texto sem ligação visível com o contato.',
    ];
    private const CALL_TIMEOUT_MS = 8000;
    private const MINIMUM_CALL_MS = 1500;
    private const MAXIMUM_EMAILS = 50;

    public function __construct(private Decisions $decisions, private ?Log $log = null) {}

    /** The cutoff an address must reach as the contact's own to be kept. */
    public static function minimumProbability(): float
    {
        $value = getenv('ENRICHMENT_EMAIL_OWNER_MIN_PROBABILITY');
        return is_string($value) && is_numeric($value) && (float) $value >= 0.5 && (float) $value <= 1 ? (float) $value : 0.6;
    }

    /**
     * Applies to the requested emails and to emails retained as an unpurchased sibling.
     * Without the platform key nothing is checked. When the model gives no answer, requested emails
     * become a failed lookup (nothing bought) and sibling emails are not retained.
     *
     * @param callable(): array{name?: ?string, birthYear?: ?string, company?: ?string, website?: ?string} $subject
     *        who the emails are read against; called only when there is something to check
     */
    public function apply(Result $result, string $targetType, string $product, string $provider, callable $subject, int $budgetMs): Result
    {
        if ($targetType !== 'Contact' || !in_array($result->state, ['match', 'no_match'], true)) return $result;
        $requested = $product === 'email' && $result->values;
        if (!$requested && !isset($result->hidden['email'])) return $result;
        $key = $this->decisions->key();
        if ($key === '') return $result;
        $deadline = hrtime(true) + max(0, $budgetMs) * 1_000_000;
        try { $subject = $subject(); } catch (\Throwable) { $subject = []; }
        $hidden = $result->hidden;
        if ($requested) {
            $values = $this->own($key, $result->values, $provider, $subject, $deadline);
            if ($values === null) return new Result('failed');
        } else {
            $values = $result->values;
            $hidden['email'] = $this->own($key, $hidden['email'], $provider, $subject, $deadline) ?? [];
            if (!$hidden['email']) unset($hidden['email']);
        }
        return new Result($values ? 'match' : 'no_match', $values, hidden: $hidden,
            retentionSeconds: $result->retentionSeconds, dateOfBirth: $result->dateOfBirth);
    }

    /**
     * The contact's own addresses, most certain first; null when any address got no answer.
     *
     * @param list<string> $emails
     * @return ?list<string>
     */
    private function own(#[\SensitiveParameter] string $key, array $emails, string $provider, array $subject, int $deadline): ?array
    {
        $emails = array_values(array_filter($emails, 'is_string'));
        $name = trim((string) ($subject['name'] ?? ''));
        if ($name === '' || count($emails) > self::MAXIMUM_EMAILS) return $this->unanswered($provider, count($emails));
        $contact = ['nome' => $name];
        if (preg_match('/\A(19|20)\d\d\z/', (string) ($subject['birthYear'] ?? ''))) $contact['anoNascimento'] = $subject['birthYear'];
        $origin = $provider === 'apollo' ? 'perfil profissional (LinkedIn)' : 'cadastros vinculados ao CPF';
        $bodies = [];
        foreach ($emails as $index => $email) {
            $bodies[$index] = ['model' => self::MODEL,
                'state' => ['contato' => $contact,
                    'empresaDoContato' => ['nome' => $subject['company'] ?? null, 'site' => $subject['website'] ?? null],
                    'email' => strtolower($email), 'origem' => [$origin],
                    'outrosEmailsRetornados' => array_values(array_map('strtolower', array_diff_key($emails, [$index => true])))],
                'questions' => ['dono' => ['type' => 'choice', 'instructions' => self::QUESTION, 'criteria' => self::CRITERIA]],
                'provider' => ['data_collection' => 'deny']];
        }
        $probabilities = [];
        // One resend for requests the provider throttled or dropped.
        for ($try = 0; $try < 2 && $bodies; $try++) {
            $remaining = intdiv($deadline - hrtime(true), 1_000_000);
            if ($remaining < self::MINIMUM_CALL_MS) break;
            try {
                $answers = $this->decisions->ask($key, $bodies, min(self::CALL_TIMEOUT_MS, $remaining));
            } catch (\Throwable) {
                break;
            }
            foreach ($answers as $index => $response) {
                $probability = isset($bodies[$index]) && $response->status === 200 ? self::probability($response->data) : null;
                if ($probability === null) continue;
                $probabilities[$index] = $probability;
                unset($bodies[$index]);
            }
        }
        if ($bodies) return $this->unanswered($provider, count($bodies));
        $minimum = self::minimumProbability();
        $kept = array_filter($probabilities, static fn (float $probability): bool => $probability >= $minimum);
        arsort($kept, SORT_NUMERIC);
        $this->log?->info('Enrichment emails checked against the contact.', ['provider' => $provider,
            'returned' => count($emails), 'kept' => count($kept)]);
        return array_values(array_map(static fn (int $index): string => $emails[$index], array_keys($kept)));
    }

    /** The probability that the address is the contact's own; 0 for any other choice, null without a usable answer. */
    private static function probability(?array $body): ?float
    {
        $answer = $body['answers']['dono'] ?? null;
        $choice = is_array($answer) && ($answer['type'] ?? null) === 'choice' ? ($answer['choice'] ?? null) : null;
        if (!is_string($choice) || !isset(self::CRITERIA[$choice])) return null;
        $probability = $answer['probabilities'][$choice] ?? null;
        if (!is_int($probability) && !is_float($probability) || $probability < 0 || $probability > 1) return null;
        return $choice === 'proprio' ? (float) $probability : 0.0;
    }

    private function unanswered(string $provider, int $count): ?array
    {
        $this->log?->warning('Enrichment emails could not be checked against the contact.', ['provider' => $provider, 'emails' => $count]);
        return null;
    }
}
