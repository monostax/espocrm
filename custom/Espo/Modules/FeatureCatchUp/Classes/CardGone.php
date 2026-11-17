<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Classes;

use Espo\Core\Exceptions\NotFound;

/** The subject is no longer eligible. Retrying the analysis cannot produce a current card. */
class CardGone extends NotFound {}
