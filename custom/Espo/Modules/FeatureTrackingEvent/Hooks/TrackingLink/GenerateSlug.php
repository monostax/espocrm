<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\TrackingLink;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingLink;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Assigns the immutable short slug on creation and pins it on update.
 *
 * Slug: random lowercase base36 (a-z0-9), 10 chars (~3.6e15 space) — the
 * alphabet is lowercase-only because MySQL/MariaDB default collations are
 * case-insensitive, so mixed-case slugs could collide at the unique index.
 *
 * Collision handling: probability is negligible, but a best-effort existence
 * check retries a few times; the `slug` unique index is the real guarantee
 * (a race between two concurrent creates surfaces as a save error, and the
 * user simply retries).
 *
 * Immutability: the slug IS the public URL already printed in emails/posts;
 * any attempted change (API mass update, import) is reverted to the fetched
 * value.
 */
class GenerateSlug implements BeforeSave
{
    public static int $order = 5;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof TrackingLink) {
            return;
        }

        if (!$entity->isNew()) {
            if ($entity->isAttributeChanged('slug')) {
                $entity->set('slug', $entity->getFetched('slug'));
            }

            return;
        }

        $slug = $entity->get('slug');

        if (is_string($slug) && $slug !== '') {
            // System-provided slug (e.g. migration import): keep as-is;
            // the unique index still guards duplicates.
            return;
        }

        $entity->set('slug', $this->generateSlug());
    }

    private function generateSlug(): string
    {
        $repo = $this->entityManager->getRDBRepository(TrackingLink::ENTITY_TYPE);

        $slug = '';

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $slug = $this->randomSlug();

            $existing = $repo
                ->where(['slug' => $slug])
                ->findOne();

            if ($existing === null) {
                return $slug;
            }
        }

        // Astronomically unlikely; unique index catches the collision.
        return $slug;
    }

    private function randomSlug(): string
    {
        $alphabet = TrackingLink::SLUG_ALPHABET;
        $max = strlen($alphabet) - 1;

        $slug = '';

        for ($i = 0; $i < TrackingLink::SLUG_LENGTH; $i++) {
            $slug .= $alphabet[random_int(0, $max)];
        }

        return $slug;
    }
}
