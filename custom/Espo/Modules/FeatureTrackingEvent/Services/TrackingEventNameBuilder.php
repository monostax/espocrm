<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Language\LanguageFactory;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Composes human-friendly names for TrackingEvent records and labels for
 * auto-created TrackingEventType records.
 *
 * Shape: "{label} · {detail}"
 *   - label:  the TrackingEventType name when it differs from the raw code
 *             (tenant-editable — renaming a type improves future event
 *             names), otherwise a translated label for the code from i18n
 *             (TrackingEvent > eventCodeLabels), otherwise a prettified
 *             code ("form_submitted" -> "Form Submitted").
 *   - detail: caller-supplied context fragment (link name, page title or
 *             path, WhatsApp phone, opportunity name...). Optional,
 *             language-neutral data.
 *
 * No timestamp in the name - occurredAt is its own column everywhere the
 * name is shown.
 *
 * Language resolution ladder: Tenant.language -> instance default
 * (config 'language') -> en_US. Resolved labels are data frozen at
 * creation time; per-viewing-user locale is NOT re-rendered (accepted
 * trade-off, single-language tenants).
 *
 * Used by TrackingEventIngester, TrackingLinkRedirector,
 * InternalEventRecorder, WhatsAppAttributionLinker,
 * TrackingEventPersister::resolveEventType and the
 * tracking-event:rebuild-names console command. Never throws.
 */
class TrackingEventNameBuilder
{
    /** TrackingEvent.name is varchar(200). */
    private const MAX_LENGTH = 200;

    private const SEPARATOR = ' · ';

    private const I18N_SCOPE = 'TrackingEvent';
    private const I18N_CATEGORY = 'eventCodeLabels';

    /** @var array<string, string> tenantId => language code */
    private array $localeByTenantId = [];

    /** @var array<string, Language> language code => instance */
    private array $languageByLocale = [];

    public function __construct(
        private EntityManager $entityManager,
        private LanguageFactory $languageFactory,
        private Config $config,
        private Log $log,
    ) {}

    /**
     * Builds a display name for a TrackingEvent.
     *
     * @param string $code Event code (e.g. 'link_clicked').
     * @param string $tenantId Tenant the event belongs to (drives language).
     * @param ?string $typeName Name of the resolved TrackingEventType, if at hand.
     * @param ?string $detail Context fragment (link name, page title, phone...).
     */
    public function build(string $code, string $tenantId, ?string $typeName = null, ?string $detail = null): string
    {
        $label = $this->resolveLabel($code, $tenantId, $typeName);

        $detail = $this->cleanDetail($detail);

        if ($detail === null) {
            return mb_substr($label, 0, self::MAX_LENGTH);
        }

        $name = $label . self::SEPARATOR . $detail;

        return mb_substr($name, 0, self::MAX_LENGTH);
    }

    /**
     * Human label for an event code in the tenant's language. Used both as
     * the name-prefix of events and as the seeded name of auto-created
     * TrackingEventType records.
     */
    public function labelForCode(string $code, string $tenantId): string
    {
        try {
            $translated = $this->language($tenantId)
                ->get([self::I18N_SCOPE, self::I18N_CATEGORY, $code]);

            if (is_string($translated) && $translated !== '') {
                return $translated;
            }
        } catch (Throwable $e) {
            $this->log->warning(
                "TrackingEventNameBuilder: label lookup failed for code={$code}: " . $e->getMessage());
        }

        return self::prettifyCode($code);
    }

    /**
     * "form_submitted" -> "Form Submitted". Fallback when no translation
     * exists for the code.
     */
    public static function prettifyCode(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }

    private function resolveLabel(string $code, string $tenantId, ?string $typeName): string
    {
        if (is_string($typeName)) {
            $typeName = trim($typeName);

            // A type name equal to the raw code is a legacy auto-created
            // placeholder, not a human label - translate instead.
            if ($typeName !== '' && $typeName !== $code) {
                return $typeName;
            }
        }

        return $this->labelForCode($code, $tenantId);
    }

    private function cleanDetail(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        // Collapse whitespace/control chars; keep it a single line.
        $detail = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $detail));

        if ($detail === '') {
            return null;
        }

        // Leave room for the label part.
        return mb_substr($detail, 0, 120);
    }

    private function language(string $tenantId): Language
    {
        $locale = $this->tenantLocale($tenantId);

        return $this->languageByLocale[$locale] ??= $this->languageFactory->create($locale);
    }

    private function tenantLocale(string $tenantId): string
    {
        if (isset($this->localeByTenantId[$tenantId])) {
            return $this->localeByTenantId[$tenantId];
        }

        $locale = null;

        if ($tenantId !== '') {
            try {
                $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

                $value = $tenant?->get('language');

                if (is_string($value) && $value !== '') {
                    $locale = $value;
                }
            } catch (Throwable $e) {
                $this->log->warning(
                    "TrackingEventNameBuilder: tenant language lookup failed for {$tenantId}: " . $e->getMessage());
            }
        }

        $locale ??= $this->config->get('language') ?? 'en_US';

        return $this->localeByTenantId[$tenantId] = $locale;
    }
}
