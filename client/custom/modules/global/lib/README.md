# FullCalendar Scheduler Plugins

This directory contains the bundled FullCalendar Scheduler premium plugins required for the Resource Calendar view.

## Required Plugins

- `@fullcalendar/resource` - Core resource functionality
- `@fullcalendar/resource-timegrid` - Resource Time Grid views (resourceTimeGridDay, resourceTimeGridWeek)

## License

**IMPORTANT**: FullCalendar Scheduler is a premium product that requires a commercial license for commercial use.

- For open-source projects: Use `schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source'`
- For commercial use: Purchase a license at https://fullcalendar.io/pricing

## Building the Plugins

After purchasing a license or for GPL use, build the plugins:

1. Install the packages:
```bash
npm install @fullcalendar/resource @fullcalendar/resource-timegrid
```

2. Create a build script or use rollup to bundle:
```javascript
// rollup.config.js for resource plugin
import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';

export default {
  input: 'node_modules/@fullcalendar/resource/index.js',
  output: {
    file: 'client/custom/modules/clinica-medica/lib/fullcalendar-resource.js',
    format: 'iife',
    name: 'FullCalendarResource',
    globals: {
      'fullcalendar': 'FullCalendar'
    }
  },
  external: ['fullcalendar'],
  plugins: [resolve(), commonjs()]
};
```

3. Run the build:
```bash
npx rollup -c rollup.config.js
```

## Alternative: Use vis-timeline

If you don't want to purchase a FullCalendar Scheduler license, the existing Timeline view 
(using vis-timeline library) already provides resource grouping functionality at no additional cost.
Select "Timeline" mode and use "Shared" calendar type to see events grouped by users.
