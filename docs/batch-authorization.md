# Bounded application authorization

`POST /api/v1/sso/authorize-batch` requires the existing confidential client headers.
It is disabled by default and restricted to `SSO_BATCH_APPLICATION_UUID`.
The production Nginx exact route sends it to a dedicated one-child FPM pool.
Other routes retain the existing pool. Alternate runtimes without the trusted
FastCGI parameter fail closed.

Send an object with exactly `items`, a JSON array of 1–10 objects containing only
`id` and `token`. IDs are unique 1–64 ASCII letters, digits, underscores or hyphens.
Tokens are distinct, nonempty, at most 16384 bytes, without whitespace or controls.
The raw JSON body cannot exceed 196608 bytes. Numeric-key objects are not arrays.

```json
{"items":[{"id":"0","token":"application-bearer"}]}
```

HTTP 200 returns ordered `data` with exactly one result per item. Success contains
`id`, `authorized: true`, `role`, and `employee: {uuid, full_name, email}`.
Denial contains `id`, `authorized: false`, and `status` (401 or 403).
Infrastructure failures never return partial positive results. Whole-request
errors include 400 malformed JSON, 401 client credentials, 404 disabled/unselected
client, 413 body size, 422 shape, 429 quota, and 503 unavailable/busy.
429 and 503 include `Retry-After`. Responses are private and not cacheable.
Consumers must enforce exact cardinality and IDs, fail closed, and honor cooldowns.
Do not fan out singleton retries after a batch failure.

Every item verifies the current token row, active employee, password setup,
application scope, grant and role. Employee authorization is never cached.
Successful and denied item decisions produce individual audit rows in one insert;
tokens, hashes, secrets and submitted IDs are excluded.

The existing shared HTTP budget still charges one request per batch. Separate
`SSO_BATCH_ITEMS_PER_MINUTE` and `SSO_BATCH_GLOBAL_ITEMS_PER_MINUTE` count every
item. Both must be positive. Fixed windows permit adjacent-window bursts; these
are not sliding-minute ceilings. Rejected reservations remain charged. Counters
use the configured database store and its default database connection. Store
failures fail closed; switching stores does not transfer existing counters.

An optional successful client-secret comparison memo is restricted to
`SSO_CLIENT_COMPARISON_APPLICATION_UUID`. The current active application and
stored secret hash are fetched on every request. A server-key HMAC binds the
application, current stored hash and exact presented secret. Only successful
comparisons are retained for 60 seconds; failed comparisons are not cached.
Rotation and application deactivation take effect on the next request.
`SSO_CLIENT_COLD_PER_MINUTE` and `SSO_CLIENT_GLOBAL_COLD_PER_MINUTE` bound actual
bcrypt work. A database ownership lock joins concurrent cold misses; a one-second
polling budget does not include an already-running database operation.

`SSO_CAPACITY_STORE=database` requires the existing cache and cache-lock tables.
MySQL session SELECT, row-lock and metadata-lock limits bound database waits;
the dedicated batch FPM worker has a six-second termination timeout. Nginx
admits one batch request at a time and converts pool failures to a generic 503.
These controls do not prove user capacity or increase the shared HTTP budget.

There are no new migrations or seeders. Deploy the paired backend/web images
before enabling the compatible consumer. Preserve the existing shared HTTP
limit. On rollback, disable the consumer batch flag before restoring older SSO
images; never clear login, registration, application or work counters.
