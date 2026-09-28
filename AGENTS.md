<!-- lgu-ecosystem:start -->
# LGU SSO context

The active stack contains `lgu-sso-backend` (API at `https://sso.lgu.lan`) and `lgu-sso-portal` (login at `https://sso-portal.lgu.lan`). OPTS and Chat source/data are retained for rebuilding; their containers and SSO registrations are retired.

SSO owns identity, application grants, and shared roles. Consumers own detailed business permissions and must recheck current authorization for protected requests. Central cookies are host-only and HttpOnly. Application bearers are scoped to one client; never restore shared central cookies or bearer-in-URL callbacks.

Normal logout affects only the current session. The portal separately offers sign out everywhere. Protected requests fail closed when SSO cannot verify access. Development and production must enforce the same security contract.

Read `../CLAUDE.md` for workspace rules and `../lgu-sso-portal/LGU-SSO-INTEGRATION-GUIDE.md` for the current code-exchange contract. Historical Superpowers plans do not replace the user's PIV workflow.
<!-- lgu-ecosystem:end -->
