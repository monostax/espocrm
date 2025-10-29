# Global Module

This module provides global customizations for the EspoCRM navbar and system-wide configurations.

## Features

### Automatic Navbar Customization

The module automatically modifies the default navbar tab list to improve usability:

- **Call Entity Positioning**: Moves the "Call" entity from the "Activities" section to the top section, right after the CRM divider
- This makes Calls more accessible as they appear near the beginning of the navbar
- **Respects User Customization**: Only modifies the tabList if it hasn't been customized by users
- **Automatic Execution**: Runs automatically during system rebuild
- **One-time Setup**: Uses a flag to prevent repeated execution on subsequent rebuilds

## How It Works

1. A Rebuild Action (`ConfigureNavbar`) runs automatically when the system is rebuilt
2. It checks if the navbar has already been configured (via `navbarConfigured` config flag)
3. If not configured, it checks if the tabList still has the default structure (Call under Activities)
4. If yes, it reorganizes the tabList to move Call to the top section
5. Sets a flag to prevent the action from running again
6. If the user has customized their tabList, the action does not modify it

## Installation & Deployment

This module is already installed in the custom directory structure:
- `/custom/Espo/Modules/Global/`

The navbar customization will be applied automatically on the next system rebuild:

```bash
# From the EspoCRM pod:
php command.php rebuild
```

Or rebuild from the admin panel: **Administration > Rebuild**

That's it! The navbar will be automatically configured on first rebuild.

## Module Structure

```
Global/
├── Rebuild/
│   └── ConfigureNavbar.php       # Rebuild action (runs automatically)
├── Resources/
│   ├── metadata/
│   │   └── app/
│   │       └── rebuild.json      # Rebuild action registration
│   └── module.json              # Module configuration
└── README.md                    # This file
```

## Technical Details

### Rebuild Action Registration

**File**: `Resources/metadata/app/rebuild.json`
```json
{
    "actionClassNameList": [
        "Espo\\Modules\\Global\\Rebuild\\ConfigureNavbar"
    ]
}
```

### Implementation

**Class**: `Rebuild/ConfigureNavbar.php`
- Implements `Espo\Core\Rebuild\RebuildAction` interface
- Runs automatically on system rebuild
- Moves "Call" from Activities to top section (after CRM divider)
- Checks if already configured using `navbarConfigured` config flag
- Respects user customizations
- Logs actions to `data/logs/espo.log`

### Configuration Flag

The module uses a configuration flag to track if the navbar has been configured:
- **Flag**: `navbarConfigured` in `data/config.php`
- **Purpose**: Prevent repeated execution on subsequent rebuilds
- **Value**: `true` (set after first successful configuration)

## Resetting the Configuration

If you need to re-run the navbar configuration (e.g., after reverting to EspoCRM defaults):

1. **Remove the flag** from `data/config.php`:
   ```php
   // Find and remove this line:
   'navbarConfigured' => true,
   ```

2. **Rebuild the system**:
   ```bash
   php command.php rebuild
   ```

The navbar will be reconfigured automatically.

## Logs

Check the EspoCRM logs for confirmation:
```bash
tail -f data/logs/espo.log | grep "Global Module"
```

You should see:
```
Global Module: Successfully configured navbar - moved Call to top section.
```

## Version

- **Version**: 1.0.0
- **Order**: 10

## Notes

- ✅ The rebuild action only modifies the tabList if it matches the default EspoCRM structure
- ✅ User customizations are always preserved
- ✅ After any EspoCRM upgrade, simply rebuild to reapply customizations if needed
- ✅ The action is safe to run multiple times - it checks the flag before executing
- ✅ No manual intervention required - everything happens automatically on rebuild
