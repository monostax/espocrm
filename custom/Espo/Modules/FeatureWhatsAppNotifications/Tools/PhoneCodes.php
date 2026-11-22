<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBSelectBuilder;
use Throwable;

/**
 * Proving a phone number with a 6-digit code we send to it (the user, 2026-11-22: no more QR code or wa.me link).
 * The code goes as Meta's authentication template from the Avisos number (CodeWhatsApp); on request, "Receber por
 * SMS" sends one by Twilio Verify, which picks its own code, so either code proves the number. Used by self-service
 * signup (`signup`) and Settings › Notificações no WhatsApp (`subscription`).
 *
 * Only an HMAC of our code is stored. A code lasts 10 minutes (the template says so) and takes 5 wrong tries. Sends
 * are capped per person (a new code at most once a minute, 5 an hour), per number (5 an hour, 10 a day) and, for SMS,
 * per IP address, so the form can't be used to flood a number or to run up SMS costs.
 */
class PhoneCodes
{
    public const SUBSCRIPTION = 'subscription';
    public const SIGNUP = 'signup';
    public const TTL_SECONDS = 600;
    public const RESEND_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;
    private const SENDS_PER_OWNER_PER_HOUR = 5;
    private const SENDS_PER_PHONE_PER_HOUR = 5;
    private const SENDS_PER_PHONE_PER_DAY = 10;
    private const SMS_PER_IP_PER_HOUR = 5;

    public function __construct(
        private EntityManager $em,
        private Settings $settings,
        private CodeWhatsApp $whatsApp,
        private TwilioVerify $sms,
        private Log $log,
    ) {}

    /**
     * A new code to $phone by WhatsApp; older ones of the same person (or signup) stop working. When Meta refuses the
     * number outright, the code goes by SMS instead.
     *
     * @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner
     * @return array<string, mixed> what the screen shows (view)
     * @throws CodeRejected
     */
    public function send(string $purpose, array $owner, string $phone, ?string $ipAddress = null): array
    {
        $last = $this->latest($purpose, $owner);
        // A minute between codes to the same number; a corrected number ("Trocar número") needn't wait.
        if ($last && $last->get('phone') === $phone && self::time($last->get('sentAt')) + self::RESEND_SECONDS > time()) {
            throw new CodeRejected(CodeRejected::RESEND_TOO_SOON);
        }
        $hour = gmdate('Y-m-d H:i:s', time() - 3600);
        if ($this->rows([...$this->ownerWhere($purpose, $owner), 'createdAt>=' => $hour])->count() >= self::SENDS_PER_OWNER_PER_HOUR) {
            throw new CodeRejected(CodeRejected::RATE_LIMITED);
        }
        $this->assertPhoneBelowLimits($phone);

        $now = gmdate('Y-m-d H:i:s');
        foreach ($this->rows($this->openWhere($purpose, $owner))->find() as $old) {
            $old->set('usedAt', $now);
            $this->em->saveEntity($old);
        }
        $code = sprintf('%06d', random_int(0, 999999));
        $row = $this->em->createEntity('WhatsAppPhoneCode', [
            'purpose' => $purpose, 'phone' => $phone, 'attempts' => 0, 'sends' => 1, 'sentAt' => $now,
            'userId' => $owner['userId'] ?? null, 'chatwootAccountId' => $owner['chatwootAccountId'] ?? null,
            'signupId' => $owner['signupId'] ?? null, 'ipAddress' => $ipAddress !== null ? mb_substr($ipAddress, 0, 45) : null,
            'expiresAt' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ]);
        $row->set('codeHash', $this->hash($row, $code));
        $this->em->saveEntity($row);

        try {
            $row->set('waPhone', $this->whatsApp->send($phone, $code));
            $this->em->saveEntity($row);
        } catch (Throwable $e) {
            $this->log->warning("PhoneCodes: WhatsApp code {$row->getId()} failed, sending by SMS: " . $e->getMessage());
            $this->startSms($row);
        }
        return $this->view($row);
    }

    /**
     * "Receber por SMS": the open code's number gets a Twilio Verify SMS, once the WhatsApp one had time to arrive.
     *
     * @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner
     * @return array<string, mixed>
     * @throws CodeRejected
     */
    public function sms(string $purpose, array $owner, ?string $ipAddress = null): array
    {
        $row = $this->open($purpose, $owner) ?? throw new CodeRejected(CodeRejected::CODE_EXPIRED);
        $smsAt = $row->get('smsAt');
        $from = $smsAt ? self::time($smsAt) + self::RESEND_SECONDS : self::time($row->get('sentAt')) + $this->settings->smsAfterSeconds();
        if ($from > time()) throw new CodeRejected($smsAt ? CodeRejected::RESEND_TOO_SOON : CodeRejected::SMS_TOO_SOON);
        $this->assertPhoneBelowLimits((string) $row->get('phone'));
        if ($ipAddress !== null) {
            $since = gmdate('Y-m-d H:i:s', time() - 3600);
            if ($this->rows(['ipAddress' => $ipAddress, 'smsAt>=' => $since])->count() >= self::SMS_PER_IP_PER_HOUR) {
                throw new CodeRejected(CodeRejected::RATE_LIMITED);
            }
            $row->set('ipAddress', mb_substr($ipAddress, 0, 45));
        }
        $this->startSms($row);
        return $this->view($row);
    }

    /**
     * The code the person typed. Returns the proven number, as WhatsApp knows it when the WhatsApp send told us
     * (Brazil's mobile 9th digit): Avisos matches incoming messages by that number.
     *
     * @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner
     * @throws CodeRejected
     */
    public function check(string $purpose, array $owner, string $code): string
    {
        $row = $this->open($purpose, $owner) ?? throw new CodeRejected(CodeRejected::CODE_EXPIRED);
        if ((int) $row->get('attempts') >= self::MAX_ATTEMPTS) throw new CodeRejected(CodeRejected::TOO_MANY_ATTEMPTS);
        // Count the attempt before comparing, so failures always add up.
        $row->set('attempts', (int) $row->get('attempts') + 1);
        $this->em->saveEntity($row);

        $code = (string) preg_replace('/\D/', '', $code);
        $right = strlen($code) === 6 && hash_equals((string) $row->get('codeHash'), $this->hash($row, $code));
        if (!$right && $row->get('smsAt') && strlen($code) >= 4) $right = $this->sms->check((string) $row->get('phone'), $code);
        if (!$right) throw new CodeRejected(CodeRejected::INVALID_CODE);

        $row->set('usedAt', gmdate('Y-m-d H:i:s'));
        $this->em->saveEntity($row);
        return (string) ($row->get('waPhone') ?: $row->get('phone'));
    }

    /**
     * The code waiting to be typed, for the screen, or null.
     *
     * @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner
     * @return ?array<string, mixed>
     */
    public function pending(string $purpose, array $owner): ?array
    {
        $row = $this->open($purpose, $owner);
        return $row ? $this->view($row) : null;
    }

    /** "+55 11 91234-5678" for people to read. */
    public static function display(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);
        if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $digits, $m)) return "+55 {$m[1]} {$m[2]}-{$m[3]}";
        return $digits !== '' ? '+' . $digits : $phone;
    }

    /**
     * Where the code went and when the person may ask again: a new code after a minute, the SMS after
     * smsAfterSeconds (then a minute between SMS). The code itself never leaves here.
     *
     * @return array<string, mixed>
     */
    private function view(Entity $row): array
    {
        $at = fn (int $time) => gmdate(DATE_ATOM, $time);
        $sentAt = self::time($row->get('sentAt'));
        $smsAt = $row->get('smsAt') ? self::time($row->get('smsAt')) : null;
        return [
            'phone' => (string) $row->get('phone'),
            'display' => self::display((string) $row->get('phone')),
            'whatsApp' => (bool) $row->get('waPhone'),
            'sms' => $smsAt !== null,
            'smsAvailable' => $this->sms->available(),
            'sentAt' => $at($sentAt),
            'resendAt' => $at($sentAt + self::RESEND_SECONDS),
            'smsAt' => $at($smsAt !== null ? $smsAt + self::RESEND_SECONDS : $sentAt + $this->settings->smsAfterSeconds()),
            'expiresAt' => $at(self::time($row->get('expiresAt'))),
        ];
    }

    /** @throws CodeRejected */
    private function startSms(Entity $row): void
    {
        try {
            $sid = $this->sms->start((string) $row->get('phone'));
        } catch (Throwable $e) {
            $this->log->warning("PhoneCodes: SMS code {$row->getId()} failed: " . $e->getMessage());
            throw new CodeRejected(CodeRejected::SEND_FAILED);
        }
        $row->set(['smsAt' => gmdate('Y-m-d H:i:s'), 'smsSid' => $sid, 'sends' => (int) $row->get('sends') + 1]);
        $this->em->saveEntity($row);
    }

    /** @throws CodeRejected */
    private function assertPhoneBelowLimits(string $phone): void
    {
        $sends = function (int $seconds) use ($phone): int {
            $total = 0;
            foreach ($this->rows(['phone' => $phone, 'createdAt>=' => gmdate('Y-m-d H:i:s', time() - $seconds)])->find() as $row) {
                $total += max(1, (int) $row->get('sends'));
            }
            return $total;
        };
        if ($sends(3600) >= self::SENDS_PER_PHONE_PER_HOUR || $sends(86400) >= self::SENDS_PER_PHONE_PER_DAY) {
            throw new CodeRejected(CodeRejected::RATE_LIMITED);
        }
    }

    private function hash(Entity $row, string $code): string
    {
        return hash_hmac('sha256', 'phone-code:' . $row->getId() . ':' . $code, $this->settings->secret());
    }

    /** @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner */
    private function open(string $purpose, array $owner): ?Entity
    {
        return $this->rows($this->openWhere($purpose, $owner))->order('createdAt', 'DESC')->findOne();
    }

    /** @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner */
    private function latest(string $purpose, array $owner): ?Entity
    {
        return $this->rows($this->ownerWhere($purpose, $owner))->order('createdAt', 'DESC')->findOne();
    }

    /**
     * @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner
     * @return array<string, mixed>
     */
    private function openWhere(string $purpose, array $owner): array
    {
        return [...$this->ownerWhere($purpose, $owner), 'usedAt' => null, 'expiresAt>' => gmdate('Y-m-d H:i:s')];
    }

    /**
     * @param array{userId?: string, chatwootAccountId?: string, signupId?: string} $owner
     * @return array<string, mixed>
     */
    private function ownerWhere(string $purpose, array $owner): array
    {
        return $purpose === self::SIGNUP
            ? ['purpose' => $purpose, 'signupId' => $owner['signupId'] ?? '']
            : ['purpose' => $purpose, 'userId' => $owner['userId'] ?? '', 'chatwootAccountId' => $owner['chatwootAccountId'] ?? ''];
    }

    /** @param array<string, mixed> $where */
    private function rows(array $where): RDBSelectBuilder
    {
        return $this->em->getRDBRepository('WhatsAppPhoneCode')->where($where);
    }

    private static function time(mixed $value): int
    {
        return (int) strtotime((string) $value . ' UTC');
    }
}
