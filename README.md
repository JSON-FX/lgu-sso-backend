# lgu-sso-backend

Laravel 12 authentication API for the LGU platform. It owns employee accounts, application access, and single sign-on (SSO). Only SSO administrators can create accounts.

The companion [lgu-sso-portal](../lgu-sso-portal/README.md) provides login and administration. Both repositories are built from the [workspace Compose files](../docker-compose.yml).

## Local Docker stack

Run these commands from the `lgu-dev` workspace root. The current `docker-compose.yml` uses the renamed repository directories. Its service names remain `lgu-sso` and `lgu-sso-ui` for compatibility.

| Service | Local HTTPS URL | Purpose |
| --- | --- | --- |
| `lgu-sso` | `https://sso.lgu.lan` | Laravel API |
| `lgu-sso-ui` | `https://sso-portal.lgu.lan` | Next.js portal |

The local stack requires its existing Traefik proxy, `dev-net` network, MySQL service, DNS entries, and a trusted certificate covering these hosts. Laravel reaches MySQL at `mysql:3306`. The portal reaches the API at `http://lgu-sso:8000/api/v1` inside Docker.

```sh
docker compose build lgu-sso lgu-sso-ui
docker compose up -d --no-build lgu-sso lgu-sso-ui
docker compose ps lgu-sso lgu-sso-ui
curl --fail --silent --show-error https://sso.lgu.lan/up
curl --fail --silent --show-error https://sso-portal.lgu.lan/login > /dev/null
```

The backend loads `lgu-sso-backend/.env`, then Compose overrides local URL, database, session, and cookie settings. Its entrypoint runs migrations. It seeds only when `SSO_AUTO_SEED=true` is set in that local file and the employee table is empty. That seed contains demo credentials, so use it only with disposable local data. Leave automatic seeding off for an existing database.

Central cookies are host-only, Secure, and HttpOnly. The production portal uses `__Host-portal_session`. Consumers use authorization-code exchange and do not receive central cookies. OPTS and Chat are retired from the active stack; their source/data remain available for rebuilding. See the [integration contract](../lgu-sso-portal/LGU-SSO-INTEGRATION-GUIDE.md).

The September 29 migration revokes old sessions once to retire the shared-cookie contract. Users must sign in again. This is an intentional migration effect and cannot be reversed by restoring revoked credentials.

## Production Docker stack

Production uses [docker-compose.prod.yml](../docker-compose.prod.yml), separately from the local stack and its volumes. It includes MySQL 8.4, PHP-FPM, Nginx, the portal, a TLS proxy, and a scheduled database and storage backup service. The application images use non-root users. MySQL uses a restricted `lgu_sso` account. The backend runs with `APP_ENV=production`, `APP_DEBUG=false`, and `SSO_AUTO_SEED=false`.

Choose real, trusted hostnames before deploying. Set `SSO_API_HOST` and `SSO_PORTAL_HOST`. Central cookies remain host-only; a shared parent domain is not required. Include the portal in `SSO_ALLOWED_ORIGINS`. Supply a certificate covering the API and portal hosts. Do not reuse the `.lan` hosts or development certificate in a shared deployment.

Create a private Compose environment file outside the repositories. Replace every placeholder with the deployment's actual value. Paths must be absolute and point to readable files. Generate independent random values for the Laravel `APP_KEY`, JWT secret, database passwords, and TLS key. Restrict the environment and secret files to authorized operators.

```dotenv
SSO_STACK_NAME=lgu-sso-production
SSO_API_HOST=<api-host>
SSO_PORTAL_HOST=<portal-host>
SSO_ALLOWED_ORIGINS=https://<portal-host>
# Only when migrating old shared cookies:
SSO_LEGACY_COOKIE_DOMAIN=.<former-shared-parent-domain>
SSO_APP_KEY_FILE=/secure/path/app-key
SSO_JWT_SECRET_FILE=/secure/path/jwt-secret
SSO_DB_PASSWORD_FILE=/secure/path/db-password
SSO_DB_ROOT_PASSWORD_FILE=/secure/path/db-root-password
SSO_TLS_CERT_FILE=/secure/path/tls-cert.pem
SSO_TLS_KEY_FILE=/secure/path/tls-key.pem
```

The `APP_KEY` file must contain a Laravel key in `base64:` format. The TLS certificate and key must match. HTTPS binds to port 443 by default; set `SSO_HTTPS_BIND_IP` and `SSO_HTTPS_PORT` if needed. Retain the `APP_KEY` and JWT secret securely for restores. Changing them invalidates encrypted values and active sessions.

Set `env_file` to the absolute path of your private environment file:

```sh
env_file=/secure/path/sso-production.env
docker compose --env-file "$env_file" -f docker-compose.prod.yml config --quiet
docker compose --env-file "$env_file" -f docker-compose.prod.yml build
docker compose --env-file "$env_file" -f docker-compose.prod.yml up -d --no-build
docker compose --env-file "$env_file" -f docker-compose.prod.yml ps
```

The backend migrates on startup. Take a verified backup before applying an image with migrations. For repeatable deployments and rollbacks, pin `SSO_BACKEND_IMAGE`, `SSO_WEB_IMAGE`, and `SSO_PORTAL_IMAGE` to immutable, tested image tags. The portal's browser requests use a same-origin server route; its server receives `SSO_API_URL` at runtime.

### First administrator and credential rotation

Production never runs demo seeders. `sso:bootstrap-admin` works only when both employee and application tables are empty. Store a new administrator password and a distinct portal client secret in files under a private host directory. The password must be 20 to 72 bytes; the client secret must be 32 to 72 bytes. Do not put either value on the command line.

This shell helper mounts that directory read-only for a one-shot PHP process. It loads the Compose secrets without printing them:

```sh
bootstrap_dir=/secure/path/sso-bootstrap
sso_cli() {
  docker compose --env-file "$env_file" -f docker-compose.prod.yml run --rm --no-deps \
    --user 0 -v "$bootstrap_dir:/run/bootstrap:ro" --entrypoint sh \
    sso-backend -lc '
      export APP_KEY="$(cat /run/secrets/app_key)"
      export JWT_SECRET="$(cat /run/secrets/jwt_secret)"
      export DB_PASSWORD="$(cat /run/secrets/db_password)"
      exec php artisan "$@"
    ' sh "$@"
}

sso_cli sso:bootstrap-admin \
  --first-name='<first-name>' --last-name='<last-name>' \
  --email='<email-address>' --birthday='YYYY-MM-DD' \
  --civil-status='single' --residence='<residence>' \
  --nationality='<nationality>' \
  --portal-redirect-uri='https://<portal-host>/login' \
  --password-file=/run/bootstrap/admin-password \
  --client-secret-file=/run/bootstrap/portal-client-secret
```

Replace the identity fields and HTTPS portal host before running the command. Record the returned username and client ID. At first login, the administrator must change the initial password. Administrators then create ordinary accounts in the portal; public registration is disabled.

Rotate any administrator password or client secret carried forward from local seed data before sharing that environment. These commands read replacement values from files and do not print them:

```sh
sso_cli sso:rotate-admin-password '<admin-username>' \
  --password-file=/run/bootstrap/new-admin-password
sso_cli sso:rotate-app-secret '<client-id>' \
  --secret-file=/run/bootstrap/new-client-secret
```

Password rotation revokes the administrator's sessions. After rotating an application secret, update the matching consumer configuration and restart it. Keep separate credentials for each rebuilt consumer. Register each consumer's exact HTTPS callback before testing browser redirects.

### Backup, restore, and rollback

The `backup` service writes a compressed MySQL dump and matching `storage/app` archive on startup. It repeats at `SSO_BACKUP_INTERVAL_SECONDS`, which defaults to one day. The `mysql-backups` volume is separate from database and application storage. Retention defaults to 14 days. Check service health and logs. Copy a chosen SQL and storage pair to protected storage outside this Docker host. An on-host volume alone is not a disaster recovery copy.

```sh
dc() { docker compose --env-file "$env_file" -f docker-compose.prod.yml "$@"; }
dc ps backup
dc logs --tail=30 backup
dc exec -T backup sh -lc 'ls -lh /backups/lgu-sso-*.sql.gz /backups/lgu-sso-storage-*.tar.gz'
```

Restore a SQL and storage pair with the same timestamp. Verify the archives first and restore them to an isolated stack as a rehearsal. To replace production data, keep MySQL running but stop the writer services. The following commands delete the current database and `storage/app`. Set `stamp` to an existing backup timestamp and `restore_dir` to a private host directory.

```sh
stamp=YYYYMMDDTHHMMSSZ
restore_dir=/secure/path/sso-restore
mkdir -p "$restore_dir"
dc cp "backup:/backups/lgu-sso-${stamp}.sql.gz" "$restore_dir/"
dc cp "backup:/backups/lgu-sso-storage-${stamp}.tar.gz" "$restore_dir/"
gzip -t "$restore_dir/lgu-sso-${stamp}.sql.gz"
tar -tzf "$restore_dir/lgu-sso-storage-${stamp}.tar.gz" > /dev/null
dc stop backup sso-portal sso-web sso-backend
dc exec -T mysql sh -lc '
  export MYSQL_PWD="$(cat /run/secrets/db_root_password)"
  mysql -h 127.0.0.1 -u root -e "DROP DATABASE IF EXISTS lgu_sso; CREATE DATABASE lgu_sso;"
'
set -o pipefail
gzip -dc "$restore_dir/lgu-sso-${stamp}.sql.gz" |
  dc exec -T mysql sh -lc '
    export MYSQL_PWD="$(cat /run/secrets/db_root_password)"
    exec mysql -h 127.0.0.1 -u root lgu_sso
  '
dc run --rm --no-deps --user 0 \
  -v "$restore_dir/lgu-sso-storage-${stamp}.tar.gz:/run/restore/storage.tar.gz:ro" \
  --entrypoint sh sso-backend -lc '
    rm -rf /app/storage/app
    tar -xzf /run/restore/storage.tar.gz -C /app/storage
    chown -R laravel:laravel /app/storage/app
  '
dc up -d --no-build
dc ps
```

Use the same image versions that produced the backup when restarting. Check service health, login, and consumer callbacks after the restore. Do not start a newer image that would migrate the restored schema until its release is intended.

For an image rollback without restoring data, set the three image variables to the previous immutable tags and run `dc up -d --no-build`. If the release changed the schema, restore its matching SQL and storage backup before starting those images. A code-only rollback does not reverse migrations.

### Validation

```sh
dc ps
curl --fail --silent --show-error 'https://<api-host>/up'
curl --fail --silent --show-error 'https://<portal-host>/login' > /dev/null
dc logs --tail=50 sso-backend sso-web sso-portal tls backup
```

With an administrator account, verify login, forced first password change, employee creation, application grants, and logout. For each consumer, verify that the portal returns a short-lived `code` and original `state` to the exact callback. The consumer server must exchange the code once through `POST /api/v1/sso/exchange` with its client ID and secret. A consumed or expired code must fail. Verify sign-in through the portal with two separately registered clients, token isolation, grant revocation, and both logout actions.

## API overview

| Area | Main routes |
| --- | --- |
| Authentication | `POST /api/v1/auth/login`, `GET /api/v1/auth/me`, `POST /api/v1/auth/logout`, `POST /api/v1/auth/change-password` |
| Administration | `/api/v1/employees`, `/api/v1/applications`, `/api/v1/audit`, `/api/v1/stats/dashboard` |
| SSO | `POST /api/v1/sso/validate-redirect`, `POST /api/v1/sso/code`, `POST /api/v1/sso/exchange`, `POST /api/v1/sso/validate`, `POST /api/v1/sso/authorize` |

The portal sends authenticated API requests through its same-origin `/api/sso-backend/*` route. Consumer servers use the internal Docker API URL or the trusted public API URL. See the [portal runbook](../lgu-sso-portal/README.md) for browser session details.

## Consumer directory

Role-aware colleague discovery uses `GET /api/v1/sso/directory` and current recipient lookup uses `GET /api/v1/sso/directory/{uuid}`. Both require confidential application credentials and explicit role filters. See the [directory contract](docs/consumer-directory.md) for search, pagination, response fields, and revocation boundaries. The legacy `/sso/employees` response remains unchanged.
