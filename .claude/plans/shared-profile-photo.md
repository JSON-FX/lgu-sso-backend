# Shared SSO profile photos

## Scope and acceptance
Employees and active SSO super administrators upload, frame, replace and remove employee photos through the approved Public Index UI. SSO owns the canonical photo. Registered applications receive a reusable confidential photo endpoint with the same employee eligibility rules as the directory. LGU Connect is the first consumer; OPTS integration and production deployment are excluded.

Approved prototype: storybook-hub/projects/lgu-portal/src/avatar-prototype.tsx and avatar-prototype.css. Explicit source sync must be recorded because the hub source is not committed. Keep the current image until save succeeds; removal restores initials. JPG/PNG/WebP, at most 5 MiB, minimum 256px each dimension. Browser framing creates a 512px square; server independently validates and re-encodes all submitted images. Bound decoding to 4096px each dimension and 16 million pixels, normalize to 512px WebP, strip metadata. Private storage only.

## Owning source and patterns
- backend routes/api.php: authenticated central portal group and EnsureSsoAdministrator admin group; confidential directory uses ValidateAppCredentials, PerAppRateLimit, PrivateDirectoryResponse.
- backend app/Http/Controllers/Api/V1/SsoDirectoryController.php: roles validation, active employee/grant/setup filters. Reuse this query for binary delivery.
- backend app/Models/Employee.php: UUID route key; avatar fields excluded from ordinary mass assignment and hidden from raw serialization.
- backend app/Http/Resources/EmployeeResource.php: additive nullable avatar_version and relative avatar_endpoint descriptor.
- backend tests/Feature/OfficeManagementTest.php: issueSsoToken and current authorization test patterns.
- Portal app/api/sso-backend/[...path]/route.ts: host-only HttpOnly cookie and same-origin mutation check. Preserve both; add bounded binary-safe body forwarding and image responses.
- Portal app/(portal)/portal/page.tsx and app/(dashboard)/employees/[uuid]/page.tsx: photo component independent from employee form state.
- Connect backend/app/Services/Sso/SsoClient.php: fixed HTTPS origin, credentials remain server-side. Existing dirty feat/files-avatars is unrelated and must be preserved.

## Contract
POST /api/v1/portal/profile/avatar (multipart photo); DELETE same; GET same returns current WebP. Admin equivalents /api/v1/employees/{uuid}/avatar. Mutations return EmployeeResource. Writes are rate limited and audited with actor/subject UUID and opaque version only.
GET /api/v1/sso/directory/{uuid}/avatar?roles[]=standard uses current confidential application credentials and the exact directory visibility rules. Returns current WebP, private no-store, nosniff; 404 for absent/ineligible targets. No public storage URL, bearer query parameter, or caller-controlled application scope. Descriptor is relative; consumers construct URLs only against their configured SSO origin. Version is a random UUID per replacement; null after removal. Old version query parameters do not authorize historical image access.

Use one nullable avatar_path (hidden) plus avatar_version. New immutable file path per write. Within transaction lock employee, swap pointer and audit; delete previous file after commit. Failed DB writes delete only newly written file and preserve existing image. Missing file returns404. Storage failures preserve old state and return503. Removed photos are inaccessible immediately even if cleanup fails.

## Implementation sequence
1. Backend migration, private avatar service/controller, directory binary endpoint, resource metadata, GD support in runtime, PHP/nginx limits. Validate php syntax and focused Pest tests.
2. Portal source sync, shadcn Slider/AlertDialog dependencies, real save/remove, current header and profile rendering, binary proxy tests. Validate npm test, tsc, lint, build.
3. Connect integrate shared SSO delivery after ownership question resolves. Current dirty feature permits Connect override; user has been asked SSO-only vs override. Do not modify dirty tree. Add current-session protected proxy and web/widget safe transport with initials fallback. Test credentials/grant failures, missing photos, replacement/removal and widget token isolation.
4. Documentation: both SSO READMEs, integration guide, Connect contract, source sync hashes, implementation report. Focused tests plus full repository required gates and review. Browser desktop/mobile self/admin/error/removal flows.
5. Staging only: fresh backup and storage verification, CI images, migration and deploy via existing MIS pull flow. Verify live endpoints, binary ingress limits, portal and Connect same bytes/current authorization. No production mutation.

## Validation commands
Backend: vendor/bin/pint --test; php artisan test (phpunit.xml uses in-memory SQLite). Portal: npm test; npx tsc --noEmit; npm run lint; npm run build. Connect: documented isolated backend-test Docker Compose; frontend pnpm lint/typecheck/test/build. Review diff for authorization, stale metadata and image lifecycle races. Browser verify authenticated flows without printing tokens or altering real employee photos; use disposable staging-only fixture and cleanup or owner supplied photo.

## Sources
- https://laravel.com/docs/12.x/validation#validating-files — MIME and size/dimension validation.
- https://www.php.net/manual/en/function.imagecreatefromstring.php — GD decode failure handling.
- https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html — private storage, size limits, rewritten raster output, authenticated uploads.

## Open question
User confirmed: SSO default, applications may override. User authorized coordination with the active LGU Connect chat; that chat owns its concurrent Connect implementation and fallback integration. This work owns SSO backend/Portal and will provide staging-ready endpoint evidence.
