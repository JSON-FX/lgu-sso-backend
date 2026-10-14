# Implementation Report — Application reference directory

Plan: `.claude/plans/sso-reference-directory.md`
Branch: `feature/sso-reference-directory`, based on `67bd38debab1775630c1b0fe4e3e30768746127a`
Status: COMPLETE for the authorized local implementation and test scope. Office-server deployment is pending network access and a deployment request.

## Delivered

- Extended the existing application-scoped employee directory with office ID, position ID and current position title. Existing response fields and authorization boundaries are preserved.
- Added office/position list and numeric-ID lookup endpoints with credentials, shared rate limits, bounded pagination, literal search, private/no-store responses, active selections and inactive historical lookup.
- Updated reference seeders from October 14 exports. Renamed offices keep IDs; omitted records become inactive without deleting IDs or changing employee assignments. Portal office selection excludes inactive records.
- Updated backend README/contract and Portal integration guide/README. Portal documentation is in the separate `docs/sso-reference-directory` branch at `../sso-reference-contract`.

## Verification

- Full isolated SQLite Pest suite: 149 tests, 637 assertions passed.
- `vendor/bin/pint --dirty`: passed. `git diff --check`: passed in both repositories.
- Docker image rebuilt and local `lgu-sso` API recreated. Existing local storage restored from backup. HTTPS `/up`: 200.
- 22 live HTTPS API checks passed with normal certificate verification: scoped users, UUID lookup, office/position pages and details, literal search, credentials, validation, inaccessible users, missing references and no-store responses.
- Live active counts: 85 offices and 303 positions. All 303 active position titles exactly match the normalized source CSV.
- Historical totals: 92 offices, 349 positions; 7 offices and 46 positions are inactive. All pre-existing IDs and all ten pre-existing employee assignments were retained.
- Running container SHA-256 hashes match all seven changed application/data source files.
- Existing authentication/code exchange, role isolation, session revocation and Portal lookup tests passed in the full suite. No new browser UI was introduced.

## Local test access

API base: `https://sso.lgu.lan/api/v1`
Portal: `https://sso-portal.lgu.lan`

A clearly labeled `Local Directory API Test` application has access to one synthetic `Demo Directory Employee`. Its callback list is empty. The employee's random password was not retained; this fixture is for directory calls, not interactive login. Existing real employee grants were not changed.

Client headers: `/Users/jsonse/.config/lgu-sso/directory-local.curl` (0600).
Non-secret fixture identifiers: `/Users/jsonse/.config/lgu-sso/directory-local.json` (0600).
The consumer-directory contract contains copyable curl examples. Keep the credentials outside source control and browser code. Remove the test application's grant/client and synthetic employee when local testing ends.

The root checkout remains unchanged. To rebuild this local feature image, run from `/Users/jsonse/Documents/development/lgu-dev`:

```sh
docker compose -f docker-compose.yml -f "$HOME/.config/lgu-sso/directory-local-compose.yml" build lgu-sso
```

Before recreating the container, preserve `/app/storage/app`: the existing local service has no storage volume. Recreating from the root Compose file without the override selects the old root checkout, not this feature branch.

## Backup and release evidence

Private backup directory: `/Users/jsonse/.local/share/lgu-backups/reference-directory-20261014T194943`.
It contains the SQL dump, storage archive, checksums, original reference/employee mapping, final mapping and `local-release.json` with image ID and source hashes. Archive integrity and the original ID/assignment mapping were verified. No restore rehearsal or remote backup is claimed.

## Deviations and limitations

Live local data was older than the October 8 baseline: six additional offices and 43 position titles were absent from the updated snapshot. The seeders now retain all omitted records as inactive so active options exactly match the user's supplied exports. Do not run a dated snapshot seeder over future manual directory additions without reviewing its effect.

No employees were imported from CSV counts. One isolated local demo employee was added for user testing. No schema migration, new dependency, Portal UI change, main push, hosted CI run or server deployment was performed. Implementation and documentation are published on separate feature branches. Server deployment remains deferred until office-network access is available.
