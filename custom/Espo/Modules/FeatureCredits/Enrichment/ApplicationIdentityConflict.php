<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

/** Only pre-write identity checks use this marker, so quarantine can safely commit. */
final class ApplicationIdentityConflict extends \Espo\Core\Exceptions\Conflict {}
