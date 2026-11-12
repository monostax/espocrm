<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Signup;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\PasswordHash;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\PhoneCodes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings as WhatsAppSettings;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Subscriptions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Email-and-password signup:
 *
 *   begin   → a pending signup and a 6-digit code by email
 *   verify  → the WhatsApp check (status `phone`), or straight on when it is off
 *   phone   → the person sends a code from their WhatsApp (FeatureWhatsAppNotifications\Tools\PhoneCodes); its
 *             number becomes the owner's phone, then the CRM user and the workspace provisioning
 *   status  → once the workspace is ready, a one-time login ticket
 *
 * The visitor holds the signup id and a secret token returned by begin().
 * Later calls need both. Only hashes of the token, the code and the password
 * are stored. Nothing outside the signup row exists until the code is right.
 */
class Signups
{
    private const ENTITY_TYPE = 'SelfServiceSignup';

    private const CODE_TTL_SECONDS = 900;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_CODE_SENDS = 5;
    /** Per signup, across resends: a new code doesn't give new guesses. */
    private const MAX_CODE_ATTEMPTS = 5;
    /** Per email across all its signups, so a 6-digit code can't be ground down. */
    private const MAX_CODE_ATTEMPTS_PER_EMAIL_PER_DAY = 10;
    private const SIGNUPS_PER_IP_PER_HOUR = 10;
    private const SIGNUPS_PER_EMAIL_PER_HOUR = 5;
    private const LOGIN_WINDOW_SECONDS = 3600;
    /** A verified number whose activation hasn't finished after this long is activated by the next status poll. */
    private const ACTIVATION_RETRY_SECONDS = 90;

    public const PHONE_OK = 'ok';
    public const PHONE_TAKEN = 'taken';

    public function __construct(
        private EntityManager $entityManager,
        private SelfServicePolicy $policy,
        private PasswordHash $passwordHash,
        private VerificationMailer $mailer,
        private Provisioner $provisioner,
        private LoginTickets $tickets,
        private Log $log,
        private PhoneCodes $phoneCodes,
        private WhatsAppSettings $whatsApp,
        private InjectableFactory $injectableFactory,
    ) {}

    /**
     * @return array{signupId: string, token: string}
     * @throws SignupRejected
     */
    public function begin(SignupRequest $request, string $ipAddress): array
    {
        $this->policy->assertEnabled();
        $this->assertEmailAvailable($request->email);
        $this->assertBelowLimit('ipAddress', $ipAddress, self::SIGNUPS_PER_IP_PER_HOUR);
        $this->assertBelowLimit('email', $request->email, self::SIGNUPS_PER_EMAIL_PER_HOUR);

        if ($this->codeAttemptsToday($request->email) >= self::MAX_CODE_ATTEMPTS_PER_EMAIL_PER_DAY) {
            throw new SignupRejected(SignupRejected::RATE_LIMITED);
        }

        $token = bin2hex(random_bytes(32));
        $code = $this->newCode();
        $now = time();

        $signup = $this->entityManager->createEntity(self::ENTITY_TYPE, [
            'email' => $request->email,
            'name' => $request->name,
            'phone' => $request->phone,
            'company' => $request->company,
            'language' => $request->language,
            'passwordHash' => $this->passwordHash->hash($request->password),
            'tokenHash' => hash('sha256', $token),
            'codeSentAt' => $this->at($now),
            'codeSends' => 1,
            'codeAttempts' => 0,
            'ipAddress' => mb_substr($ipAddress, 0, 45),
            'status' => 'pending',
            'termsAcceptedAt' => $this->at($now),
        ], ['silent' => true]);

        $this->setCode($signup, $code, $now);
        $this->sendCode($signup, $code);

        return ['signupId' => (string) $signup->getId(), 'token' => $token];
    }

    /**
     * @throws SignupRejected
     */
    public function resend(string $signupId, string $token): void
    {
        $this->policy->assertEnabled();
        $signup = $this->find($signupId, $token);

        if ($signup->get('status') !== 'pending') {
            return;
        }

        $now = time();

        if (strtotime((string) $signup->get('codeSentAt') . ' UTC') + self::RESEND_COOLDOWN_SECONDS > $now) {
            throw new SignupRejected(SignupRejected::RESEND_TOO_SOON);
        }

        if ((int) $signup->get('codeSends') >= self::MAX_CODE_SENDS) {
            throw new SignupRejected(SignupRejected::RATE_LIMITED);
        }

        $code = $this->newCode();
        $signup->set([
            'codeSentAt' => $this->at($now),
            'codeSends' => (int) $signup->get('codeSends') + 1,
        ]);
        $this->setCode($signup, $code, $now);
        $this->sendCode($signup, $code);
    }

    /**
     * Check the code, then create the owner and start the workspace. Calling
     * again after success just reports progress.
     *
     * @return array{status: string, ticket?: string}
     * @throws SignupRejected
     */
    public function verify(string $signupId, string $token, string $code): array
    {
        $this->policy->assertEnabled();
        $signup = $this->find($signupId, $token);

        if ($signup->get('status') === 'verified') {
            return $this->progress($signup);
        }

        if ($signup->get('status') === 'phone') {
            return $this->phoneStep($signup);
        }

        if ((int) $signup->get('codeAttempts') >= self::MAX_CODE_ATTEMPTS ||
            $this->codeAttemptsToday((string) $signup->get('email')) >= self::MAX_CODE_ATTEMPTS_PER_EMAIL_PER_DAY) {
            throw new SignupRejected(SignupRejected::TOO_MANY_ATTEMPTS);
        }

        if (strtotime((string) $signup->get('codeExpiresAt') . ' UTC') < time()) {
            throw new SignupRejected(SignupRejected::CODE_EXPIRED);
        }

        // Count the attempt before comparing, so failures always add up.
        $signup->set('codeAttempts', (int) $signup->get('codeAttempts') + 1);
        $this->entityManager->saveEntity($signup, ['silent' => true]);

        $code = trim($code);

        if (!preg_match('/^\d{6}$/', $code) ||
            !hash_equals((string) $signup->get('codeHash'), $this->codeHash($signup, $code))) {
            throw new SignupRejected(SignupRejected::INVALID_CODE);
        }

        // Another signup may have taken the email while this code was out.
        $this->assertEmailAvailable((string) $signup->get('email'));

        return $this->afterEmail($signup);
    }

    /**
     * Signup whose email a sign-in provider already verified (SocialSignIn):
     * no code, straight to the owner and the workspace.
     *
     * @return array{signupId: string, token: string, status: string, ticket?: string}
     * @throws SignupRejected
     */
    public function beginVerified(SignupRequest $request, string $ipAddress): array
    {
        $this->policy->assertEnabled();
        $this->assertEmailAvailable($request->email);
        $this->assertBelowLimit('ipAddress', $ipAddress, self::SIGNUPS_PER_IP_PER_HOUR);

        $token = bin2hex(random_bytes(32));
        $now = time();

        $signup = $this->entityManager->createEntity(self::ENTITY_TYPE, [
            'email' => $request->email,
            'name' => $request->name,
            'phone' => $request->phone,
            'company' => $request->company,
            'language' => $request->language,
            'passwordHash' => $this->passwordHash->hash($request->password),
            'tokenHash' => hash('sha256', $token),
            'codeSends' => 0,
            'codeAttempts' => 0,
            'ipAddress' => mb_substr($ipAddress, 0, 45),
            'status' => 'pending',
            'termsAcceptedAt' => $this->at($now),
        ], ['silent' => true]);

        return ['signupId' => (string) $signup->getId(), 'token' => $token, ...$this->afterEmail($signup)];
    }

    /**
     * @return array{status: string, ticket?: string}
     */
    private function activate(Entity $signup): array
    {
        $user = $this->createOwner($signup);

        $signup->set([
            'status' => 'verified',
            'verifiedAt' => $this->at(time()),
            'userId' => $user->getId(),
            'codeHash' => null,
        ]);
        $this->entityManager->saveEntity($signup, ['silent' => true]);

        // Both paths here proved the email: the code, or a provider-verified sign-in.
        $provisioning = $this->provisioner->start(new ProvisioningRequest(
            'signup:' . strtolower((string) $signup->getId()),
            (string) $user->getId(),
            (string) $signup->get('company'),
            (string) $signup->get('language'),
            ownerEmailVerified: true,
        ));

        $signup->set('provisioningId', $provisioning->getId());
        $this->entityManager->saveEntity($signup, ['silent' => true]);

        // Usually done within the request. If not, the minute job continues
        // it and status() reports when it is ready.
        $this->provisioner->run((string) $provisioning->getId());

        return $this->progress($signup);
    }

    /**
     * @return array{status: string, ticket?: string}
     * @throws SignupRejected
     */
    public function status(string $signupId, string $token): array
    {
        $signup = $this->find($signupId, $token);

        if ($signup->get('status') === 'phone') {
            $this->retryActivation($signup);
            $signup = $this->entityManager->getEntityById(self::ENTITY_TYPE, (string) $signup->getId()) ?? $signup;
        }

        if ($signup->get('status') === 'phone') {
            return $this->phoneStep($signup);
        }

        if ($signup->get('status') !== 'verified') {
            return ['status' => 'pending'];
        }

        return $this->progress($signup);
    }

    /**
     * @return array{status: string, ticket?: string}
     */
    private function progress(Entity $signup): array
    {
        $provisioning = $signup->get('provisioningId')
            ? $this->entityManager->getEntityById(Provisioner::ENTITY_TYPE, (string) $signup->get('provisioningId'))
            : null;

        $status = $provisioning?->get('status');

        // Usable once the owner's membership exists; the starter (funnel, AI agent, Em dia) may still be finishing.
        if ($provisioning && Provisioner::ownerCanEnter($provisioning)) {
            $this->subscribeOwner($signup, $provisioning);

            // The signup token stands in for the password only for a short while.
            if (strtotime((string) $signup->get('verifiedAt') . ' UTC') + self::LOGIN_WINDOW_SECONDS < time()) {
                return ['status' => 'ready'];
            }

            return ['status' => 'ready', 'ticket' => $this->tickets->issue((string) $signup->get('userId'))];
        }

        if ($status === Provisioner::STATUS_FAILED && !$provisioning->get('nextAttemptAt')) {
            return ['status' => 'failed'];
        }

        return ['status' => 'provisioning'];
    }

    /**
     * The email is proven. With the WhatsApp check on, the signup waits for the person to send a code from their
     * WhatsApp; nothing else exists until then.
     *
     * @return array<string, mixed>
     */
    private function afterEmail(Entity $signup): array
    {
        if (!$this->whatsApp->signupVerification()) {
            return $this->activate($signup);
        }

        $signup->set(['status' => 'phone', 'codeHash' => null]);
        $this->entityManager->saveEntity($signup, ['silent' => true]);

        return $this->phoneStep($signup);
    }

    /**
     * The WhatsApp step: the code to send (a new one when the last expired), the Avisos number, and whether the last
     * number sent from already has a workspace.
     *
     * @return array{status: string, phone: array<string, mixed>}
     */
    private function phoneStep(Entity $signup): array
    {
        $owner = ['signupId' => (string) $signup->getId()];
        $code = $this->phoneCodes->pending(PhoneCodes::SIGNUP, $owner) ?? $this->phoneCodes->issue(PhoneCodes::SIGNUP, $owner);

        return ['status' => 'phone', 'phone' => [
            ...$code,
            'number' => $this->whatsApp->displayNumber(),
            'verified' => (bool) $signup->get('phoneVerifiedAt'),
            'taken' => (bool) $signup->get('phoneTakenAt'),
        ]];
    }

    /**
     * POST /SelfService/signup/:id/phone: a new WhatsApp code.
     *
     * @return array<string, mixed>
     * @throws SignupRejected
     */
    public function phoneCode(string $signupId, string $token): array
    {
        $this->policy->assertEnabled();
        $signup = $this->find($signupId, $token);

        if ($signup->get('status') !== 'phone') {
            return $this->status($signupId, $token);
        }

        $signup->set('phoneTakenAt', null);
        $this->entityManager->saveEntity($signup, ['silent' => true]);
        $this->phoneCodes->issue(PhoneCodes::SIGNUP, ['signupId' => (string) $signup->getId()]);

        return $this->phoneStep($signup);
    }

    /**
     * A signup code arrived from $phone (FeatureWhatsAppNotifications\Tools\Inbound). One free trial per verified
     * number (decided 2026-11-12): a number that already verified another signup is refused, and the person may send
     * a new code from another number. Otherwise the number becomes the owner's phone and the account is created here,
     * so it happens even if the signup page was closed.
     */
    public function phoneVerified(string $signupId, string $phone): string
    {
        $signup = $this->entityManager->getEntityById(self::ENTITY_TYPE, $signupId);

        if (!$signup || $signup->get('status') !== 'phone') {
            return self::PHONE_OK;
        }

        $used = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['phone' => $phone, 'phoneVerifiedAt!=' => null, 'id!=' => $signup->getId()])
            ->findOne();

        if ($used) {
            $signup->set('phoneTakenAt', $this->at(time()));
            $this->entityManager->saveEntity($signup, ['silent' => true]);

            return self::PHONE_TAKEN;
        }

        // Claimed once: a status poll racing this one must not create a second owner.
        $claim = $this->entityManager->getPDO()->prepare(
            'UPDATE self_service_signup SET phone = ?, phone_verified_at = ?, phone_taken_at = NULL '
            . "WHERE id = ? AND status = 'phone' AND phone_verified_at IS NULL AND deleted = 0"
        );
        $claim->execute([$phone, $this->at(time()), $signup->getId()]);

        if ($claim->rowCount() !== 1) {
            return self::PHONE_OK;
        }

        $this->activateVerified((string) $signup->getId());

        return self::PHONE_OK;
    }

    /** A verified number whose activation didn't finish (the webhook died): the next status poll takes it over. */
    private function retryActivation(Entity $signup): void
    {
        $verifiedAt = $signup->get('phoneVerifiedAt');

        if (!$verifiedAt || strtotime($verifiedAt . ' UTC') + self::ACTIVATION_RETRY_SECONDS > time()) {
            return;
        }

        $claim = $this->entityManager->getPDO()->prepare(
            "UPDATE self_service_signup SET phone_verified_at = ? WHERE id = ? AND status = 'phone' AND phone_verified_at = ? AND deleted = 0"
        );
        $claim->execute([$this->at(time()), $signup->getId(), $verifiedAt]);

        if ($claim->rowCount() === 1) {
            $this->activateVerified((string) $signup->getId());
        }
    }

    private function activateVerified(string $signupId): void
    {
        $signup = $this->entityManager->getEntityById(self::ENTITY_TYPE, $signupId);

        if (!$signup) {
            return;
        }

        try {
            $this->assertEmailAvailable((string) $signup->get('email'));
            $this->activate($signup);
        } catch (\Throwable $e) {
            $this->log->error("SelfService: activation of signup {$signupId} after the WhatsApp check failed: " . $e->getMessage());
        }
    }

    /**
     * The owner proved their WhatsApp at signup, and the step said Em dia would come there: their Avisos subscription
     * in the new workspace, once it exists. Idempotent; a failure only leaves it for Settings.
     */
    private function subscribeOwner(Entity $signup, Entity $provisioning): void
    {
        if (!$signup->get('phoneVerifiedAt') || !$signup->get('userId') || !$provisioning->get('tenantId')) {
            return;
        }

        try {
            $account = $this->entityManager
                ->getRDBRepository('ChatwootAccount')
                ->where(['tenantId' => $provisioning->get('tenantId'), 'chatwootAccountId>' => 0])
                ->order('createdAt')
                ->findOne();

            if (!$account) {
                return;
            }

            $subscriptions = $this->injectableFactory->create(Subscriptions::class);

            if ($subscriptions->find((string) $signup->get('userId'), (string) $account->getId())) {
                return;
            }

            $subscriptions->activate((string) $signup->get('userId'), (string) $account->getId(), (string) $signup->get('phone'), 'signup', null, null);
        } catch (\Throwable $e) {
            $this->log->warning("SelfService: Avisos subscription for signup {$signup->getId()} failed: " . $e->getMessage());
        }
    }

    private function createOwner(Entity $signup): User
    {
        $name = (string) $signup->get('name');
        $parts = explode(' ', $name, 2);

        /** @var User */
        return $this->entityManager->createEntity(User::ENTITY_TYPE, [
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
            'firstName' => count($parts) === 2 ? $parts[0] : null,
            'lastName' => count($parts) === 2 ? $parts[1] : $name,
            'emailAddress' => $signup->get('email'),
            'phoneNumber' => $signup->get('phone'),
            'password' => $signup->get('passwordHash'),
        ]);
    }

    /**
     * Logins are by email (Global EmailIdentity), so an email held by any user
     * can't start a second account. Reserved machine-identity addresses never can.
     *
     * @throws SignupRejected
     */
    private function assertEmailAvailable(string $email): void
    {
        if (ManagedIdentityPolicy::isReservedEmail($email)) {
            throw new SignupRejected(SignupRejected::INVALID_EMAIL);
        }

        $taken = $this->entityManager
            ->getRDBRepository(User::ENTITY_TYPE)
            ->select(['id'])
            ->where(['OR' => [['userName' => $email], ['emailAddress' => $email]]])
            ->findOne();

        if ($taken) {
            throw new SignupRejected(SignupRejected::EMAIL_IN_USE);
        }
    }

    /**
     * @throws SignupRejected
     */
    private function assertBelowLimit(string $attribute, string $value, int $limit): void
    {
        $count = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where([
                $attribute => $value,
                'createdAt>' => $this->at(time() - 3600),
            ])
            ->count();

        if ($count >= $limit) {
            throw new SignupRejected(SignupRejected::RATE_LIMITED);
        }
    }

    /**
     * Code guesses for this email in the last 24 hours, over all its signups
     * (at most ~120 rows given the hourly signup limit).
     */
    private function codeAttemptsToday(string $email): int
    {
        $total = 0;

        $signups = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->select(['codeAttempts'])
            ->where(['email' => $email, 'createdAt>' => $this->at(time() - 86400)])
            ->find();

        foreach ($signups as $signup) {
            $total += (int) $signup->get('codeAttempts');
        }

        return $total;
    }

    /**
     * @throws SignupRejected
     */
    private function find(string $signupId, string $token): Entity
    {
        $signup = preg_match('/^[A-Za-z0-9-]{1,36}$/', $signupId)
            ? $this->entityManager->getEntityById(self::ENTITY_TYPE, $signupId)
            : null;

        if (!$signup || !hash_equals((string) $signup->get('tokenHash'), hash('sha256', $token))) {
            throw new SignupRejected(SignupRejected::NOT_FOUND);
        }

        return $signup;
    }

    /**
     * @throws SignupRejected
     */
    private function sendCode(Entity $signup, string $code): void
    {
        try {
            $this->mailer->send(
                (string) $signup->get('email'),
                (string) $signup->get('name'),
                $code,
                (string) $signup->get('language')
            );
        } catch (SignupRejected $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->log->error("SelfService: verification email for signup {$signup->getId()} failed: " . $e->getMessage());

            throw new SignupRejected(SignupRejected::EMAIL_NOT_SENT);
        }
    }

    private function setCode(Entity $signup, string $code, int $now): void
    {
        $signup->set([
            'codeHash' => $this->codeHash($signup, $code),
            'codeExpiresAt' => $this->at($now + self::CODE_TTL_SECONDS),
        ]);
        $this->entityManager->saveEntity($signup, ['silent' => true]);
    }

    private function codeHash(Entity $signup, string $code): string
    {
        return hash('sha256', $signup->getId() . ':' . $code);
    }

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function at(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
