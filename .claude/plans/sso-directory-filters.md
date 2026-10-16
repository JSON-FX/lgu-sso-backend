# Feature: app-scoped directory filters and related-field search

## Context and decisions

Approved Connect colleague discovery needs server-side office and position filtering across pages, plus search over names, position titles, office names and abbreviations. The contract is settled; no clarification is needed. Work remains local. Production deployment and starting MIS staging are outside this task.

Base: `origin/main` at `eacded3527f0c2d84dcd118aac44db395390bfca`. Branch: `feature/sso-directory-filters`. Laravel 12 / PHP / Pest; tests use in-memory SQLite.

Relevant files:
- `app/Http/Controllers/Api/V1/SsoDirectoryController.php:17`: list validation, scoped relation, grouped term search and simple pagination.
- `app/Models/Employee.php:145`: office/position relationships; current application grants remain the scope.
- `tests/Feature/SsoDirectoryTest.php:10`: synthetic employee and credential fixtures, eligibility, privacy and pagination assertions.
- `docs/consumer-directory.md:15`: authoritative list contract.
- `routes/api.php:104`: existing credential, no-store and application-rate middleware; unchanged.

Reuse Eloquent `orWhereHas` within each existing grouped search condition. Related-field OR clauses must never escape that group. Keep eager loading and stable ordering. References: [Laravel relationship existence](https://laravel.com/docs/12.x/eloquent-relationships#querying-relationship-existence), [integer validation](https://laravel.com/docs/12.x/validation#rule-integer).

## Acceptance criteria

1. Optional `office_id` and `position_id` are positive integers. Supplied empty/invalid values return 422; unknown positive IDs yield empty results. No existence validation leaks record state.
2. Both filters combine with AND and apply before pagination, alongside existing roles and eligibility restrictions. Historical inactive reference assignments remain searchable/filterable.
3. Successful lists include `meta.filters_applied: true` when either valid filter was supplied, including empty/out-of-range pages. Unfiltered metadata remains unchanged.
4. Each whitespace-separated search term can match a name part, office name/abbreviation or position title. Terms combine with AND; wildcard escaping and bound values remain unchanged. Null relations do not suppress matching names.
5. Current grant scope, active/password/deleted eligibility, role selection, no-store responses, rate limits, lookup and legacy routes remain unchanged.

## Ordered tasks

1. UPDATE controller validation, ID constraints, grouped relationship search and conditional metadata. Validate: `php -l app/Http/Controllers/Api/V1/SsoDirectoryController.php` and `php artisan test --compact --filter=SsoDirectoryTest`.
2. UPDATE Pest coverage for individual/combined filters before pagination, unknown IDs, invalid inputs, related-field literal matching, cross-field terms, missing/historical relations and authorization boundaries. Validate: `php artisan test --compact --filter=SsoDirectoryTest`.
3. UPDATE contract and README for parameters, acknowledgment, search and unchanged boundaries. Validate: `git diff --check`.
4. Run full `php artisan test --compact`, changed-file Pint, and full Pint to identify pre-existing failures. Review all changed files, save implementation/review reports, then provide branch/commit and explicit undeployed status to Connect. No UI changes, browser tests or schema changes are needed; HTTP feature tests exercise the application in process.

## Risks and assumptions

- Grouping errors could widen application visibility; regression fixtures must include matching excluded and other-application employees.
- Related-field substring search uses existing database collation and may scan rows. No cache, index migration or capacity claim is introduced.
- Consumers omit unused filter parameters and must reject filtered responses lacking the acknowledgment.

## Validation environment amendment — 2026-10-16

The bare full-suite command requires a signing key. Run it with disposable `JWT_SECRET` and `APP_KEY` values in the test process, explicitly setting `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and `DB_DATABASE=:memory:`. Do not load or modify production configuration. The existing CI also supplies an isolated signing key. Full Pint has two existing migration-format failures; changed-file Pint is the CI gate.
