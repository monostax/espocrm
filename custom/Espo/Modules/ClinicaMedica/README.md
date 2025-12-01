# ClinicaMedica Module

## Overview

This module contains all customizations for the Clinica Medica (Medical Clinic) system. It provides patient management, medical consultations, doctor records, AI agent integration, and call tracking functionality.

## Entities

The module manages the following custom entities:

1. **CPaciente** (Patient)

    - Person-based entity for managing patient records
    - Full CRUD operations with custom layouts
    - Multi-language support

2. **CMedico** (Doctor)

    - Person-based entity for managing doctor/physician records
    - Custom detail and list views
    - Relationships with patients and consultations

3. **CAgendamento** (Medical Consultation)

    - Event-based entity for scheduling and tracking medical appointments
    - Formula and logic definitions for automation
    - Mass update capabilities
    - Custom filters and views

4. **CLigacoesIA** (AI Calls/Connections)

    - BasePlus entity for tracking AI-powered call interactions
    - Advanced logic and formula definitions
    - Custom side panels and bottom panels

5. **CAIAgent** (AI Agent)
    - Base entity for managing AI agent configurations
    - Integration with the call tracking system

## Structure

```
ClinicaMedica/
├── Controllers/           # PHP controllers for each entity
│   ├── CAIAgent.php
│   ├── CAgendamento.php
│   ├── CLigacoesIA.php
│   ├── CMedico.php
│   └── CPaciente.php
├── Resources/
│   ├── i18n/             # Translations (30+ languages)
│   ├── layouts/          # Custom UI layouts
│   │   ├── CAgendamento/
│   │   ├── CLigacoesIA/
│   │   ├── CMedico/
│   │   └── CPaciente/
│   ├── metadata/         # Entity and system metadata
│   │   ├── aclDefs/      # Access control definitions
│   │   ├── clientDefs/   # Client-side definitions
│   │   ├── entityDefs/   # Entity field definitions
│   │   ├── formula/      # Formula scripts
│   │   ├── logicDefs/    # Logic definitions
│   │   ├── recordDefs/   # Record behavior definitions
│   │   ├── scopes/       # Scope definitions
│   │   └── selectDefs/   # Select query definitions
│   └── module.json       # Module metadata
└── README.md            # This file
```

## Migration Notes

This module was migrated from the backup located at:
`~/espocrm-backups/20251004-124917/espocrm-custom.tar.gz`

The customizations were moved from `custom/Espo/Custom/` to a proper module structure at:
`custom/Espo/Modules/ClinicaMedica/`

All PHP namespaces were updated from `Espo\Custom\Controllers` to `Espo\Modules\ClinicaMedica\Controllers`.

## Language Support

The module includes translations for the following languages:

-   Arabic (ar_AR)
-   Bulgarian (bg_BG)
-   Czech (cs_CZ)
-   Danish (da_DK)
-   German (de_DE)
-   English GB (en_GB)
-   English US (en_US)
-   Spanish (es_ES, es_MX)
-   Persian (fa_IR)
-   French (fr_FR)
-   Croatian (hr_HR)
-   Hungarian (hu_HU)
-   Indonesian (id_ID)
-   Italian (it_IT)
-   Japanese (ja_JP)
-   Lithuanian (lt_LT)
-   Latvian (lv_LV)
-   Norwegian (nb_NO)
-   Dutch (nl_NL)
-   Polish (pl_PL)
-   Portuguese (pt_BR, pt_PT)
-   Romanian (ro_RO)
-   Russian (ru_RU)
-   Slovak (sk_SK)
-   Slovenian (sl_SI)
-   Serbian (sr_RS)
-   Swedish (sv_SE)
-   Thai (th_TH)
-   Turkish (tr_TR)
-   Ukrainian (uk_UA)
-   Vietnamese (vi_VN)
-   Chinese Simplified (zh_CN)
-   Chinese Traditional (zh_TW)

## Installation

This module is already installed as part of the EspoCRM customization. After deployment:

1. Clear cache: `php command.php clear-cache`
2. Rebuild: `php command.php rebuild`

## Version

-   Version: 1.0.0
-   Migration Date: October 7, 2025
-   Total Files: 234
