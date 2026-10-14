# Application-scoped consumer directory

Source contract for `GET /api/v1/sso/directory` and `GET /api/v1/sso/directory/{uuid}`. These endpoints are separate from the unchanged legacy `/sso/employees` array response. Source implementation does not establish that a running environment has deployed it.

## Authentication and scope

Send `X-Client-ID` and `X-Client-Secret` from the consumer server. The authenticated application's current grants determine the directory. No central administrator or employee bearer is required here; the consumer must authorize its caller separately before exposing directory information. Never put client credentials in browser code or request URLs.

Both endpoints require `roles[]`: a distinct array of one to four values from `guest`, `standard`, `administrator`, `super_administrator`. The consumer chooses its allowed roles on the server. LGU Connect sends exactly `standard`, `administrator`, `super_administrator`. A browser cannot widen that list. SSO does not impose Connect's role policy on other applications.

Only employees who are active, not soft-deleted, have completed password setup, and currently hold one of the selected roles in the requesting application are returned. Unknown stored roles never match. Application identifiers supplied by callers cannot change the credential-derived scope.

## List

Example: `/api/v1/sso/directory?roles[]=standard&roles[]=administrator&roles[]=super_administrator&search=Maria%20Reyes&page=1&per_page=20`.

| Parameter | Contract |
| --- | --- |
| `roles[]` | Required as described above; filtering precedes pagination |
| `search` | Optional name search, maximum 100 characters; blank lists all permitted colleagues |
| `page` | Integer 1–10000, default 1 |
| `per_page` | Integer 1–50, default 20 |

Whitespace-separated terms must each match a first, middle, last name, or suffix. Matching uses lowercase name parts and follows database Unicode/collation behavior. `%`, `_`, and `!` are literal characters, not search operators. Results sort by last name, first name, then unique UUID. Pages are current reads, not a snapshot; concurrent employee edits may shift page boundaries. Refetch when needed and never use an old result as permission.

```json
{
  "data": [{
    "uuid": "00000000-0000-4000-8000-000000000001",
    "full_name": "Maria Reyes",
    "initials": "M.R",
    "role": "standard",
    "office_id": 12,
    "office_name": "Example Office",
    "office_abbreviation": "EO",
    "position_id": 34,
    "position_title": "Engineer I"
  }],
  "meta": {"page": 1, "per_page": 20, "has_more": false}
}
```

The example is synthetic. Office and position fields may be null. Position title is the current designation. Historical inactive office/position assignments are still displayed until changed in SSO. No email, username, private profile, employee database ID, grants to other applications, total employee count, or pagination URLs are returned. An empty or out-of-range page returns `data: []` and `has_more: false`.

## Current recipient lookup

`GET /api/v1/sso/directory/{uuid}?roles[]=standard&roles[]=administrator&roles[]=super_administrator` returns `{"data": {...}}` with the same display fields and current role. It applies the same current eligibility query. Search and pagination do not affect lookup.

An invalid/unknown UUID, missing assignment, excluded role, inactive employee, unfinished password setup, or soft deletion returns the same `404 {"message":"Employee not found."}`. Recheck this endpoint before initiating contact. It is a current eligibility read, not an atomic reservation or permission to read conversation content. Consumers still enforce membership and ongoing authorization.

## Failures and caching

- `401`: missing/incorrect credentials, disabled/deleted application, or rotated client secret.
- `422`: invalid/missing query fields, including role selection.
- `429`: existing per-application rate limit, shared with other SSO consumer endpoints; honor `Retry-After`.
- Timeout/network/5xx: unavailable; consumers must deny the operation and allow deliberate retry.

Directory responses through these routes include `Cache-Control: private, no-store`, including validation, authorization, lookup-miss, and rate-limit responses. Do not persist eligibility or serve protected stale results during an outage.

## Verification

`tests/Feature/SsoDirectoryTest.php` verifies the contract with synthetic records using the project's isolated in-memory SQLite setup. The existing SSO suite covers code exchange and bearer-session behavior. No schema migration is required. Apply the reference seeders below to update operational lookup data.


## Office and position reference endpoints

These routes require the same active server-side client credentials and share the application's rate limit. They do not require `roles[]` because offices and positions are common reference data across applications.

| Method and path | Response |
| --- | --- |
| `GET /api/v1/sso/offices` | Active offices: `{data: [{id, name, abbreviation, is_active}], meta}` |
| `GET /api/v1/sso/offices/{id}` | One office, including an inactive historical record: `{data: {...}}` |
| `GET /api/v1/sso/positions` | Active positions: `{data: [{id, title, is_active}], meta}` |
| `GET /api/v1/sso/positions/{id}` | One position, including an inactive historical record: `{data: {...}}` |

Reference lists accept `search` (maximum 100 characters), `page` (1–10000, default 1), and `per_page` (1–100, default 50). `meta` contains `page`, `per_page`, and `has_more`. Every whitespace-separated search term must match an office name/abbreviation or position title, case-insensitively subject to database collation. Wildcards are literal. Sort order is name/title, then ID. Follow pages until `has_more` is false. Build searchable comboboxes using `search` rather than assuming the first page contains every option.

Office full names and abbreviations are separate fields. The detail routes accept positive numeric database IDs; absent IDs return 404. Inactive records remain resolvable for historical transactions but should not be offered for new selections. These endpoints return no employee lists or employee counts.

## IDs and business history

- Store `employee.uuid` as the external user identifier, `office_id` as the external office ID, and `position_id` when required. Treat IDs as opaque and never generate them from names or abbreviations.
- IDs are stable within one SSO database. Staging and production have independent data; do not transfer numeric reference IDs between environments without an explicit mapping.
- For an endorsement, store the actor UUID and destination office ID. If an audit needs the name, office or designation as it was then, also store a permitted display snapshot at the transaction time. A subsequent directory lookup returns today's information.
- Current directory access requires a current application grant. Revoked or inactive employees cannot be looked up through this endpoint even when a consumer has historical records referring to them. Keep business history locally under the consumer's own access policy.
- Reference metadata and stored snapshots never prove current authorization. Continue `/sso/authorize` checks for protected operations, and check current employee eligibility before new assignments.

## Local testing

Base URL: `https://sso.lgu.lan/api/v1`. Login remains `https://sso-portal.lgu.lan`.

Send `Accept: application/json`, `X-Client-ID`, and `X-Client-Secret` from a server or API client. Use your application's registered credentials. Never embed these credentials in frontend bundles. The following example reads headers from a private curl config, so secrets do not appear in command-line arguments:

```sh
curl --config "$HOME/.config/lgu-sso/directory-local.curl" \
  --get 'https://sso.lgu.lan/api/v1/sso/directory' \
  --data-urlencode 'roles[]=standard' \
  --data-urlencode 'roles[]=administrator' \
  --data-urlencode 'roles[]=super_administrator' \
  --data-urlencode 'per_page=20'

curl --config "$HOME/.config/lgu-sso/directory-local.curl" \
  'https://sso.lgu.lan/api/v1/sso/offices?search=MAO&per_page=100'

curl --config "$HOME/.config/lgu-sso/directory-local.curl" \
  'https://sso.lgu.lan/api/v1/sso/positions?search=Engineer'
```

The config file is local-only, mode `0600`, and contains the three headers above. Trust the local CA normally; do not disable TLS verification. Office-server staging uses `https://staging.api.sso.lguquezon.local/api/v1` only after this revision is deployed there.

## October 14, 2026 reference update

The supplied exports contain 85 offices and 303 positions. Literal `\n` row markers and HTML entities were normalized. Export employee counts are not employee records and are not imported. SSO employee administration remains the source of employee assignments.

Four metadata changes preserve their existing office IDs: `MMO-GSD` to `MAO-GSD`, `MMO-BPLD` to `MAO-BPLD`, `MMO-MPM-ALT` to `MMO-PM`, and `MHO-QHCI` to `QHCI`. `MACCO - COA` is a formatting-only source change and retains `MACCO-COA`. The distinct `MMO-MPM` office remains separate from `MMO-PM`.

The omitted `MPSO-STANDALONE` office and `Barangay Coordinator`, `Enforcer`, `Watchman` positions remain stored as inactive. Their IDs and employee assignments are not deleted or merged. The exports are authoritative for active selections: other existing records absent from the snapshot are retained as inactive, including older manually managed entries. Review this effect before applying these snapshot seeders to an environment with later directory additions. A fresh database contains 86 total offices (85 active) and 306 total positions (303 active); a pre-existing database can contain additional inactive historical records.

After backing up the target database, run only the reference seeders from the tested image:

```sh
php artisan db:seed --class=OfficeSeeder --force --no-interaction
php artisan db:seed --class=PositionSeeder --force --no-interaction
```

Each seeder is transactional and repeatable. An office rename that would collide with another existing identity fails rather than merging IDs. Image startup does not run these seeders automatically. Verify counts, renamed IDs and existing employee assignments after applying them. Reverting an image does not reverse reference-data updates; use a reviewed correction, not an automatic database restore.
