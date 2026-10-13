<?php

declare(strict_types=1);

namespace Espo\ORM\Exception;

/** No record values: callers must perform a fresh authorized read to resolve. */
final class ConcurrencyConflict extends \RuntimeException {}
