# Changelog

## [1.1.2] - 2026-05-12

### Changes
- fix(deploy): document new security env vars; revert loopback-only API bind (#23) (7c35d60)

## [1.1.1] - 2026-05-12

### Changes
- Initial commit (7d4ca72)
- Initial plan (80943d3)
- Add core infrastructure: Docker, database, file watcher, and XML parser (c87faf4)
- Add setup script and fix Dockerfile for SSL certificate issues (fea865e)
- Fix XML parsing datetime format and add .dockerignore (f571466)
- Add security improvements: XXE protection, secure ID generation, log level validation (c08d179)
- Add docs and samples directories for Enterprise CAD Standard integration (ff11a63)
- Add files via upload (f03b102)
- Add files via upload (d1cf732)
- feat: comprehensive database schemas for NWS Aegis CAD XML format (567b19d)
- Implement New World Systems Aegis CAD schema and parser (98dcf5f)
- Add complete implementation documentation (e2d9eb1)
- Add comprehensive PHP API controllers for NWS CAD database (01b1db2)
- Address code review feedback (3c7ec37)
- Improve name concatenation and use DbHelper consistently (b7c0ec3)
- Add REST API with 19 endpoints and API documentation (4f374bd)
- feat: Add comprehensive web-based dashboard with maps, charts, and analytics (9704ad6)
- fix: Address code review feedback (aa114e0)
- Add comprehensive testing infrastructure and CI/CD pipeline (29699fd)
- Add testing documentation and update README (4bd5c84)
- Complete implementation: Dashboard, Testing, CI/CD, Monitoring, Documentation (d97c179)
- feat: Add custom exceptions, security enhancements, and comprehensive troubleshooting guide (949db3f)
- fix: Address code review feedback for security classes (7d778fb)
- docs: Add comprehensive code review response documentation (cd7ce83)
- Remove CodeQL job from security.yml and update security-summary job's needs (2499200)
- Merge pull request #1 from k9barry/copilot/create-database-watch-folder (49606ef)
- Refactor and fix bugs (3607ae1)
- Initial plan (c41a3cc)
- Add aggregate /api/stats endpoint, fix units.js API path, remove legacy API files, fix SQL field names (2a52a0f)
- Update API documentation for new aggregate stats endpoint (b912930)
- Add logging viewer to dashboard with API endpoints and UI (a532e17)
- Fix code review issues in logging feature (security, error handling, state management) (70e92cc)
- Add missing MYSQL_HOST and POSTGRES_HOST to .env.example (7b32f6b)
- Fix critical bugs in StatsController and LogsController based on code review (194b9a4)
- Add integration tests for LogsController and aggregate stats endpoint (907e0c6)
- Merge pull request #2 from k9barry/copilot/review-repo-and-update-code (092ceb9)
- Initial plan (55368fd)
- Add DBeaver service and fix XML BOM parsing issue (c479d61)
- Update setup.sh for DBeaver and add data directory structure (aa7982e)
- Update README with DBeaver and BOM handling documentation (6325a61)
- Address code review feedback - fix docstring and DBeaver URL handling (9ec1979)
- Fix BOM detection order and improve Codespaces URL handling (1ec2742)
- Update CB_ADMIN_PASSWORD environment variable (a79a52e)
- Merge pull request #3 from k9barry/copilot/add-dbeaver-service-container (a395bd5)
- Initial plan (7de5e48)
- Restore docker-compose.yml file (was accidentally renamed to k9barry) (0c08db9)
- Merge pull request #4 from k9barry/copilot/find-docker-compose-file (dc0e1f3)
- Refactor API routes and update dashboard components for improved clarity and functionality (b600c5e)
- Add files via upload (4b0f377)
- Refactor FileWatcher class to improve database readiness handling and enhance file scanning logic (5f9eeb4)
- Bump phpunit/phpunit from 10.5.61 to 10.5.62 (b7f404e)
- Enhance Dashboard and Units Management (e70db47)
- Merge pull request #5 from k9barry/dependabot/composer/phpunit/phpunit-10.5.62 (aa8671d)
- feat: Enhance call management with jurisdiction and location filters (339b293)
- Add CIFS volume for watchfolder (ec77b1a)
- Initial plan (3e2b621)
- Implement duplicate call_id handling with update logic (2087e5f)
- Update CHANGELOG with duplicate call fix (bf44227)
- Optimize deleteChildRecords and improve test coverage (cc6b046)
- Add documentation for duplicate call_id fix (0d68b07)
- Merge pull request #6 from k9barry/copilot/fix-file-parsing-issues (f4fe4db)
- Initial plan (9c3687c)
- Implement append-only XML processing with UPSERT logic (6efa608)
- Update CHANGELOG with append-only processing changes (e7285ec)
- Fix code review issues: VALUES() deprecation and NULL handling (67a095f)
- Merge pull request #7 from k9barry/copilot/review-xml-file-commit (0f6ba7e)
- Initial plan (7dae23c)
- Fix Qodo review comments: unterminated PHPDoc, add delete logic for unit data, optimize PostgreSQL ID retrieval (8e179c5)
- Merge pull request #8 from k9barry/copilot/address-comments-from-pr7 (ee7304b)
- Reset to clean state (008fb26)
- Initial plan (bd858f5)
- Add FilenameParser utility and optimize file processing logic (2b2ebf4)
- Add documentation, tests, and migration scripts for v1.1.0 (45604ea)
- Fix code review findings: add validation and improve error handling (843bddb)
- Complete v1.1.0: Add optimization summary and finalize implementation (172bcc4)
- Address code review comments: handle tilde filenames, add overflow protection, fix null checks, add unparseable detection (c434e0c)
- Add documentation for tilde format and enhance test assertions (07dcbd3)
- Remove tilde filename support - files with tildes now skipped as unparseable (99756c5)
- Move unparseable files (with tildes) to failed folder automatically (ae1d224)
- Merge pull request #9 from k9barry/copilot/optimize-schema-and-xml-parsing (a7e8ab8)
- feat: Add incident number handling and filtering options for calls and units (2c0091a)
- Initial plan (3ae993f)
- Remove legacy documentation files and update docs index (c883fc8)
- Add comprehensive DOCUMENTATION.md index and update README (339f438)
- Initial plan (f89804c)
- Merge pull request #10 from k9barry/copilot/refactor-documentation (2b8fa75)
- Fix MySQL health check, improve retry logic, and make coverage threshold non-blocking (7d158bd)
- Merge pull request #11 from k9barry/copilot/fix-mysql-health-check (6b33488)
- feat: Enhance filtering options and UI for calls and units, including date range and agency selection (8290740)
- feat: Update dashboard and calls scripts with enhanced filtering options and improved UI elements (d7df42b)
- Initial plan (2ae9884)
- Remove deprecated code and add strict types (18f55a6)
- Improve security tests and add coverage attributes (1ae27cc)
- Merge pull request #13 from k9barry/copilot/improve-code-quality (352e735)
- Initial plan (74f4ef1)
- Add Dozzle service and update logging to use DEBUG/INFO levels (f5ccf37)
- Add user prompt for .env file handling in reset-repo.sh (f2fd838)
- Address code review feedback: regex pattern and logging consistency (17befd7)
- Address PR compliance and code suggestions: improve security (d137dd7)
- Address code review: stack traces at ERROR level, fix Dozzle port config (845f0a1)
- Merge pull request #14 from k9barry/copilot/add-dozzle-service-integration (2b7448f)
- Initial plan (f844d10)
- Fix setup.sh syntax error and add database/schema.sql for CI tests (298420c)
- Merge pull request #15 from k9barry/copilot/review-reset-setup-scripts (62c10cc)
- Add files via upload (c212cef)
- Initial plan (dc26c9f)
- Fix SQL parameter mismatch when updating existing call records (65ba322)
- Merge pull request #16 from k9barry/copilot/fix-container-log-db-entry (834950f)
- feat: Implement comprehensive centralized filter architecture with modal UI (5c13cc7)
- Dashboard improvements: layout optimization, pagination, filters, and map enhancements (a9f6904)
- fix: Map popup now displays call Type, Priority, Status, and Time (796e6bd)
- working version - faster (396648b)
- fix: Recalculate date ranges for quick periods on init to show fresh data (5c5abf8)
- fix: Add database reconnection logic with enhanced logging (a73002f)
- refactor: Remove blue badge counter and clean up legacy code (e4382cc)
- chore: Remove remaining backup files from controllers and views (14eb10e)
- Fix duplicate city display in Recent Calls table (5005e49)
- Add map zoom modal for Recent Calls table (b634d66)
- Initial plan (705f138)
- Add mobile-friendly dashboard infrastructure (6de6c31)
- Update documentation for mobile dashboard (f4d426b)
- Add mobile dashboard test and preview files (7794f4f)
- Fix code review issues - naming convention and event handling (3552557)
- Merge pull request #19 from k9barry/copilot/create-mobile-friendly-dashboard (9bf5717)
- Security audit, documentation rewrite, and code cleanup (6024940)
- Add backup configuration to .env.example (d1bdcc9)
- Delete data/dbeaver directory (a4c8b76)
- Remove Database Manager link from DASHBOARD.md (0923e3c)
- Delete logs/container-logs-2026-01-31T16-34-07.zip (8009953)
- Delete samples directory (41ad280)
- Delete logs directory (946de7c)
- Initial plan (edde222)
- Consolidate migration scripts into init.sql and remove orphaned dbeaver references (74c61b7)
- Fix CI failure: replace schema.sql symlink with standalone file without Docker provisioning (dc6a7a6)
- Fix failing security tests: correct XSS test assertions and SQL injection test isolation (2617a91)
- Fix testPreventDomBasedXss to assert against $json instead of intermediate $safeData (ec628b0)
- Fix 3 SQL injection test failures and performance test issues (549d56a)
- Add validation guard in testUnitsForCallPerformance for seeded call lookup (2cc017c)
- Merge pull request #20 from k9barry/copilot/consolidate-migration-changes (6a1dc99)
- Add CLAUDE.md for Claude Code agents (1d4714f)
- Add design doc: consolidate nws-endpoints into nws-cad (fbd11b7)
- Add implementation plan for nws-endpoints consolidation (8073199)
- feat(notifications): add MissingSecretException (12eddcc)
- fix(notifications): make MissingSecretException constructor private (ac4e961)
- feat(notifications): add SecretRegistry (c4d091e)
- feat(notifications): add RedactingProcessor scrubbing registered secrets (ded6d21)
- feat(notifications): register RedactingProcessor on the global logger (2bd8adf)
- feat(notifications): add Config::secret/secretOptional with SecretRegistry (f6bb00a)
- feat(notifications): add Intent enum (bfb7d7e)
- feat(notifications): add CallProcessedEvent value object (b9f6a32)
- feat(notifications): add in-process EventDispatcher with isolated error handling (223dd3e)
- feat(notifications): add TopicSanitizer (whitelist + collapse) (caa7321)
- feat(notifications): add IncidentDto with explicit row mapping (no extract()) (c0493bb)
- feat(notifications): add notification_channels & notification_send_log tables (6d19cbd)
- feat(notifications): document notification env vars in .env.example (10b0cef)
- feat(notifications): add SendResult and NotificationContext value objects (39cfbae)
- feat(notifications): add NotificationChannel interface (7ce47c4)
- feat(notifications): add NtfyChannel with sanitized topics and bounded retry (ea6be2b)
- feat(notifications): add PushoverChannel with bounded retry (e4bb5f2)
- feat(notifications): add ChannelRepository with per-channel send-log pruning (7e5180f)
- feat(notifications): add NotificationDispatcher with delta-time gate and intent rules (e398031)
- feat(notifications): add bin/notifications.php CLI (list/enable/disable) (407c123)
- feat(notifications): add NotificationsController (read-only) (ce0ce0e)
- feat(notifications): wire /api/notifications/* routes (c07df4d)
- feat(notifications): add read-only /notifications dashboard view (b3d9750)
- feat(notifications): add IntentResolver (Created/Updated/Closed/no-op) (da7bae2)
- feat(notifications): emit CallProcessedEvent from AegisXmlParser after commit (329ed71)
- feat(notifications): register NotificationDispatcher in watcher.php (d3985fd)
- test(security): add topic-injection and secret-redaction guards (3919c3e)
- docs: add NOTIFICATIONS.md (operator + developer reference) (92ad620)
- docs: update README/CLAUDE/CHANGELOG for v1.2.0 notifications (dd151fe)
- refactor(notifications): address final-review minor notes (9c9a828)
- fix(notifications): address Copilot PR review (0938304)
- test(unit): fix risky-test failures surfaced by strict CI (6012663)
- test(unit): add @uses annotations for strict coverage metadata (bc5f93b)
- test(ci): add notification tables to database/schema.sql (025ddb4)
- test(ci): reset AUTO_INCREMENT in cleanTestDatabase (555d30a)
- test(ci): make Response::json testing-mode use a flag, not exit/throw (0f581ad)
- test(integration): fix testSqlInjectionProtectionInDateFilter (3f2cf29)
- test(security): add @uses for SecretRedactionTest's transitive deps (73872de)
- test(performance): add @coversNothing to performance test classes (7a4cec8)
- Merge pull request #21 from k9barry/feature/notifications-consolidation (00ed9ce)
- docs: refresh CLAUDE.md after notifications consolidation (0cb76e2)
- docs(specs): notifications dashboard UI — channel toggle + test send (c23bbeb)
- docs(plans): notifications dashboard UI implementation plan (53c8598)
- feat(notifications): add ChannelFactory for shared channel construction (bf178e1)
- fix(notifications): clean up ChannelFactory test hygiene + style (d416a5a)
- refactor(notifications): use ChannelFactory in watcher (67b530e)
- refactor(watcher): drop dead imports + use ChannelFactory short name (c85a971)
- feat(notifications): add enable endpoint to NotificationsController (9b274b7)
- feat(notifications): add disable endpoint to NotificationsController (090d3ae)
- feat(notifications): add test send endpoint to NotificationsController (b918295)
- feat(api): register notification + bundled /health endpoint routes (53a0708)
- feat(dashboard): notifications channel toggle + test send UI (413cf1a)
- fix(dashboard): notifications view error handling + concurrency (42cce8f)
- docs: changelog entry for notifications dashboard UI (7f6f06c)
- fix(notifications): include log_id in test endpoint response (16f8bf0)
- fix(dashboard): defer notifications IIFE until APP_CONFIG is ready (3ca849e)
- feat(api): add /health endpoint controller (467177c)
- feat(infra): docker healthchecks + TZ propagation + watcher heartbeat (2cc9158)
- fix(config): strip matched surrounding quotes from .env values (494dc13)
- fix(tests): guard cleanTestDatabase from wiping production DB (5eca123)
- fix(tests): route Database::getConnection() to test DB during tests (031586b)
- fix(mysql): bump innodb_redo_log_capacity + add stack.sh wrapper (fdf9c51)
- fix(notifications): self-heal across MySQL restarts via Database::run (23826e9)
- fix(notifications): hydrate common_name/beat/quad and dedupe ntfy topics (c01cf9f)
- docs(specs): add filter refactor design (d465214)
- docs(plans): add filter refactor implementation plan (ee2ab7b)
- chore(docker): enable pcov coverage driver and mount test-setup.sql (522e940)
- fix(parser): upsert via natural keys to avoid duplicate child rows (bee236e)
- feat(notifications): localize send-log timestamps and add summary line (828bada)
- feat(bin): add diagnose-active-calls and stale-child-rows migration (1e2f72d)
- fix(filters): treat empty saved filter state as cleared, fall through to default (8b2be6f)
- feat(db): add filter refactor migration (ref tables, FDID column, indexes) (ba7f8ca)
- feat(db): sync init.sql and schema.sql with filter refactor migration (e419a68)
- feat(seed): reference data JSON and bin/seed-reference.php CLI (c9e7194)
- feat(parser): populate agency_contexts.fdid from XML or ref_agencies fallback (0279c40)
- feat(filtering): add DateRange, InvalidFilterException, SqlFragment, FilterContext value objects (19f73c7)
- feat(filtering): add FilterRegistry per-controller allowlist (766eaf8)
- feat(filtering): add FilterCriteria parser with security limits (7465194)
- feat(filtering): add FilterSqlBuilder with parameterized WHERE/JOIN generation (9906c20)
- feat(filtering): add in-process FilterOptionsCache with TTL + invalidation (7f1acef)
- feat(api): add /api/filter-options endpoint with curated+derived merging (7c8afae)
- feat(parser): invalidate FilterOptionsCache for derived fields after ingest (5a7fe3c)
- refactor(calls): rewire CallsController::index to FilterCriteria/FilterSqlBuilder (f53ce6f)
- refactor(units): rewire UnitsController::index to FilterCriteria/FilterSqlBuilder (45df545)
- refactor(stats): rewire StatsController to FilterCriteria/FilterSqlBuilder (8624c44)
- refactor(stats): migrate calls/units/responseTimes endpoints to FilterCriteria (5a41e20)
- chore(vendor): add Choices.js v10 and Flatpickr v4 (d160ce2)
- feat(js): add FilterState with URL parse/serialize and merge semantics (eaa4a3f)
- feat(js): add filter field components (DateRange, MultiSelect, Text, Status) + registry (c87c780)
- feat(js): add FilterPanel orchestrator with URL sync, restore banner, and compact mode (0a207de)
- feat(views): add shared filter-panel.php partial (6f5f0ac)
- feat(dashboard): replace FilterManager with FilterPanel on desktop (614705b)
- feat(dashboard-mobile): replace mobile filter logic with FilterPanel compact mode (1b03f5f)
- feat(views): add /calls and /units list pages using FilterPanel (09fecaa)
- refactor: remove legacy filter-manager.js and orphan calls.js / units.js (2ba523a)
- test(performance): assert filter queries complete <100ms on 100k rows (b65379f)
- docs: document filter refactor in CHANGELOG and CLAUDE.md (f1e97c0)
- test(performance): relax threshold to 200ms for Docker variance (931fa7c)
- fix(dashboard): position filter panel above content + responsive grid layout (d0e36ec)
- feat(filter): drawer-based filter UI + default 'today + open' (f0be9cc)
- style(filter): polish drawer and summary chips for visual depth (8e03997)
- fix(filter): drop redundant Incident Type, derive agency/ORI/FDID from data (70bac8a)
- fix(api): restore location/related-data DTO in calls list response (5f4460e)
- docs(spec): close-status correctness design (2026-05-09) (7ad00f7)
- docs(spec): expand close-status spec with reopen detection + multi-agency display (68ce6f5)
- fix(calls): close-status correctness — reverse-arrival, CAD inconsistency, reopens (68c4a46)
- perf(calls): two-step ID-then-detail query in calls list endpoint (42991ee)
- feat(ui): consolidate to dashboard-only nav, expand notifications page (30abc19)
- fix(dashboard): align active-calls stat + recent-calls count text with new status semantics (2243dbc)
- docs(spec): align dashboard map height with right column to close footer gap (7e7cf41)
- docs(plan): implementation plan for dashboard map height alignment (021ba31)
- fix(dashboard): drop hard-coded map height in favor of CSS class (bf01dcc)
- fix(dashboard): flex-size map card to fill column height (be8cdd2)
- fix(dashboard): invalidate Leaflet size after init and on resize (5ece34c)
- fix(dashboard): viewport-fill row + ResizeObserver to close footer gap and stop grey-strip retile (7cc0ca7)
- fix(dashboard): use flex sticky-footer instead of viewport calc (401cedb)
- fix(dashboard): tighten margins around dashboard-row-fill so it reaches the footer (037cd11)
- fix(dashboard): use !important to defeat Bootstrap utility specificity (ea925f5)
- docs(spec): dashboard prettify design — gradient banner, stat-card upgrade, table polish, map header (11b06e8)
- docs(plan): implementation plan for dashboard prettify (05e418f)
- feat(dashboard): add prettify component classes (banner, pill, stat-card-v2) (11ead22)
- feat(dashboard): gradient banner header + live pill (6bc57be)
- fix(dashboard): update filters.css chip selectors for renamed banner pill ID (7c94ea3)
- feat(dashboard): stat cards use stat-card-v2 with gradient icon tiles (e800c66)
- feat(dashboard): populate per-stat-card pill badges from filter summary (64dcae6)
- feat(dashboard): gradient card headers + map marker count pill (0ea04fe)
- feat(dashboard): pill-badge styling for Recent Calls priority + status cells (0e8adb1)
- feat(dashboard): flash new Recent Calls rows on refresh (0bf8331)
- feat(dashboard): drive live pill from refreshDashboard success/failure (ae48171)
- fix(dashboard): re-run updateFilterSummary after stats load so Active pill reflects fresh count (f2d3561)
- fix(dashboard): distinct reopened pill + tighter default map zoom (75edd14)
- ops(db): backfill SQL for 6 calls clobbered by Bug A pre-fix (4cf26bb)
- feat(dashboard): top-row stats layout, faster polling, coord sentinel guards (145ae2f)
- style(notifications,analytics): match dashboard banner + tighten card density (e5e4ab5)
- style(notifications): stretch channel cards to fill the viewport down to the footer (51c13ab)
- fix(notifications): make channel cards actually fill column height down to the footer (f6c3315)
- fix(notifications): close the residual gap between channel cards and footer (494caca)
- fix(notifications): clamp page to exactly 100vh so cards fill the viewport (93ab393)
- feat(dashboard): filter access on analytics modal, today/open default (eb359fe)
- fix(ci): make release.yml valid YAML (752f578)
- docs(spec): 72h stale-open call guardrail design (bfe35e6)
- feat(calls): 72h stale-open guardrail (4cb82ed)
- docs(spec): security hardening — proxy-trusted auth + headers + audit identity (279f764)
- docs(plan): security hardening implementation plan (b2a66bd)
- feat(schema): identity-aware audit columns (cc761b8)
- feat(config): csv helper + cors/proxy/notifications keys (92554e9)
- feat(security): TrustedProxy CIDR check (3040eb2)
- feat(security): Identity value object + header extraction (5a1cf2f)
- feat(security): CorsPolicy wraps SecurityHeaders + OPTIONS 204 (8518d5c)
- feat(security): UrlValidator for channel base URLs (a12397b)
- fix(ntfy): reject CR/LF in auth token; auto-prefix Bearer (d5e63a3)
- feat(security): bootstrap.php wires SecurityHeaders, CORS, TrustedProxy, Identity (75524af)
- feat(notifications): URL validation + identity audit on writes (45b1182)
- feat(cli): URL validation in notifications enable (bec1a73)
- feat(deploy): loopback binding + Caddy/nginx samples (611e664)
- chore(security): remove unused RateLimiter (386aa1c)
- test(security): bootstrap trust-guard integration coverage (f355f8e)
- test(security): identity round-trip integration coverage (616076f)
- test(security): direct-access forgery is rejected with no DB writes (982b7b7)
- test(security): CORS exploit smoke coverage (59be524)
- fix(test): make CorsPolicy subprocess test actually verify the short-circuit (d947bc0)
- fix(ci): give MYSQL_DATABASE a distinct value so test-bootstrap guard passes (7c1e89b)
- fix(tests): use Identity::extract instead of reflecting past its private ctor (9aaafb5)
- Merge pull request #22 from k9barry/feat/filter-refactor (ade15c8)

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] — 2026-05-07

### Added
- Notifications module (`NwsCad\Notifications\*`) replacing the standalone `nws-endpoints` repo.
- `notification_channels` and `notification_send_log` tables.
- Read-only `/notifications` dashboard view + `GET /api/notifications/channels`, `GET /api/notifications/log`.
- `bin/notifications.php` CLI for enabling/disabling channels.
- `Config::secret()` and `SecretRegistry`/`RedactingProcessor` for env-var-only secrets and globally-scrubbed logs.
- `NOTIFICATION_DELTA_SECONDS` env var (default 900).

### Changed
- `AegisXmlParser` dispatches `CallProcessedEvent` after commit.

### Security
- Topic sanitizer + URL encoding for ntfy paths.
- Explicit `IncidentDto::fromRow()` mapping (no `extract()`).
- cURL-based ntfy sender with explicit error checking (replaces `@file_get_contents`).

### Deprecated
- `k9barry/nws-endpoints` repository — superseded by this module. See `docs/NOTIFICATIONS.md`.

## [Unreleased]

### Added
- `ChannelRegistry` and `ChannelDescriptor` for plug-in channel registration. Adding a new channel type now requires one class with a `descriptor()` static method plus one line in `src/Notifications/registerChannels.php`.
- `WebhookChannel` — generic HTTP POST channel with JSON-template payload configuration. Covers Slack, Discord, Mattermost, Home Assistant, and similar incoming-webhook integrations via per-row `config_json` templates with `{string}` and `"${array}"` placeholder substitution.
- `HttpPost::postJson()` helper for JSON-body HTTP POST.
- Unified filter system across desktop dashboard, mobile dashboard, and new `/calls` and `/units` list pages.
- New filter vocabulary: call_type, incident_type, nature_of_call (LIKE), agency, ORI, FDID, beat, area, city, location, call_id, unit #, status (open/closed/canceled), and date range with presets.
- New `Api\Filtering\` namespace (`FilterCriteria`, `FilterSqlBuilder`, `FilterRegistry`, `FilterContext`, `FilterOptionsCache`, `DateRange`, `InvalidFilterException`).
- New endpoint `GET /api/filter-options` returning curated reference data + derived option lists.
- New reference tables: `ref_agencies`, `ref_oris`, `ref_fdids`, `ref_beats`, `ref_areas`. Seeded via `php bin/seed-reference.php`.
- New `agency_contexts.fdid` column; populated by `AegisXmlParser` from XML or `ref_agencies` lookup.
- New composite indexes on `calls`, `agency_contexts`, `locations`, `units`, `incidents` (see migration).
- Vendored frontend libraries: Choices.js v10 and Flatpickr v4 under `public/assets/vendor/`.
- Notifications dashboard UI: enable/disable channels and dispatch synthetic test sends from `/notifications` without dropping into the shell. Backed by three new endpoints (`POST /api/notifications/channels/{type}/{enable|disable|test}`) and a shared `Notifications\ChannelFactory` for channel construction.

### Changed
- `ChannelFactory::create()` now dispatches via `ChannelRegistry` instead of a hardcoded `match` on channel type.
- `NotificationsController` and `bin/notifications.php` query the registry for validation, help text, and default `config_json` on first enable.
- The `/api/notifications/{type}/enable|disable|test|clear` endpoints now return HTTP 400 (was 404) for unknown channel types, with the available types listed in `errors.available_types`.
- Consolidated v1.1.0 database migration scripts into init.sql files (migrations already reflected in init schemas)
- Removed redundant `database/mysql/migration_v1.1.0.sql` and `database/postgres/migration_v1.1.0.sql`
- Removed orphaned `data/dbeaver` directory references (no DBeaver service in docker-compose.yml)

### Removed
- Unused `NotificationChannel::type()` interface method (`descriptor()->type` replaces it).
- Legacy `public/assets/js/filter-manager.js` (replaced by `FilterPanel`).
- Mobile filter logic in `mobile.js` (replaced by `FilterPanel` compact mode).
- `Api\Request::filters()` (superseded by `FilterCriteria`).
- `partials/filter-modal.php` and `partials-mobile/filters-modal.php` (replaced by `partials/filter-panel.php`).

### Security (2026-02-15)
- 🔒 **Critical XSS Fixes** - Comprehensive cross-site scripting prevention across all JavaScript
  - Added `Dashboard.escapeHtml()` utility function for consistent HTML escaping
  - Fixed 52 unescaped user data fields in `dashboard-main.js` (call details, narratives, persons)
  - Fixed 51 unescaped fields in `calls.js` (table cells, badges, modal content)
  - Fixed 19 unescaped fields in `units.js` (unit details, popups)
  - Fixed 7 unescaped fields in `maps.js` (map popups)
  - Fixed XSS in `dashboard.js` badge functions (`getPriorityBadge`, `getStatusBadge`)
  - Fixed XSS in `showToast()` notification function
- 🔒 **SQL Injection Prevention** - Enhanced database query safety
  - `DbHelper.php`: Added `validateIdentifier()` for SQL column/table name validation
  - `DbHelper.php`: Added `escapeSeparator()` to prevent injection via separator strings
  - `StatsController.php`: Added LIKE wildcard escaping to prevent pattern injection
  - All methods now validate identifiers before SQL interpolation
- 🔒 **CORS Security Fix** - Fixed bypass vulnerability in `SecurityHeaders.php`
  - Now properly validates Origin header (empty/null no longer bypasses checks)
  - Added `Vary: Origin` header per CORS specification
  - Improved origin validation against allowed list
- 🔒 **Logs Controller Hardening** - Comprehensive security for log viewing
  - Disabled by default in production environments
  - Added log level whitelist validation (DEBUG through EMERGENCY only)
  - Added filename validation with path traversal prevention
  - Added realpath verification to restrict access to configured log directory
- 🔒 **Input Validation** - Improved request handling
  - `Request.php`: JSON parsing now uses `JSON_THROW_ON_ERROR` with try/catch
  - `SearchController.php`: Added coordinate range validation (lat ±90, lng ±180)
  - `SearchController.php`: Added radius range validation (0-100 km)

### Changed (2026-02-15)
- Removed dead/legacy code from `calls.js` (21 lines of unused rendering code)
- Removed duplicate `escapeHtml()` function from `units.js` (now uses global)
- Improved PHPDoc documentation across security-critical files
- Enhanced error handling in API request functions

### Documentation (2026-02-15)
- 📚 **Complete Documentation Rewrite** - All documentation updated to reflect current codebase
  - Rewrote `README.md` with cleaner structure and tables
  - Rewrote `DOCUMENTATION.md` as quick reference index
  - Rewrote `docs/README.md` with component summary
  - Rewrote `docs/API.md` with endpoint tables and examples
  - Rewrote `docs/DASHBOARD.md` with desktop and mobile guides
  - Rewrote `docs/TESTING.md` with test suite details
  - Rewrote `docs/TROUBLESHOOTING.md` with quick diagnostics
- 🗑️ **Removed Legacy Documentation** - Cleaned up outdated fix notes
  - Removed `ANALYTICS-FILTER-FIX.md`
  - Removed `ANALYTICS-FIXES-FEB3-2026.md`
  - Removed `ANALYTICS-ISSUES-EXPLAINED.md`
  - Removed `FINAL-SUCCESS-SUMMARY.md`
  - Removed `FRONTEND-FIX-COMPLETE.md`
  - Removed `PERFORMANCE-OPTIMIZATIONS.md`
  - Removed `PERFORMANCE-QUICK-START.md`
  - Removed `PERFORMANCE-ROLLOUT-PLAN.md`
  - Removed `ROUTING-FIX-2026-02-03.md`

### Added (Mobile Dashboard - 2026-02-14)
- 📱 **Mobile-Friendly Dashboard** - Complete mobile-optimized interface for CAD data visualization
  - Automatic device detection using `jenssegers/agent` package
  - Dedicated mobile view served to mobile devices and tablets
  - Desktop view remains unchanged and fully functional
- 📋 **Mobile Calls List** - Primary mobile view showing recent calls
  - Card-based layout optimized for touch interactions
  - Quick-view call information with badges for status and priority
  - Tap any call to view full details in modal
  - Pull-to-refresh functionality for manual updates
  - Auto-refresh every 30 seconds
- 🎨 **Mobile-Specific UI Components** - Touch-friendly interface elements
  - Fixed header with app branding and live indicator
  - Horizontal scrollable stats cards (4 key metrics)
  - Bottom navigation bar for quick access to main sections
  - Full-screen modals for filters, call details, and analytics
  - Touch-optimized buttons (minimum 44x44px tap targets)
- 🔍 **Mobile Filters Modal** - Complete filtering system for mobile
  - Quick select buttons for time periods (Today, Yesterday, 7/30 Days)
  - Dropdown filters for Jurisdiction, Agency, Status, Priority
  - Text input for Call Type search
  - Reset and Apply actions with instant feedback
- 📊 **Mobile Analytics Modal** - Charts and statistics on mobile
  - Call Volume Over Time chart
  - Call Types Distribution chart
  - Priority Distribution chart
  - Status Distribution chart
- 🗺️ **Mobile Map View** - Interactive map optimized for mobile screens
  - Full-height map display with touch controls
  - Accessible via bottom navigation
  - Call markers with popups showing call details
- 🎯 **Responsive Design** - Optimized for all mobile screen sizes
  - Support for 360px to 768px screen widths
  - Adaptive layouts for phones and tablets
  - Portrait and landscape orientation support
- ⚡ **Mobile Performance Optimizations**
  - Minimal JavaScript footprint for fast loading
  - Lazy loading of charts and maps
  - Optimized CSS with mobile-first approach
  - Efficient API calls with pagination

### Added (Dashboard Consolidation - 2026-02-01)
- 📊 **Analytics Modal** - Full analytics and charts accessible from dashboard
  - 4th stat card opens fullscreen analytics modal
  - All charts from analytics page now in modal
  - No separate analytics page needed
- 🎯 **Modular Dashboard Architecture** - Dashboard broken into 6 reusable components
  - `partials/filter-summary.php` - Active filters bar (19 lines)
  - `partials/stats-cards.php` - 4 stat cards with actions (74 lines)
  - `partials/map-and-table.php` - Map + recent calls table (57 lines)
  - `partials/filter-modal.php` - Filter configuration modal (118 lines)
  - `partials/call-detail-modal.php` - Call details modal (17 lines)
  - `partials/analytics-modal.php` - Analytics charts modal (152 lines)
  - Main dashboard.php reduced from 284 to 29 lines
  - Easier maintenance and updates to individual sections
- 🚛 **Units Quick View Popover** - Hover/click units button to see assigned units
  - Shows unit numbers, types, and current status
  - Quick status badges (Clear, On Scene, Enroute, Dispatched, Assigned)
  - Highlights primary unit
  - Link to full call details if needed
  - No need for separate action - units button and action button have different purposes

### Removed (Dashboard Consolidation - 2026-02-01)
- ❌ **Separate Page Navigation** - Removed /calls, /units, /analytics routes
  - Everything now accessible from dashboard through modals
  - Cleaner UX without page navigation
  - Faster workflows (no page loads)
- ❌ **DBeaver Service** - Removed CloudBeaver database manager
  - Removed from docker-compose.yml
  - Removed navigation link
  - Reduced docker overhead

### Added (Dashboard Interactivity - 2026-02-01)
- 🎯 **Auto-updating filter dropdowns** - Jurisdiction and Agency dropdowns now auto-reload when Quick Select period changes
  - Dropdowns immediately reflect only jurisdictions/agencies with data in selected time period
  - Filters are applied before fetching dropdown options (no invalid options shown)
  - Works seamlessly with all Quick Select periods (Today, Yesterday, Last 7 Days, etc.)
- 🪟 **Full Call Details Modal on Dashboard** - Complete call information without leaving dashboard
  - Click any table row or View button to open comprehensive modal
  - Shows all call information: Call details, Location, Caller info
  - Displays Agency Contexts, Incidents, Assigned Units with timestamps
  - Shows Persons Involved and Narratives  
  - No "View Full Details" button needed - everything is shown
  - Same rich modal as on Calls page
- 📊 **Smart stat card behaviors** - Different actions for different cards
  - **Total Calls** → Opens filter modal for custom filtering
  - **Active Calls** → Filters dashboard to active calls in-place
  - **Closed Calls** → Filters dashboard to closed calls in-place
  - **Available Units** → Navigates to Units page (unchanged)

### Changed (Dashboard Layout Optimization - 2026-02-01)
- 📊 **Dashboard Map Layout** - Optimized for Madison County viewing
  - Map reduced to 1/4 page width (col-lg-3) for more efficient use of space
  - Map height increased to 800px to show entirety of Madison County Indiana
  - Recent Calls table expanded to 3/4 page width (col-lg-9)
  - Table displays 20 calls (up from 10) with full details
  - Added sticky table header for better scrolling
  - Removed duplicate "Recent Calls" card section
  - Consolidated all call details into single comprehensive table
  - Improved space utilization: 25% map + 75% data

### Added (Database Protection - 2026-02-01)
- 🔒 **Database Backup Script** (`backup-database.sh`) - Automated database backups
  - Creates timestamped, compressed SQL dumps
  - Supports both MySQL and PostgreSQL
  - Automatic cleanup of backups older than 30 days (configurable)
  - Shows backup size and lists recent backups
  - Ready for cron automation
- 🔄 **Database Restore Script** (`restore-database.sh`) - Interactive backup restoration
  - Lists all available backups with timestamps
  - Creates safety backup before restore
  - Strong confirmation prompts (type "RESTORE")
  - Automatic database type detection
- 📚 **Backup Guide** (`docs/BACKUP_GUIDE.md`) - Complete backup/restore documentation
  - Setup instructions for automated backups
  - Recovery scenarios and troubleshooting
  - Security best practices
  - Example workflows

### Changed (Database Protection - 2026-02-01)
- ⚠️ **Enhanced reset-repo.sh** - Stronger database deletion protection
  - Added large red warning banner before database deletion
  - Requires typing "DELETE" (all caps) to confirm
  - Automatically creates backup before deletion
  - Skips database deletion in non-interactive mode (CI/CD safe)
  - Shows backup location after creation

### Fixed (Dashboard Fixes - 2026-02-01)
- ✅ **Accidental data loss prevention** - Multiple safeguards added to prevent database deletion
- ✅ **Backup directory** - Added `/backups/` to `.gitignore` to protect sensitive data
- 🔧 **Filter dropdown sync** - Fixed Quick Select not filtering Jurisdiction/Agency dropdowns
  - Dropdowns now use current date range when loading options
  - No more stale or invalid values appearing in dropdowns  
  - Date filters are applied to currentFilters state before dropdown reload
- 🔧 **Dashboard element IDs** - Fixed table not loading after modularization
  - Fixed `calls-table-body` → `recent-calls-body` (3 locations)
  - Fixed `recent-activity-title` → `recent-calls-title`
  - Fixed map element ID: `map` → `calls-map`
  - Fixed filterDashboard to actually refresh dashboard data
  - Fixed analytics top call type to use correct API field (top_call_types)
  - Recent calls, map, and stat card filtering now work correctly
- 🔧 **Analytics call counts** - Fixed inflated counts in Call Distribution chart
  - Changed `COUNT(*)` to `COUNT(DISTINCT c.id)` in top_call_types query
  - Calls with multiple agencies now counted only once
  - Distribution chart now shows accurate call counts
- 🔧 **Dozzle logs link** - Fixed navbar Logs button not working
  - Updated default port from 9999 to 8081 (external Dozzle container)
  - Link now correctly opens Dozzle log viewer

### Added (Dashboard & Filter Improvements)
- 🎯 **FilterManager Class** (`public/assets/js/filter-manager.js`) - NEW centralized filter management
  - Single source of truth for all filtering across dashboard pages
  - URL parameter support for shareable filtered views
  - Real-time debounced search (300ms) - search as you type
  - Automatic date field visibility toggle (only show when "Custom Range" selected)
  - Smart jurisdiction/agency loading from filtered results (descending sort)
  - Centralized date range calculation with proper boundaries
  - Filter validation and sanitization
  - Active filter badges with individual remove buttons
  - ~300 lines of duplicate code eliminated across 4 pages
- 🧪 **Comprehensive Filter Testing Suite** (`tests/Integration/ApiFilteringTest.php`)
  - 20+ test cases covering all filter parameters
  - Date range filtering tests (date_from, date_to, combined)
  - Status filtering tests (active/closed calls via closed_flag)
  - Agency type and jurisdiction filtering tests
  - Combined filter scenario tests (2-3 filters simultaneously)
  - Search functionality tests (LIKE queries, pattern matching)
  - SQL injection protection tests for all filter parameters
  - Edge case tests (NULL values, invalid dates, empty results, case sensitivity)
  - Performance tests for complex multi-filter queries (<100ms benchmark)
  - Seeded test data with 7 calls, multiple agencies/jurisdictions
- 📊 CI/CD status badge to README.md showing test pass/fail status
- 📚 Enhanced test documentation in tests/README.md with filter testing guide
- 🎨 CSS hover effects for clickable stat cards with smooth transitions

### Changed (Dashboard UX Improvements)
- **🔍 Complete Filter System Refactor**
  - Refactored all 4 dashboard pages to use new FilterManager class
    - `calls.js` - Calls list and tracking
    - `units.js` - Unit locations and status
    - `dashboard-main.js` - Main overview dashboard
    - `analytics.js` - Advanced analytics and reports
  - Removed ~300 lines of duplicate filter handling code
  - Unified filter behavior across all pages
  - Consistent sessionStorage and URL parameter handling
  - Real-time search now works across all pages
- **🗺️ Map Layout**: Changed from full-width (col-lg-8) to half-width portrait (col-lg-6)
  - Better fits Madison County's shape and boundaries
  - Increased height from 500px to 600px for improved visibility
  - Applied to dashboard and units pages
- **🗺️ Map Center**: Updated default center to Madison County, Indiana
  - Coordinates: 40.1184°N, 85.6900°W (Anderson, IN area)
  - Default zoom level: 10 (shows county + surrounding area)
  - Replaces generic US center (Lebanon, Kansas)
  - Applied to both map initialization and "no data" fallback
  - Updated maps.js default center
  - Updated units.js fallback center (when no units have location data)
- **📊 Stat Cards Optimization** (Dashboard page):
  - Reduced from 6 cards to 4 essential metrics
  - **Kept**: Total Calls, Active Calls, Closed Calls, Available Units
  - **Removed**: Avg Response Time, Top Call Type (less critical)
  - Changed layout from col-md-2 to col-md-3 for better spacing
  - Made all remaining cards clickable with `cursor: pointer`
  - Added visual feedback: lift effect on hover, shadow enhancement
  - Cards navigate to filtered views (e.g., Active Calls → Calls page filtered to active)
- **🧭 Navigation Cleanup**: Removed redundant page buttons from dashboard header
  - Streamlined dashboard title area (no navigation buttons)
  - Kept "Return to Dashboard" buttons on sub-pages (calls, units, analytics)
- **📱 Improved Empty States**: Enhanced "No data" messaging across all sections
  - Recent Activity sections show clear empty states
  - Tables display friendly "No data found" messages
  - Charts show "No data available" with icon

### Fixed
- ✅ **"Yesterday" filter returning 0 calls** - Fixed date range calculation to include today (yesterday through now)
- ✅ **"Yesterday" and date filters not returning all data** - Fixed API date_to comparison to include entire day by appending ' 23:59:59'
- ✅ **"Today" filter not populating call list** - Fixed date calculation with proper boundaries
- ✅ **Jurisdiction dropdown not showing filtered results** - Now loads from current filters, sorted descending
- ✅ **Custom date fields always visible** - Now hidden unless "Custom Range" selected in quick period
- ✅ **Dropdown filters not sorted** - All dropdowns now sort descending (Z-A)
- ✅ **Search field only working on submit** - Now real-time with 300ms debouncing
- ✅ **Filter inconsistency across pages** - All pages now use centralized FilterManager
- ✅ **Units page map not centering on Madison County** - Fixed fallback coordinates when no unit locations
- ✅ **Clear filters button not working** - Fixed ID mismatch (clear-filters-btn → clear-filters)
- ✅ **Stats cards not updating on filter change** - Added comprehensive debug logging to trace filter application
- ✅ Map initialization now uses Madison County coordinates by default
- ✅ Stat card hover effects properly applied with CSS
- ✅ Dashboard-main.js updated to only update 4 stat cards (removed avg response/top call type references)
- ✅ Empty data sections properly handle and display no-data states

### Improved
- 🎯 Filters now shareable via URL parameters - bookmark and share filtered views
- 🔍 Real-time search with instant feedback as you type (300ms debounce)
- 🎨 Filter badges show active filters with individual remove buttons
- 🗺️ Maps now better suited for Madison County geography (portrait vs landscape)
- 🧹 Cleaner codebase with ~300 lines of duplicate code eliminated
- 🎨 Enhanced visual feedback on interactive elements
- 🧪 Comprehensive test coverage for filtering functionality ensures reliability
- 📊 All filter parameters now thoroughly tested for security and correctness
- 🚀 Better UX with clickable stat cards providing direct navigation

## [Unreleased - Previous]

### Added
- **Dozzle Docker Log Viewer** - Real-time container log monitoring service
  - Added Dozzle service to docker-compose.yml (port 9999, localhost-only by default)
  - Added DOZZLE_PORT, DOZZLE_USERNAME, DOZZLE_PASSWORD configuration to .env.example
  - Logs link in navigation now opens Dozzle in new tab
  - Security: Binds to localhost only, supports optional authentication
- **Enhanced DEBUG Logging** - Comprehensive step-by-step logging throughout codebase
  - DEBUG level shows detailed step-by-step processing information
  - INFO level shows only major milestones
  - Updated FileWatcher.php with DEBUG logging for file scanning, stability checks, processing
  - Updated AegisXmlParser.php with DEBUG logging for XML parsing, database operations
  - Updated Database.php with DEBUG logging (sanitized, no sensitive credentials exposed)
  - Updated watcher.php with DEBUG logging for service startup
- Agency and Jurisdiction filters to Analytics page
- Call counts to Call Distribution chart labels
- Dynamic calculation of busiest hour from actual call data
- Dynamic calculation of most active unit from actual units data
- "Incidents by Jurisdiction" chart replacing "Call Volume Over Time"
- Unique constraints on units table for (call_id, unit_number)
- Unique constraints on unit_logs table for (unit_id, log_datetime, status, location)
- Unique constraints on narratives table for (call_id, create_datetime, create_user, text)
- Location field to unit_logs table to store log location data

### Changed
- Logs page replaced with Dozzle external service for real-time container log viewing
- Removed internal logs.php view and logs.js frontend components
- Logs navigation link now opens Dozzle in a new browser tab
- LOG_LEVEL environment variable now controls verbosity (DEBUG for detailed, INFO for milestones)

### Removed
- Internal logs page frontend (logs.php, logs.js) - replaced by Dozzle service
- /logs route from dashboard routing

### Fixed
- Analytics page stats calculation using correct data sources
- SQL GROUP BY compatibility with MySQL strict mode
- API jurisdiction filtering to use incidents table instead of agency_contexts
- XML file processing now appends new data instead of replacing existing records
- Unit logs and narratives are now preserved when processing updated XML files
- Units are now updated (UPSERT) rather than deleted and recreated

### Changed
- XML parser now uses INSERT IGNORE for cumulative child records (narratives, unit_logs)
- XML parser now uses UPSERT for units to update timestamps without losing child records
- Removed deleteChildRecords() method that was deleting all child data on updates
- Database schema updated to support idempotent XML imports

## [1.1.0] - 2026-01-30

### Added
- **FilenameParser utility class** for parsing CAD XML filenames
- Intelligent file version detection and processing optimization
- Automatic skipping of older file versions for the same call
- Enhanced `processed_files` table with `call_number` and `file_timestamp` columns
- Database migration scripts for MySQL and PostgreSQL (v1.1.0)
- Comprehensive documentation:
  - File Processing Optimization guide
  - Database Schema Diagram
- Test script for validating file processing optimization
- 82% reduction in file processing overhead (tested with 89 sample files)

### Changed
- FileWatcher now groups files by call number and processes only latest versions
- AegisXmlParser now stores call metadata in processed_files table
- Enhanced logging to show version analysis and skipped files
- Database indexes added for efficient call_number and file_timestamp queries

### Performance
- Processing optimization: 82% reduction in database operations
- Example: 19 versions of same call → only 1 file processed
- 73 of 89 sample files automatically skipped as older versions

## [1.0.1] - 2026-01-25

### Added
- Dashboard main page with live data refresh
- Units tracking page with real-time status
- Analytics page with comprehensive reporting
- Auto-detection of GitHub Codespaces environment for API URLs
- Comprehensive JavaScript logging for debugging

### Fixed
- Dashboard API connection issues in GitHub Codespaces
- Async/await initialization in dashboard JavaScript
- API base URL configuration for both local and Codespaces environments
- Field name mapping to match database schema

### Changed
- Improved error handling in all dashboard pages
- Enhanced logging throughout JavaScript modules
- Updated APP_CONFIG to auto-detect environment

## [1.0.0] - 2025-01-18

### Added
- Initial release
- XML file parsing and processing
- REST API with 19 endpoints
- MySQL and PostgreSQL support
- Comprehensive test suite
- CI/CD pipeline
- Documentation

## [Unreleased] - 2026-02-01

### Changed
- **Dashboard Layout Improvements**
  - Moved map to top section (40% width, 600px height)
  - Positioned 4 stat cards next to map in 2x2 grid (60% width)
  - Moved Recent Calls table to full-width below map/stats
  - Repositioned filter label to right side next to "Change Filters" button
  - Created new partials:
    - map-and-stats.php (93 lines) - Combined map + stats cards
    - recent-calls-table.php (42 lines) - Standalone table component
  - Updated partials/README.md with new structure documentation
  - Reduced table max-height from 770px to 500px for better proportions


### Fixed
- **Units Popover Bug**
  - Fixed API response format handling: Changed from `result.items` to `result.data` to match actual API response structure
  - Fixed primary unit badge: Changed from `u.primary_unit` to `u.is_primary` (correct field name)
  - Units popover now displays correctly with unit details and status


### Changed
- **Dashboard Layout Restructured**
  - Recent Calls table moved below stats cards (right column only)
  - Map height increased to 800px to fill left column
  - Layout now: Map (left 40%) | Stats Cards + Recent Calls (right 60%, stacked)
  - Table max-height reduced to 400px for better proportions
  - Removed standalone recent-calls-table.php partial (merged into map-and-stats.php)


### Changed
- **Dashboard Header Optimization**
  - Merged filter controls into header row (single row layout)
  - Dashboard title on left, filter summary and button on right
  - Removed standalone filter-summary.php partial (merged into dashboard.php)
  - Map now starts immediately below header (more vertical space)
  - Reduced header margin from mb-4 to mb-3 for tighter layout


### Fixed
- **Recent Calls Table Positioning**
  - Fixed HTML structure: Table now correctly positioned in right column below stats cards
  - Corrected closing div tags that were pushing table outside the column layout


### Added
- **Map Boundary Restrictions**
  - Map now restricted to Madison County, Indiana boundaries
  - maxBounds set to prevent panning outside county (~40mi buffer)
  - maxBoundsViscosity: 1.0 creates "hard" boundaries (can't drag outside)
  - minZoom: 9 prevents zooming out too far
  - Coordinates: SW (39.90°N, 85.90°W) to NE (40.35°N, 85.45°W)

### Confirmed
- **Units Popover "Full Details" Button**
  - Already correctly opens call details modal via viewCallDetails() function
  - No changes needed - functionality already working as intended


### Fixed
- **Units Popover "Full Details" Button**
  - Fixed button not opening call details modal
  - Added event.stopPropagation() to prevent click event from bubbling
  - Added setTimeout(100ms) to ensure popover closes before modal opens
  - Button now correctly closes popover and opens call details modal


### Changed
- **Call Details Modal Layout**
  - Moved Agency Contexts section to top (above Call Information section)
  - Provides better overview of multi-agency response context first

### Fixed
- **Units Popover "Full Details" Button (Improved Fix)**
  - Replaced inline onclick handler with proper event listener
  - Button now has ID and data-call-id attribute
  - Event listener attached after popover creation with setTimeout
  - More reliable than inline handler approach
  - Properly closes popover (150ms) then opens call details modal


### Added
- **Recent Calls Table Pagination**
  - Added Previous/Next navigation buttons
  - Shows "Page X of Y" info
  - Displays "Showing X-Y of Z calls"
  - Pagination controls only appear when there are multiple pages
  - 20 calls per page
  - Maintains current filters when navigating pages
  - Event listeners for prev/next buttons

### Changed
- **Recent Calls Table**
  - Added pagination footer below table
  - Table now supports viewing all filtered calls (not just first 20)
  - loadRecentCalls() function now accepts page parameter
  - Tracks currentCallsPage, totalCallsPages, currentCallsTotal state


### Fixed
- **Jurisdiction Filter Dropdown - Missing Jurisdictions**
  - **Root Cause**: Stats API had `LIMIT 10` on jurisdiction query, only showing top 10 most frequent jurisdictions in dropdown
  - **Impact**: Less frequent jurisdictions (like 48020) were not available as filter options
  - **Solution**: Removed LIMIT 10 from calls_by_jurisdiction query in StatsController
  - **Result**: All jurisdictions now appear in filter dropdown (went from 10 to 17 in current dataset)
  - Example: Call 1093 with jurisdiction 48020 now filterable


### Changed
- **Default Filter Period**
  - Changed default quick select filter from "Last 7 Days" to "Today"
  - Dashboard now shows today's calls by default on first load
  - User preference stored in session still takes precedence


### Fixed
- **Default Filter Initialization**
  - FilterManager now sets quick_period: 'today' when no saved filters exist
  - Dashboard immediately applies 'Today' filter on first load
  - Filter summary correctly shows "Today" instead of "All Time" on first load


### Changed
- **Map Zoom Limits**
  - Increased maximum zoom level from 19 to 21 (2 additional zoom levels)
  - Allows users to zoom in closer for more detailed street-level views
  - Both map container and tile layer maxZoom increased to 21
  - Minimum zoom (9) remains unchanged to prevent zooming out past county view


### Changed
- **Map Minimum Zoom Level**
  - Increased minimum zoom from level 9 to level 12 (3 levels more zoomed in)
  - Prevents users from zooming out to county-wide view
  - Keeps map focused on more detailed city/neighborhood level
  - Users cannot zoom out past level 12 (was level 9)
  - Both map container and tile layer minZoom increased to 12

  - Updated default starting zoom from 10 to 12 (matches new minimum)
  - Map now initializes at city/neighborhood level instead of county-wide


### Changed
- **Map Minimum Zoom Adjusted**
  - Changed minimum zoom from level 12 to level 10
  - Allows slightly more zoom out capability
  - Still prevents extreme zoom out (original was level 9)
  - Default starting zoom remains at level 10 (matches minimum)
  - Balance between detail and overview


### Changed
- **Map Zoom Level to 11**
  - Changed minimum zoom from level 10 to level 11
  - Changed default starting zoom from level 10 to level 11
  - Slightly more focused view with better detail
  - Good balance between coverage and detail for Madison County


### Changed
- **Map Zoom Level to 10.5 (Fractional)**
  - Enabled fractional zoom levels with zoomSnap: 0.5
  - Changed minimum zoom from 11 to 10.5
  - Changed default zoom from 11 to 10.5
  - Added zoomDelta: 0.5 for smoother zoom controls
  - Provides zoom level between 10 and 11 for optimal view

