# Shared profile photo implementation — 2026-10-15

SSO owns the default employee photo. Applications may retain independent overrides. LGU Connect integration is coordinated with its owning chat; OPTS integration remains outside this release.

## Implementation

Central employees and super administrators can upload, replace, read, and remove photos. Authenticated application directory readers use current grants, roles, active status, and completed password setup. Photos are decoded and re-encoded as private 512 × 512 WebP images. Original metadata and storage paths are not exposed. Replacement uses transactional pointers and post-commit cleanup. The additive migration retains compatibility with the previous application image.

Portal follows the approved prototype, with framing, zoom, rotation, replacement, removal confirmation, and authenticated binary proxy delivery. Local browser checks cover self upload/replacement/reload, mobile framing, cancellation, and administrator upload while preserving an unsaved employee form field.

## Validation

- Backend: 225 tests passed, 1,127 assertions, isolated in-memory SQLite.
- Feature PHP formatting and both repositories' diff checks passed.
- Full repository Pint reports three unchanged baseline files: ChangePasswordRequest and the January 3/March 26 migrations. They are outside this feature.
- Portal: 15 tests passed; typecheck and production build passed. Lint has zero errors and six pre-existing warnings.
- Independent technical and visual finish review: ship at the avatar-extension scope, no material findings.

## Delivery boundary

Staging only. Fresh staging database/storage/runtime backup and verified off-server copy were created before release. Production remains unchanged. Staging and actual Connect acceptance must be recorded after deployment; a Portal preview is not proof of Connect synchronization.
