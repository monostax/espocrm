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

use Espo\Core\Mail\EmailSender;
use Espo\Entities\Email;
use Espo\ORM\EntityManager;

/**
 * Sends the signup verification code through the CRM's system SMTP
 * account (Cloudflare Email Sending in production, provisioned by the
 * container's apply-runtime-smtp-account.php).
 */
class VerificationMailer
{
    public function __construct(
        private EntityManager $entityManager,
        private EmailSender $emailSender,
    ) {}

    public function send(string $to, string $name, string $code, string $language): void
    {
        $english = $language === 'en_US';
        $firstName = htmlspecialchars(explode(' ', $name)[0], ENT_QUOTES);

        $subject = $english
            ? "Your Monostax code: {$code}"
            : "Seu código Monostax: {$code}";

        $lines = $english
            ? ["Hi {$firstName},", 'Use this code to confirm your email and create your workspace:',
                'The code expires in 15 minutes. If you did not request it, ignore this email.']
            : ["Olá, {$firstName}!", 'Use este código para confirmar seu e-mail e criar seu espaço de trabalho:',
                'O código vale por 15 minutos. Se você não pediu, ignore este e-mail.'];

        $body = '<p>' . $lines[0] . '</p><p>' . $lines[1] . '</p>' .
            '<p style="font-size:28px;font-weight:bold;letter-spacing:6px">' . $code . '</p>' .
            '<p style="color:#666">' . $lines[2] . '</p>';

        /** @var Email $email */
        $email = $this->entityManager->getRDBRepositoryByClass(Email::class)->getNew();

        $email
            ->setSubject($subject)
            ->setBody($body)
            ->setIsHtml()
            ->addToAddress($to);

        $email->set('isSystem', true);

        $this->emailSender->create()->send($email);
    }
}
