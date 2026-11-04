<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

/**
 * Server copy of the dashboard's groupEnrichmentEmails (enrichmentEmails.js), so bulk application
 * picks the same "best" email and leaves out the same shared inboxes as the single-record review.
 */
final class EnrichmentEmails
{
    private const GENERIC = ['admin', 'administracao', 'atendimento', 'billing', 'cobranca', 'comercial', 'compras',
        'contabilidade', 'contabil', 'contato', 'contatos', 'contact', 'customer', 'dominios', 'domains',
        'finance', 'financeiro', 'financial', 'fiscal', 'help', 'hello', 'info', 'invoice', 'invoices',
        'juridico', 'marketing', 'newsletter', 'noreply', 'office', 'recepcao',
        'recrutamento', 'recursos', 'humanos', 'revista', 'rh', 'sac', 'sales', 'secretaria',
        'suporte', 'support', 'vendas', 'webmaster'];

    /**
     * Personal emails, name matches first, without shared inboxes or duplicates.
     *
     * @param list<string> $values
     * @return list<string>
     */
    public static function personal(array $values, string $contactName): array
    {
        $names = array_values(array_filter(preg_split('/[^a-z]+/', self::fold($contactName)) ?: [],
            static fn (string $name): bool => strlen($name) > 2 && !in_array($name, ['das', 'dos'], true)));
        $aliases = [];
        foreach ($names as $name) {
            $aliases[] = $names[0] . $name;
            $aliases[] = $names[0][0] . $name;
        }
        $rows = [];
        foreach ($values as $value) {
            $key = strtolower(trim($value));
            if ($key === '' || isset($rows[$key])) continue;
            $local = explode('+', explode('@', self::fold($key))[0])[0];
            $tokens = array_values(array_filter(preg_split('/[^a-z]+/', $local) ?: [], 'strlen'));
            $matches = (bool) array_intersect($tokens, $names) || in_array(implode('', $tokens), $aliases, true);
            $generic = !$matches && ((bool) array_intersect($tokens, self::GENERIC) ||
                in_array(implode('', $tokens), self::GENERIC, true));
            if (!$generic) $rows[$key] = ['value' => trim($value), 'matches' => $matches];
        }
        // Stable: name matches first, otherwise supplier order.
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => (int) $b['matches'] - (int) $a['matches']);
        return array_column($rows, 'value');
    }

    private static function fold(string $value): string
    {
        $ascii = \Normalizer::normalize($value, \Normalizer::FORM_D);
        return strtolower(preg_replace('/\p{Mn}+/u', '', $ascii === false ? $value : $ascii) ?? $value);
    }
}
