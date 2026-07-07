<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Classes\FieldSanitizers;

use Espo\Core\FieldSanitize\Sanitizer;
use Espo\Core\FieldSanitize\Sanitizer\Data;

/**
 * IRI -> URI normalization for url fields: percent-encodes every
 * non-printable-ASCII character (and spaces) in the submitted value.
 *
 * Why: users paste URLs with raw accented characters in query params —
 * e.g. wa.me/api.whatsapp.com targets with a pre-filled Portuguese
 * `text=Olá!...`. Core url-field validation (UrlType::checkValid and the
 * client regExp, both bound to the ASCII-only `uriOptionalProtocol`
 * pattern) rejects those outright with a bare "{field} is invalid".
 * Sanitizers run on API input BEFORE field validation, so encoding here
 * makes the human-friendly paste just work — and the stored value becomes
 * a valid ASCII URL, which the redirect endpoint can emit verbatim in a
 * Location header (RFC-compliant: header values must not carry raw
 * non-ASCII).
 *
 * Already-encoded input is untouched: only bytes outside \x21-\x7E are
 * encoded; existing %XX sequences, +, ! etc. pass through as-is.
 *
 * Wired via entityDefs `sanitizerClassNameList` (TrackingLink.targetUrl).
 * Client mirror: views/tracking-link/fields/target-url.js (fetch()).
 */
class AsciiUrl implements Sanitizer
{
    public function sanitize(Data $data, string $field): void
    {
        if (!$data->has($field)) {
            return;
        }

        $value = $data->get($field);

        if (!is_string($value) || $value === '') {
            return;
        }

        $encoded = preg_replace_callback(
            '/[^\x21-\x7E]/u',
            static fn (array $matches) => rawurlencode($matches[0]),
            trim($value),
        );

        if (!is_string($encoded)) {
            // Invalid UTF-8: leave as-is; field validation reports it.
            return;
        }

        $data->set($field, $encoded);
    }
}
