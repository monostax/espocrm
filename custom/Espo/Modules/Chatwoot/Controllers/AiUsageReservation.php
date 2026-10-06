<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

// Internal accounting state is writable only through the signed AiBudget API.
// An explicit empty controller prevents fallback to generic Base CRUD actions.
class AiUsageReservation {}
