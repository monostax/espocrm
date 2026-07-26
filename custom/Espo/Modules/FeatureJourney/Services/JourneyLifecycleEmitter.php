<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Throwable;

/**
 * Lazily resolves FeatureTrackingEvent InternalEventRecorder — never throws.
 */
class JourneyLifecycleEmitter
{
    private const RECORDER_FQCN = 'Espo\\Modules\\FeatureTrackingEvent\\Services\\InternalEventRecorder';

    public const CODE_ENROLLED = 'journey_enrolled';
    public const CODE_STAGE_ENTERED = 'journey_stage_entered';
    public const CODE_COMPLETED = 'journey_completed';
    public const CODE_GOAL_REACHED = 'journey_goal_reached';
    public const CODE_EXITED = 'journey_exited';

    public function __construct(
        private InjectableFactory $injectableFactory,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function emit(string $tenantId, string $code, array $options = []): void
    {
        try {
            if (!class_exists(self::RECORDER_FQCN)) {
                return;
            }

            /** @var object $recorder */
            $recorder = $this->injectableFactory->create(self::RECORDER_FQCN);

            if (!method_exists($recorder, 'record')) {
                return;
            }

            $recorder->record($tenantId, $code, $options);
        } catch (Throwable $e) {
            $this->log->warning(
                "JourneyLifecycleEmitter: failed to emit '{$code}' for tenant={$tenantId}: " . $e->getMessage()
            );
        }
    }
}
