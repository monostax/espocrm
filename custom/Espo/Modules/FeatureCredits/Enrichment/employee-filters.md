# Employee search filters

`CreditEnrichmentPreview` exposes `filterOptions` on the employees action. The
inbox uses the same `MentionMenu` as “Create or link” for searchable, multi-select
presets. Typing only filters suggestions; only selecting an option changes the
quote. Selections are removable and do not create CRM records or options.

The option sources are CRM metadata:

- **Job Title (`Contact.title`)**: `Account.contactRole.options`, also used by the
  native Contact title autocomplete. The initial presets cover common executive,
  sales, marketing, operations, engineering and research titles.
- **Job Level (`Contact.jobLevel`)**: the existing enum, limited to mapped levels.
- Labels use `Contact.fields` and `Contact.options` translations. Stable CRM values,
  rather than translated labels, are persisted in quote filters.

`EmployeeFilters::apollo` maps at dispatch to Apollo's
[People API search](https://docs.apollo.io/reference/people-api-search):

| CRM selection | Apollo parameter/value |
| --- | --- |
| Titles | `person_titles[]`, the selected title strings |
| Founder | `person_seniorities[]=founder` |
| CXO | `c_suite` |
| SVP, VP | `vp` |
| Executive Director, Senior Director, Director, Associate Director | `director` |
| General Manager, Senior Manager, Manager, Lead | `manager` |
| Head | `head` |
| Principal, Senior | `senior` |
| Individual Contributor | `entry` |

Apollo's seniority buckets are coarser than the CRM enum: these are search-bucket
mappings, not exact title equivalences. Board Member has no Apollo seniority
equivalent and is offered as a title preset instead. Duplicate mapped buckets are
collapsed. Multiple titles are ORed, multiple seniorities are ORed, and the two
filters are combined by Apollo. Similar titles are enabled.

The API accepts `filters.titles` and `filters.jobLevels`. Legacy
`filters.seniorities` requests keep accepting Apollo's documented codes. Invalid
levels/codes are rejected at quoting, before provider execution. Transport encodes
array values using repeated `[]` query parameters.
