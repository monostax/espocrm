<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

/**
 * Zero-width steganographic codec for WhatsApp click-to-chat attribution.
 *
 * Encodes a short ASCII payload (an anonymousId) as invisible Unicode
 * characters embedded inside a visible pre-filled WhatsApp message. The
 * lead sends the message unmodified; the receiving pipeline (Chatwoot sync)
 * extracts the payload from the inbound text and joins the WhatsApp
 * conversation to the web click that minted the id — the "tintim.app"
 * technique.
 *
 * Wire format (interoperable with the scheme used by tintim et al.):
 *
 *   U+FEFF  <groups>  U+FEFF
 *
 * where <groups> is one group per payload character, separated by U+2060
 * (WORD JOINER), each group being the character's code point in
 * minimal-length binary with U+200B (ZWSP) = 0 and U+200C (ZWNJ) = 1.
 *
 * Character choice rationale: ZWSP/ZWNJ/WJ are legitimate typographic
 * characters that WhatsApp cannot strip without corrupting Persian/Arabic/
 * Indic text and emoji sequences; U+FEFF delimits the region and is only
 * safe mid-text (it is BOM/whitespace-ish at string edges — hence
 * embed() inserts before the LAST visible character, never appends).
 *
 * Client-side mirror: encodeInvisible()/embedInvisible() in tracker.js —
 * keep the two implementations in sync.
 */
final class ZeroWidthCodec
{
    public const MARKER = "\u{FEFF}"; // region delimiter
    public const ZERO = "\u{200B}";   // bit 0 (zero width space)
    public const ONE = "\u{200C}";    // bit 1 (zero width non-joiner)
    public const SEP = "\u{2060}";    // char separator (word joiner)

    /** Payloads are ids, not documents. */
    public const MAX_PAYLOAD_LENGTH = 64;

    /** printable ASCII, no space — the id alphabet superset. */
    private const PAYLOAD_PATTERN = '/^[\x21-\x7E]{1,64}$/';

    /** MARKER( bits/seps ){n}MARKER — bounded so a hostile message cannot
     * make the regex engine chew megabytes (64 chars * 8 bits + seps). */
    private const EXTRACT_PATTERN = '/\x{FEFF}([\x{200B}\x{200C}\x{2060}]{1,640})\x{FEFF}/u';

    private function __construct()
    {
    }

    /**
     * Encode a payload into an invisible character run (markers included).
     * Returns null when the payload is not embeddable (non-ASCII/too long).
     */
    public static function encode(string $payload): ?string
    {
        if (preg_match(self::PAYLOAD_PATTERN, $payload) !== 1) {
            return null;
        }

        $groups = [];

        foreach (str_split($payload) as $char) {
            $groups[] = strtr(decbin(ord($char)), ['0' => self::ZERO, '1' => self::ONE]);
        }

        return self::MARKER . implode(self::SEP, $groups) . self::MARKER;
    }

    /**
     * Extract the first embedded payload from a message text, or null when
     * none is present or the region does not decode to a valid payload.
     * Never throws — inbound text is untrusted.
     */
    public static function extract(string $text): ?string
    {
        if (!str_contains($text, self::MARKER)) {
            return null; // fast path: virtually every message
        }

        if (preg_match(self::EXTRACT_PATTERN, $text, $matches) !== 1) {
            return null;
        }

        $groups = explode(self::SEP, $matches[1]);

        if (count($groups) > self::MAX_PAYLOAD_LENGTH) {
            return null;
        }

        $payload = '';

        foreach ($groups as $group) {
            $bits = strtr($group, [self::ZERO => '0', self::ONE => '1']);

            // Anything left over means a foreign/corrupted region.
            if ($bits === '' || strlen($bits) > 8 || preg_match('/^[01]+$/', $bits) !== 1) {
                return null;
            }

            $payload .= chr((int) bindec($bits));
        }

        return preg_match(self::PAYLOAD_PATTERN, $payload) === 1 ? $payload : null;
    }

    /**
     * Embed a payload into a visible message text, inserted before the last
     * character so leading/trailing trimming can never eat the region
     * (tintim does the same: "conversa<payload>s"). Returns the text
     * unchanged when it is too short to embed mid-text or the payload is
     * not encodable.
     */
    public static function embed(string $text, string $payload): string
    {
        $encoded = self::encode($payload);

        if ($encoded === null || mb_strlen($text) < 2) {
            return $text;
        }

        $cut = mb_strlen($text) - 1;

        return mb_substr($text, 0, $cut) . $encoded . mb_substr($text, $cut);
    }
}
