#!/bin/sh
set -e

echo "Starting LGU-SSO..."

load_secret() {
    name="$1"
    file_var="${name}_FILE"
    eval "secret_file=\${${file_var}:-}"
    if [ -n "$secret_file" ]; then
        if [ ! -r "$secret_file" ]; then
            echo "Cannot read ${file_var}." >&2
            exit 1
        fi
        secret_value="$(cat "$secret_file")"
        if [ -z "$secret_value" ]; then
            echo "${file_var} is empty." >&2
            exit 1
        fi
        export "${name}=${secret_value}"
    fi
}

load_secret APP_KEY
load_secret JWT_SECRET
load_secret DB_PASSWORD

if [ "${SSO_SERVER_MODE:-development}" = "php-fpm" ]; then
    for required_secret in APP_KEY JWT_SECRET DB_PASSWORD; do
        eval "required_value=\${${required_secret}:-}"
        if [ -z "$required_value" ]; then
            echo "${required_secret} is required in production." >&2
            exit 1
        fi
    done
fi

# Wait for MySQL to be ready (use shell env vars from docker-compose, not .env file)
MYSQL_HOST="${DB_HOST:-mysql}"
MYSQL_PORT="${DB_PORT:-3306}"
echo "Waiting for MySQL at ${MYSQL_HOST}:${MYSQL_PORT}..."
attempt=0
until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        echo "MySQL did not become ready after 120 seconds." >&2
        exit 1
    fi
    echo "MySQL not ready, retrying in 2s..."
    sleep 2
done
echo "MySQL is ready."

# Run migrations
echo "Running migrations..."
php artisan migrate --force

# Seed only when explicitly requested for a local environment.
if [ "${SSO_AUTO_SEED:-false}" = "true" ]; then
    if [ "${APP_ENV:-production}" != "local" ]; then
        echo "SSO_AUTO_SEED is only supported in a local environment." >&2
        exit 1
    fi
    EMPLOYEE_COUNT=$(php artisan tinker --execute="echo \App\Models\Employee::count();" 2>/dev/null || echo "error")
    if [ "$EMPLOYEE_COUNT" = "0" ]; then
        echo "Seeding local database..."
        php artisan db:seed --force
    elif [ "$EMPLOYEE_COUNT" = "error" ]; then
        echo "Could not check employee count; refusing to seed." >&2
        exit 1
    fi
fi

# Cache configuration and routes
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${SSO_SERVER_MODE:-development}" = "php-fpm" ]; then
    echo "Starting PHP-FPM on port 9000..."
    exec php-fpm -F
fi

echo "Starting PHP development server on port 8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
