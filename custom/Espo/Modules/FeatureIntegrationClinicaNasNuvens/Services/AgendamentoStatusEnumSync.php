<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\DataManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use stdClass;

class AgendamentoStatusEnumSync
{
    private const ENTITY_TYPE = 'FeatureIntegrationClinicaNasNuvensAgendamento';
    private const FIELD = 'status';

    /**
     * @var array<string, bool>
     */
    private array $knownOptionMap = [];

    private bool $knownOptionsLoaded = false;

    public function __construct(
        private Metadata $metadata,
        private InjectableFactory $injectableFactory,
        private DataManager $dataManager,
        private Log $log,
    ) {}

    public function normalizeAndEnsureOption(?string $rawStatus): ?string
    {
        if ($rawStatus === null) {
            return null;
        }

        $label = trim($rawStatus);

        if ($label === '') {
            return null;
        }

        $key = $this->normalizeKey($label);

        if ($key === null) {
            return null;
        }

        $this->loadKnownOptions();

        if (isset($this->knownOptionMap[$key])) {
            return $key;
        }

        $this->persistOption($key, $label);
        $this->knownOptionMap[$key] = true;

        return $key;
    }

    private function loadKnownOptions(): void
    {
        if ($this->knownOptionsLoaded) {
            return;
        }

        $options = $this->metadata->get([
            'entityDefs',
            self::ENTITY_TYPE,
            'fields',
            self::FIELD,
            'options',
        ], []);

        if (is_array($options)) {
            foreach ($options as $option) {
                if (is_string($option) && $option !== '') {
                    $this->knownOptionMap[$option] = true;
                }
            }
        }

        $this->knownOptionsLoaded = true;
    }

    private function persistOption(string $key, string $label): void
    {
        $options = $this->metadata->get([
            'entityDefs',
            self::ENTITY_TYPE,
            'fields',
            self::FIELD,
            'options',
        ], []);

        if (!is_array($options)) {
            $options = [];
        }

        if (!in_array($key, $options, true)) {
            $options[] = $key;
        }

        $entityDefs = $this->metadata->getCustom('entityDefs', self::ENTITY_TYPE, (object) []);

        if (!isset($entityDefs->fields) || !$entityDefs->fields instanceof stdClass) {
            $entityDefs->fields = (object) [];
        }

        if (!isset($entityDefs->fields->{self::FIELD}) || !$entityDefs->fields->{self::FIELD} instanceof stdClass) {
            $entityDefs->fields->{self::FIELD} = (object) [];
        }

        $entityDefs->fields->{self::FIELD}->options = $options;

        $this->metadata->saveCustom('entityDefs', self::ENTITY_TYPE, $entityDefs);

        $ptBrLanguage = $this->injectableFactory->createWith(Language::class, ['language' => 'pt_BR']);
        $enUsLanguage = $this->injectableFactory->createWith(Language::class, ['language' => 'en_US']);

        $ptBrLanguage->set(self::ENTITY_TYPE, 'options', self::FIELD . '.' . $key, $label);
        $enUsLanguage->set(self::ENTITY_TYPE, 'options', self::FIELD . '.' . $key, $this->humanizeKey($key));

        $ptBrLanguage->save();
        $enUsLanguage->save();

        $this->dataManager->clearCache();

        $this->log->info(
            "FeatureIntegrationClinicaNasNuvens: added agendamento.status enum option '{$key}' with label '{$label}'."
        );
    }

    private function normalizeKey(string $value): ?string
    {
        $decoded = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
        $source = is_string($decoded) && $decoded !== '' ? $decoded : $value;

        $source = strtolower($source);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $source);

        if (!is_string($normalized)) {
            return null;
        }

        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : null;
    }

    private function humanizeKey(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }
}
