# Custom Weight Field for EspoCRM

This custom field type adds support for weight measurements with units, similar to how Currency fields work with currency codes.

## Features

-   **Multiple Weight Units**: Supports kg, g, mg, lb, oz, and t (ton)
-   **Unit Selection**: Similar to currency field, users can select the weight unit
-   **Decimal Support**: Configurable decimal places for precise measurements
-   **Validation**: Built-in validation for min/max values
-   **Formatting**: Automatic number formatting with thousand separators
-   **i18n Support**: Includes English and Portuguese (Brazil) translations

## Installation

The weight field has been installed in the custom directory. To activate it:

1. **Clear Cache**: Go to Administration > Clear Cache
2. **Rebuild**: Go to Administration > Rebuild

## Configuration

### Default Weight Unit

You can set the default weight unit in:

-   Administration > Settings > Add `defaultWeightUnit` configuration (optional)
-   Default is `kg` if not specified

### Available Units

The following units are available by default:

-   `kg` - Kilogram
-   `g` - Gram
-   `mg` - Milligram
-   `lb` - Pound
-   `oz` - Ounce
-   `t` - Ton

## Using the Weight Field

### Adding to an Entity

1. Go to Administration > Entity Manager
2. Select your entity
3. Go to Fields
4. Click "Add Field"
5. Select "Weight" as the field type
6. Configure the field:
    - **Required**: Make the field required
    - **Default**: Set a default value
    - **Min/Max**: Set minimum and maximum values
    - **Only Default Unit**: Restrict to only the default unit
    - **Decimal**: Use decimal storage (VARCHAR) for precise values
    - **Audited**: Track changes to this field
    - **Read Only**: Make the field read-only
    - **Read Only After Create**: Lock the field after creation

### Field Parameters

-   `required` (bool): Whether the field is required
-   `default` (float): Default value
-   `min` (float): Minimum allowed value
-   `max` (float): Maximum allowed value
-   `onlyDefaultUnit` (bool): Restrict to only the default unit
-   `decimal` (bool): Use decimal storage (more precise, stored as VARCHAR)
-   `precision` (int): Database precision (default: 13)
-   `scale` (int): Database scale (default: 4)
-   `audited` (bool): Track field changes
-   `readOnly` (bool): Make field read-only
-   `readOnlyAfterCreate` (bool): Lock field after creation

### Example Usage

In entity forms, the weight field will appear with:

-   A numeric input field
-   A unit selector dropdown (if multiple units are allowed)
-   Automatic formatting with thousand separators
-   Validation for min/max values

## File Structure

```
custom/
├── Espo/
│   └── Custom/
│       ├── Core/
│       │   ├── Field/
│       │   │   ├── Weight.php                    # Weight value object
│       │   │   └── Weight/
│       │   │       ├── WeightFactory.php         # Creates Weight objects from entities
│       │   │       └── WeightAttributeExtractor.php # Extracts attributes from Weight objects
│       │   └── Utils/
│       │       └── Database/
│       │           └── Orm/
│       │               └── FieldConverters/
│       │                   └── Weight.php        # ORM field converter
│       └── Resources/
│           ├── metadata/
│           │   ├── app/
│           │   │   └── weight.json              # Weight units configuration
│           │   ├── entityDefs/
│           │   │   └── Settings.json            # Settings definition
│           │   └── fields/
│           │       └── weight.json              # Field metadata
│           └── i18n/
│               ├── en_US/
│               │   └── Global.json              # English translations
│               └── pt_BR/
│                   └── Global.json              # Portuguese translations
└── client/
    └── custom/
        ├── res/
        │   └── templates/
        │       └── fields/
        │           └── weight/
        │               ├── detail.tpl           # Detail view template
        │               ├── edit.tpl             # Edit view template
        │               └── list.tpl             # List view template
        └── src/
            └── views/
                ├── admin/
                │   └── field-manager/
                │       └── fields/
                │           └── weight-default.js # Admin field manager view
                ├── fields/
                │   ├── weight.js                # Main weight field view
                │   └── weight-unit-list.js      # Unit list field view
                └── settings/
                    └── fields/
                        └── default-weight-unit.js # Settings field view
```

## Technical Details

### Database Storage

The weight field stores two values:

-   `{fieldName}` - The numeric weight value (FLOAT or DECIMAL)
-   `{fieldName}Unit` - The weight unit (VARCHAR, max 10 chars)

### API Usage

When working with the API, weight fields should be sent/received as:

```json
{
    "weight": 100.5,
    "weightUnit": "kg"
}
```

### Programmatic Access (PHP)

```php
// Get weight value
$weight = $entity->get('weight');
$unit = $entity->get('weightUnit');

// Set weight value
$entity->set('weight', 100.5);
$entity->set('weightUnit', 'kg');

// Using the Weight value object
use Espo\Custom\Core\Field\Weight;

$weightObject = Weight::create(100.5, 'kg');
$value = $weightObject->getValue();
$unit = $weightObject->getUnit();
```

### JavaScript API

```javascript
// Get weight value
const weight = this.model.get("weight");
const unit = this.model.get("weightUnit");

// Set weight value
this.model.set({
    weight: 100.5,
    weightUnit: "kg",
});
```

## Customization

### Adding More Units

To add more weight units, edit:
`custom/Espo/Custom/Resources/metadata/app/weight.json`

```json
{
    "unitList": ["kg", "g", "mg", "lb", "oz", "t", "your_unit"],
    "symbolMap": {
        "kg": "kg",
        "g": "g",
        "mg": "mg",
        "lb": "lb",
        "oz": "oz",
        "t": "t",
        "your_unit": "your_symbol"
    }
}
```

Don't forget to add translations in the i18n files.

### Changing Decimal Places

You can modify the default decimal places in the field view or as a parameter when creating the field.

## Support

This is a custom implementation. For issues or questions, refer to the EspoCRM documentation on custom field types.

## License

This custom field follows the same license as EspoCRM (AGPL-3.0).

