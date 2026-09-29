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
    "office_name": "Example Office",
    "office_abbreviation": "EO"
  }],
  "meta": {"page": 1, "per_page": 20, "has_more": false}
}
```

The example is synthetic. Both office fields may be null. No email, username, private profile, employee database ID, grants to other applications, total employee count, or pagination URLs are returned. An empty or out-of-range page returns `data: []` and `has_more: false`.

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

`tests/Feature/SsoDirectoryTest.php` verifies the contract with synthetic records using the project's isolated in-memory SQLite setup. The existing SSO suite covers code exchange and bearer-session behavior. No migration or operational data change is required.
