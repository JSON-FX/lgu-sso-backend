# Feature: Application reference directory

## Intent and acceptance
Applications use canonical SSO employee UUIDs, office IDs and position IDs in business records. Employee discovery remains limited to the credential-derived application's current grants. Login continues through the Portal and existing authorization-code exchange.

1. Extend existing `/api/v1/sso/directory` list/detail with `office_id`, `position_id`, `position_title`; retain all existing fields, roles validation, eligibility, pagination and no-store behavior.
2. Add credential-protected `/api/v1/sso/offices` and `/positions`, plus numeric-ID detail. Lists contain active entries, literal text search and bounded pagination. Detail includes inactive historical records with `is_active`.
3. Reconcile October 14 exports: 85 active offices, 303 active positions. Normalize source spelling and HTML entities; retain separate abbreviations. Preserve existing IDs and employee foreign keys through four actual office metadata renames, one formatting-only source rename, and retirement of one office/three positions. Never merge records or import employee counts as people.
4. Portal office dropdowns use active records. Existing historical employee relations remain readable.
5. Verify application isolation, credentials, shared rate limits, no-store, projection, search/pagination, nullable relationships, stable IDs and repeatable seeding. Run full isolated Pest suite and Pint. Build/recreate only the local SSO API, back up local data before seeding, and provide working local endpoints.

## Settled scope
User confirmed application-granted employees only and local testing while off the office network. No remote deployment, main push, consumer-app changes, new login flow, or employee import. Staging and production IDs are environment-specific. Current position is the designation; no additional designation schema is requested.

## Context
- `app/Http/Controllers/Api/V1/SsoDirectoryController.php`: existing server-to-server directory, explicit role filter and eligibility.
- `routes/api.php:78`: client credentials, per-application rate limit, private responses.
- `app/Http/Controllers/Api/V1/OfficeController.php`: shared portal/admin lookup.
- `database/seeders/OfficeSeeder.php`, `PositionSeeder.php`: canonical lists and upserts.
- `tests/Feature/SsoDirectoryTest.php`, `DirectorySeedersTest.php`: Pest integration patterns.
- Workspace `CLAUDE.md`: SSO central/consumer boundary; isolated SQLite testing.
- [Laravel Form Requests](https://laravel.com/docs/12.x/validation#form-request-validation): shared query validation.
- [Laravel Eloquent](https://laravel.com/docs/12.x/eloquent#retrieving-or-creating-models): preserve primary keys with updates.

## Ordered implementation tasks
1. Update seeders transactionally with explicit old abbreviations for renamed offices. Keep retired source rows inactive; retain other omitted records as inactive history. Add ID/foreign-key/idempotence tests. Validate: `php artisan test tests/Feature/DirectorySeedersTest.php`.
2. Add directory fields/eager loading, reference query Form Request and controller, register routes with existing middleware. Filter portal office options by active state. Add focused projection/permission/pagination/history tests. Validate: `php artisan test tests/Feature/SsoDirectoryTest.php tests/Feature/SsoReferenceDirectoryTest.php`.
3. Update existing backend README/consumer-directory docs and Portal integration guide/README in isolated worktree. Include curl examples, current-state versus historical snapshots, IDs, inactive records and rollout seed commands. Validate: inspect routes and doc consistency.
4. Run `vendor/bin/pint --dirty`, full `php artisan test`, and `git diff --check`; review complete changed/new files. Record evidence in implementation/review reports.
5. Back up local database, build local API from this branch, replace only local API service using Compose context override, run only OfficeSeeder and PositionSeeder, verify HTTPS requests with an isolated local application and existing grants. Keep generated client secret in a mode-600 local file. Report actual endpoint status and counts; no hosted deployment claim.

## Risks and safeguards
Never truncate reference tables or remap employees based on export counts. Known renamed records prefer their previous abbreviation and preserve IDs; conflicting old/new matches fail rather than merge. Retired entries remain resolvable by ID. No private employee fields or client secrets in browser JavaScript. Consumers must authorize requests separately; directory data is not authorization proof. No schema migration or dependency change needed.

## Questions
None unresolved. MAO prefixes follow existing Municipal Administrator's Office conventions; `MMO - Project Monitoring` replaces the old distinct standalone eight-employee source entry. The 53-employee Municipal Project Monitoring entry remains separate. MPSO standalone record is retired without moving any employees.


## Amendments
- 2026-10-14: Live local validation found six offices and 43 position titles from older data, absent from the source snapshot. Treat the provided exports as the authoritative active set: retain all omitted records as inactive, preserve their IDs and employee links, and verify exactly 85/303 active choices after repeat imports. This replaces the initial assumption that keeping omitted entries selectable would satisfy the updated list.
