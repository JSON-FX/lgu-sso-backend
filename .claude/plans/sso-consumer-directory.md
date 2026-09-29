# SSO consumer directory

## Authorization and scope

The owner explicitly authorized a separate SSO task to unblock Connect LC-016 on September 29, 2026. Work in an isolated checkout based on backend `7aea795`. Keep the existing `/sso/employees` contract and operational services unchanged. No migrations, identity policy changes, or new dependencies.

## Contract and implementation

1. Add `SsoDirectoryController` and `/api/v1/sso/directory` plus `/api/v1/sso/directory/{uuid}` behind existing application credentials and per-application rate limits. Add outer response middleware for private/no-store responses, including validation and credential failures. Validate required `roles` as a distinct array of 1–4 existing AppRole values. Query the authenticated application's relationship, active/password-ready accounts only, with roles filtered before pagination. Unknown stored roles are excluded.
2. List: optional `search` string up to 100 characters, `page` 1–10000, `per_page` 1–50 (default 20). Split whitespace-separated search terms; require each term to match some name part, case-insensitively, treating SQL wildcard characters literally. Sort last name, first name, UUID. Use simple pagination and expose only `data` and `meta: {page, per_page, has_more}`. No unrelated totals or URLs. Return UUID, full_name, initials, role, nullable office_name/office_abbreviation only.
3. Lookup: same current scope and role filtering, no search/page parameters. Invalid, absent, excluded, inactive, and unassigned UUIDs return the same 404 body. No employee route binding outside application scope. Consumers must recheck before a protected operation; this read is not a cross-service atomic grant.
4. Add Pest feature tests covering role/application isolation, response minimization, search wildcards/name parts, bounds, stable pagination, absent office, revocation/deactivation/role changes, client credentials and rate limits, and legacy compatibility. Validate with the installed Laravel 12 APIs and isolated SQLite tests.
5. Document contract in backend docs and update the authoritative portal integration guide with a link/summary. Preserve unrelated portal changes. Run full Pest suite and scoped Pint, review the diff, and record results. Pin source when integrating Connect; live rollout remains separate from test evidence.

## Validation

Use the existing `lgu-dev-lgu-sso` PHP 8.4 image with its entrypoint overridden, `--network none`, this checkout at `/app`, and existing locked dev vendor mounted read-only at `/app/vendor`. Set APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, CACHE_STORE=array, SESSION_DRIVER=array, QUEUE_CONNECTION=sync, a synthetic APP_KEY and JWT_SECRET. No operational env file or volumes.

Per task: PHP lint changed PHP files; after controller/tests run `php vendor/bin/pest tests/Feature/SsoDirectoryTest.php`; final `php vendor/bin/pest` and `php vendor/bin/pint --test` on changed PHP files. Documentation: `git diff --check` and local link checks. This backend-only change requires HTTP feature tests, not browser UI tests.

## References and acceptance

Read AGENTS.md, workspace CLAUDE.md, existing SsoController, ValidateAppCredentials, PerAppRateLimit, AppRole, Employee/Application models, SsoIsolationTest and the portal integration guide. Laravel references: https://laravel.com/docs/12.x/pagination#simple-pagination and https://laravel.com/docs/12.x/validation#rule-enum. Existing relationship loads pivot role. Laravel Routing Pipeline renders inner exceptions before returning through outer middleware.

All listed behavior must have passing tests. No directory response may rely on an unscoped employee lookup or cached grant. Connect retains its own role allowlist and caller session checks. The owner approved the upstream scope; endpoint shape and bounds are routine implementation choices within the proposed contract. No unresolved product decision.
