<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Proving a WhatsApp number: the person sends a code FROM that number to the Avisos number (a wa.me link with the text
 * ready, or its QR code). The sender is the number, so nothing typed is trusted, and the message opens Meta's 24h
 * window. Used by Settings › Notificações no WhatsApp (`subscription`) and by self-service signup (`signup`).
 *
 * Codes are 8 characters from an alphabet without look-alikes, valid 15 minutes, used once. A number with 5 wrong
 * codes in an hour is ignored for the rest of the hour, so a code can't be guessed from a phone.
 */
class PhoneCodes
{
    public const SUBSCRIPTION = 'subscription';
    public const SIGNUP = 'signup';
    public const TTL_SECONDS = 900;
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const FAILS_PER_HOUR = 5;
    private const PATTERN = '/(?<![0-9A-Z])([2-9A-HJ-NP-Z]{4})-([2-9A-HJ-NP-Z]{4})(?![0-9A-Z])/';

    public function __construct(private EntityManager $em, private Settings $settings) {}

    /**
     * A new code; older unused ones for the same person and workspace (or signup) stop working.
     *
     * @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner
     * @return array{code: string, text: string, link: string, expiresAt: string}
     */
    public function issue(string $purpose, array $owner): array
    {
        foreach ($this->open($purpose, $owner) as $old) {
            $old->set(['usedAt' => gmdate('Y-m-d H:i:s'), 'code' => null]);
            $this->em->saveEntity($old);
        }
        $raw = '';
        for ($i = 0; $i < 8; $i++) $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4);
        $row = $this->em->createEntity('WhatsAppPhoneCode', [
            'purpose' => $purpose, 'codeHash' => hash('sha256', $code), 'code' => $code,
            'userId' => $owner['userId'] ?? null, 'chatwootAccountId' => $owner['chatwootAccountId'] ?? null,
            'signupId' => $owner['signupId'] ?? null, 'expiresAt' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ]);
        return $this->present($row);
    }

    /**
     * The code still waiting for its message, if any.
     *
     * @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner
     * @return ?array{code: string, text: string, link: string, expiresAt: string}
     */
    public function pending(string $purpose, array $owner): ?array
    {
        $row = $this->open($purpose, $owner)[0] ?? null;
        return $row ? $this->present($row) : null;
    }

    /** The code in a message, normalised ("7KQ2-M9XP"), or null. */
    public static function extract(string $text): ?string
    {
        return preg_match(self::PATTERN, mb_strtoupper($text), $m) ? "{$m[1]}-{$m[2]}" : null;
    }

    /** Whether a message meant to carry a code (our prefilled texts start this way), so a wrong one gets an answer. */
    public static function meant(string $text): bool
    {
        return (bool) preg_match('/^\s*(ativar|confirmar|activate|confirm)\b/iu', $text);
    }

    /**
     * Use a code sent from $phone: the code's row, now spent, or null when it is unknown, expired or used, or the number
     * has failed too often this hour.
     */
    public function redeem(string $code, string $phone): ?Entity
    {
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
        $fails = $this->em->getRDBRepository('WhatsAppNotification')
            ->where(['phone' => $phone, 'kind' => 'code_failed', 'createdAt>=' => $since])->count();
        if ($fails >= self::FAILS_PER_HOUR) return null;
        $row = $this->em->getRDBRepository('WhatsAppPhoneCode')->where([
            'codeHash' => hash('sha256', $code), 'usedAt' => null, 'expiresAt>' => gmdate('Y-m-d H:i:s'),
        ])->findOne();
        if (!$row) {
            $this->em->createEntity('WhatsAppNotification', [
                'direction' => 'in', 'kind' => 'code_failed', 'phone' => $phone, 'sendKey' => 'code_failed:' . bin2hex(random_bytes(10)),
            ]);
            return null;
        }
        $row->set(['usedAt' => gmdate('Y-m-d H:i:s'), 'phone' => $phone, 'code' => null]);
        $this->em->saveEntity($row);
        return $row;
    }

    /** @return array{code: string, text: string, link: string, expiresAt: string} */
    private function present(Entity $row): array
    {
        $code = (string) $row->get('code');
        $text = ($row->get('purpose') === self::SIGNUP ? 'Confirmar WhatsApp ' : 'Ativar Em dia ') . $code;
        return [
            'code' => $code, 'text' => $text,
            'link' => 'https://wa.me/' . preg_replace('/\D/', '', $this->settings->number()) . '?text=' . rawurlencode($text),
            'expiresAt' => gmdate(DATE_ATOM, (int) strtotime($row->get('expiresAt') . ' UTC')),
        ];
    }

    /**
     * @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner
     * @return list<Entity>
     */
    private function open(string $purpose, array $owner): array
    {
        $where = ['purpose' => $purpose, 'usedAt' => null, 'expiresAt>' => gmdate('Y-m-d H:i:s')];
        if ($purpose === self::SIGNUP) {
            $where['signupId'] = $owner['signupId'] ?? '';
        } else {
            $where['userId'] = $owner['userId'] ?? '';
            $where['chatwootAccountId'] = $owner['chatwootAccountId'] ?? '';
        }
        return iterator_to_array($this->em->getRDBRepository('WhatsAppPhoneCode')->where($where)->order('createdAt', 'DESC')->find());
    }
}
