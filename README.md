# LGU-SSO

A Single Sign-On (SSO) API built with Laravel for Local Government Units (LGU). Provides centralized authentication, employee management, and OAuth-based application authorization.

<!-- lgu-ecosystem:start -->
## LGU Ecosystem

This project is one of four applications that make up the LGU platform, all running inside the shared Docker container **development**:

| Project     | Role                       | Dev URL                                 | Prod URL                                            |
|-------------|----------------------------|-----------------------------------------|-----------------------------------------------------|
| LGU-SSO     | Authentication API         | http://api.sso.local                    | https://api.sso.lguquezon.local                     |
| lgu-sso-ui  | SSO login portal           | http://sso-portal.local                 | https://sso-portal.lguquezon.local                  |
| lgu-chat    | Real-time messaging        | http://chat.local                       | https://chat.lguquezon.local                        |
| opts2026    | Procurement tracking       | http://opts.local                       | https://opts.lguquezon.local                        |

Authentication works in two hops: apps redirect users to the **login portal** (`sso-portal.*`), and then talk to the **SSO API** (`api.sso.*`) server-to-server to validate tokens and fetch the user profile. The legacy names `sso-ui.*`, `lgu-sso.test`, and `lgu-sso.local` are being phased out — always use `sso-portal.*` and `api.sso.*`.

Dev and production configurations must be identical except for DNS hostnames, secrets, and `APP_ENV`/`NODE_ENV`/`APP_DEBUG`. See the workspace-level [`../CLAUDE.md`](../CLAUDE.md) for the full ecosystem overview, authentication model, and the list of known cross-app issues.
<!-- lgu-ecosystem:end -->

## Docker Deployment (Recommended)

LGU-SSO runs as part of a multi-service Docker stack. The Docker Compose setup lives in the parent directory and includes:

| Service | Domain | Description |
|---------|--------|-------------|
| **lgu-sso** | `sso.lguquezon.local` | This app (Laravel API) |
| **lgu-sso-ui** | `sso-ui.lguquezon.local` | SSO frontend (Next.js) |
| **lgu-chat** | `chat.lguquezon.local` | Chat app (Next.js + Socket.io) |
| **nginx** | - | Reverse proxy (port 80) |
| **dns** | - | dnsmasq server (port 53) |
| **mysql** | - | MySQL 8.0 database |

### Prerequisites

- Docker and Docker Compose
- Stop any local web server using port 80 (e.g., `valet stop`)

### Quick Start

From the parent directory (`development/`):

```bash
# Build and start all services
docker compose build
docker compose up -d

# Run migrations (first time only)
docker exec lgu-sso php artisan migrate --force

# Seed the database (first time only)
docker exec lgu-sso php artisan db:seed --force
```

The API will be available at `http://sso.lguquezon.local`.

### LAN Access (Other Devices)

A dnsmasq container runs on port 53 and resolves all `*.lguquezon.local` domains to the host machine's LAN IP. To access from other devices on the network:

1. Find the host machine's LAN IP (configured in `dns/dnsmasq.conf`)
2. On the client device, set the DNS server to the host's LAN IP
3. Visit `http://sso.lguquezon.local` -- no port number or `/etc/hosts` editing needed

### Environment Variables

Environment variables are defined in the parent `../.env` file and passed through `docker-compose.yml`. Key variables for lgu-sso:

| Variable | Description |
|----------|-------------|
| `APP_URL` | Public URL for the SSO API |
| `DB_HOST` | Database host (`mysql` in Docker) |
| `DB_DATABASE` | Database name |
| `DB_USERNAME` | Database user |
| `DB_PASSWORD` | Database password |
| `JWT_SECRET` | Secret key for JWT token signing |

### Docker Image

The Dockerfile uses a multi-stage build:

1. **Composer stage**: Installs PHP dependencies with optimized autoloader
2. **Node stage**: Builds frontend assets with Vite
3. **Runner stage**: PHP 8.4 CLI Alpine with required extensions (pdo_mysql, bcmath, mbstring, zip, intl, pcntl)

The entrypoint script runs migrations automatically on container start.

---

## Local Development

For standalone development without Docker:

1. **Install PHP dependencies**
   ```bash
   composer install
   ```

2. **Install Node dependencies and build assets**
   ```bash
   npm install
   npm run build
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Run migrations and seeders**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

5. **Start development server**
   ```bash
   php artisan serve
   ```

---

## API Overview

### Authentication
- `POST /api/v1/auth/login` - User login
- `POST /api/v1/auth/register` - User registration
- `POST /api/v1/auth/logout` - User logout
- `GET /api/v1/auth/me` - Get authenticated user

### SSO / OAuth
- `GET /api/v1/sso/authorize` - OAuth authorization
- `POST /api/v1/sso/token` - Exchange code for token
- `POST /api/v1/sso/validate-token` - Validate an access token

### Employees
- `GET /api/v1/employees` - List employees
- `POST /api/v1/employees` - Create employee
- `GET /api/v1/employees/{uuid}` - Get employee
- `PUT /api/v1/employees/{uuid}` - Update employee

### Applications
- `GET /api/v1/applications` - List registered applications
- `POST /api/v1/applications` - Register a new application

## Architecture

- **Framework**: Laravel 12
- **PHP**: 8.4
- **Database**: MySQL 8.0
- **Authentication**: JWT tokens
- **API Style**: RESTful with Eloquent API Resources

---

**LGU-SSO** - Developed by Management Information System Section (MISS)
Municipality Of Quezon Bukidnon 8715 Philippines
All Rights Reserved 2025
