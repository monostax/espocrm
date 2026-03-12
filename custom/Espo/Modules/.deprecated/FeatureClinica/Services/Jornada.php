<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeatureClinica\Services;

use Espo\Services\Record;

/**
 * Jornada service. Handles session generation logic.
 * Minimal for Fase 0 -- session generation will be expanded in later phases.
 *
 * Forward reference: Jornada.convenioId and Appointment.convenioId are defined
 * as optional links in Fase 0. The Convenio entity does not exist yet (Fase 1).
 * Both fields are omitted from layouts to avoid confusing users. The DB columns
 * exist but are unused until Fase 1. If a value is written via API before the
 * entity exists, EspoCRM stores the FK but displays an empty link -- this is
 * harmless and self-corrects once Fase 1 is deployed.
 */
class Jornada extends Record
{
}
