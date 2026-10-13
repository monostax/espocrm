<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

/** Registro.br ICANN suffix rules from publicsuffix.org, retrieved 2026-10-12.
 * Suffix data: https://publicsuffix.org/list/public_suffix_list.dat (MPL-2.0).
 * No network resolution: the resulting name is sent only to the fixed RDAP host.
 */
final class RegistroBrDomain
{
    private const CATEGORIES = '9guacu abc adm adv agr aju am anani aparecida api app arq art ato b barueri belem bet bhz bib bio blog bmd boavista bsb campinagrande campinas caxias cim cng cnt com contagem coop coz cri cuiaba curitiba def des det dev ecn eco edu emp enf eng esp etc eti far feira flog floripa fm fnd fortal fot foz fst g12 geo ggf goiania gov gru ia imb ind inf jab jampa jdf joinville jor jus leg leilao lel log londrina macapa maceio manaus maringa mat med mil morena mp mus natal net niteroi not ntr odo ong org osasco palmas poa ppg pro psc psi pvh qsl radio rec recife rep ribeirao rio riobranco riopreto salvador sampa santamaria santoandre saobernardo saogonca seg sjc slg slz social sorocaba srv taxi tc tec teo the tmp trd tur tv udi vet vix vlog wiki xyz zlg';
    private const STATES = 'ac al am ap ba ce df es go ma mg ms mt pa pb pe pi pr rj rn ro rr rs sc se sp to';

    public static function normalize(mixed $website): ?string
    {
        if (!is_string($website) || strlen($website) > 2048) return null;
        $website = trim($website);
        if ($website === '' || preg_match('/[\\\\\x00-\x20\x7f]/', $website)) return null;
        $url = parse_url(str_contains($website, '://') ? $website : 'https://' . $website);
        if (!is_array($url) || !in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true) ||
            isset($url['user']) || isset($url['pass']) || !isset($url['host'])) return null;
        $host = strtolower(rtrim($url['host'], '.'));
        if (preg_match('/[^\x00-\x7f]/', $host)) {
            if (!function_exists('idn_to_ascii')) return null;
            $host = idn_to_ascii($host, IDNA_USE_STD3_RULES | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) return null;
        }
        if (strlen($host) > 253 || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+br\z/', $host)) return null;
        $labels = explode('.', $host); $count = count($labels);
        $second = $labels[$count - 2];
        $suffix = in_array($second, explode(' ', self::CATEGORIES), true) ? 2 : 1;
        if ($second === 'nom') $suffix = 3; // *.nom.br
        if ($second === 'gov' && $count >= 3 && in_array($labels[$count - 3], explode(' ', self::STATES), true)) $suffix = 3;
        return $count > $suffix ? implode('.', array_slice($labels, -($suffix + 1))) : null;
    }
}
