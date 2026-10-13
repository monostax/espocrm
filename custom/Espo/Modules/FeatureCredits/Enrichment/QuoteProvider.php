<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

/** Implemented by verified CRM supplier adapters. Never perform paid I/O when quoting. */
interface QuoteProvider
{
    /**
     * Null means unavailable, unsupported or missing inputs. Otherwise return a
     * non-secret SHA-256 revision binding verified capability, connection and terms.
     * A secret or a published price alone must never establish availability.
     * @param array<string, ?string> $identity Own readable record inputs only.
     */
    public function revision(string $tenantId, string $targetType, string $product, array $identity): ?string;
}
