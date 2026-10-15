# Profile photo API

## Shared employee profile photos

SSO is the default source of employee photos. Applications may keep an independent override; deleting that override should restore the current SSO photo, then initials when absent. Never write an application's override back to SSO implicitly.

Employee resources and `/sso/directory` entries add nullable `avatar_version` (opaque UUID) and `avatar_endpoint` (relative `/api/v1/sso/directory/{uuid}/avatar`). No photo means both are null. Existing fields retain their semantics.

Read the photo server-side with `GET /api/v1/sso/directory/{uuid}/avatar?roles[]=standard` and the same `X-Client-ID` / `X-Client-Secret` headers as the directory. Include each supported role explicitly. The endpoint derives application scope from active credentials and rechecks the target's active status, completed password setup, current grant and role. Ineligible, deleted or absent photos return 404; invalid credentials 401; missing/invalid roles 422; rate limits 429. No bearer or client secret belongs in an image URL or browser JavaScript.

Success is `image/webp`, 512×512, at most 524288 bytes, with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`. Construct the URL against the fixed configured SSO API origin; do not fetch arbitrary upstream URLs or follow redirects. Proxy through the consumer's current authenticated session and business visibility checks. Widgets fetch bytes with their existing opaque credential and display a bounded Blob. An unavailable SSO must not grant access through stale cached authorization.

`avatar_version` changes per successful replacement and becomes null on removal. Refresh metadata during current profile/directory/member reads; do not synchronize only at login. The image endpoint always resolves the current photo and does not expose historical versions. An old version query is not an access credential. Consumers may keep their own versioned in-memory UI state but must refresh it and clear removed images.

Central employee self-service: GET/POST/DELETE `/api/v1/portal/profile/avatar`. Super administrators: GET/POST/DELETE `/api/v1/employees/{uuid}/avatar`. POST uses multipart field `photo`; JPG/PNG/WebP up to 5 MiB, dimensions 256–4096 per side, at most 16 million pixels. The Portal frames a 512 px square before submitting. Direct API images are center-cropped. The server independently decodes/re-encodes; submitted originals/metadata are not retained. Writes return EmployeeResource and are audited. Consumer bearers cannot write these routes.

Avatar files use the private persistent `storage/app/private/avatars` disk. Include it in storage backups. The additive nullable migration supports rollback to the previous application image without dropping columns or reversing migrations. Do not make the avatars disk publicly served.
