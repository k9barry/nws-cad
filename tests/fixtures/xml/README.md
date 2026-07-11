# XML ingest fixtures

Canonical Aegis CAD `CallExport` documents used by the importer characterization
and edge-case tests (issue #48). Keeping them as real files (rather than inline
heredocs) lets the same inputs feed both MySQL and PostgreSQL CI jobs and gives
the planned importer refactor (#49) a stable, reviewable corpus.

| Fixture | CallId | Purpose |
|---|---|---|
| `full_call.xml` | 700001 | Every child table populated (agency context, location, incident, narrative, person, vehicle, call disposition, unit + personnel + unit log + unit disposition). The row-content characterization baseline. |
| `minimal_call.xml` | 700002 | Only the required call fields — no child collections. Locks the "sparse call" path. |
| `nil_fields.xml` | 700003 | `xsi:nil="true"` on optional scalars (call source, caller, alarm level, house number, zip, …) to lock null-coercion behavior. |
| `bom_utf8.xml` | 700004 | Leading UTF-8 BOM (`EF BB BF`) — exercises `stripBOM()`. |
| `bom_utf16be.xml` | 700005 | Leading UTF-16 BE BOM (`FE FF`). Body stays ASCII so only the BOM branch is under test. |
| `bom_utf16le.xml` | 700006 | Leading UTF-16 LE BOM (`FF FE`), same rationale. |

All CallIds are in the `7000xx` range to avoid collisions with the hard-coded
primary keys other integration tests rely on.

Reopen and stale-ordering scenarios are driven by the tests themselves (same
`CallId` ingested twice under different `{call_number}_{timestamp}.xml`
filenames), not by separate fixture files, because those behaviors depend on
processing order and filename recency rather than document content.
