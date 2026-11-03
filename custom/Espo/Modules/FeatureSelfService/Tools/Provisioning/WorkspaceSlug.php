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

namespace Espo\Modules\FeatureSelfService\Tools\Provisioning;

use Espo\ORM\EntityManager;

/**
 * Tenant slugs for new workspaces.
 *
 * A slug becomes a DNS label in machine identity emails
 * (`…@guest.<slug>.<domain>`, `…@<slug>.monostax-ext.com`), so it is
 * lowercase a-z, 0-9 and inner hyphens. A slug used by a deleted tenant
 * stays taken: identities may still carry it.
 */
class WorkspaceSlug
{
    private const MAX_BASE_LENGTH = 40;

    private const RESERVED = [
        'admin', 'api', 'app', 'auth', 'chat', 'crm', 'guest', 'mail', 'status', 'support', 'www',
    ];

    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o',
    ];

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public static function slugify(string $name): string
    {
        $text = strtr(mb_strtolower($name, 'UTF-8'), self::ACCENTS);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim(substr(trim($text, '-'), 0, self::MAX_BASE_LENGTH), '-');

        if ($text === '' || in_array($text, self::RESERVED, true)) {
            return $text === '' ? 'workspace' : $text . '-workspace';
        }

        return $text;
    }

    public function available(string $name): string
    {
        $base = self::slugify($name);
        $candidate = $base;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (!$this->isTaken($candidate)) {
                return $candidate;
            }

            $candidate = $base . '-' . bin2hex(random_bytes(2));
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }

    private function isTaken(string $slug): bool
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select(['id'])
            ->from('Tenant')
            ->where(['slug' => $slug])
            ->withDeleted()
            ->build();

        return $this->entityManager->getRDBRepository('Tenant')->clone($query)->findOne() !== null;
    }
}
