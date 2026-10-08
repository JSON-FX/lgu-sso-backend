#!/bin/sh
set -eu

export MYSQL_PWD="$(cat /run/secrets/db_password)"
interval="${SSO_BACKUP_INTERVAL_SECONDS:-86400}"
retention="${SSO_BACKUP_RETENTION_DAYS:-14}"

case "$interval:$retention" in
    *[!0-9:]*|:*|*:) echo "Backup interval and retention must be positive integers." >&2; exit 1 ;;
esac
if [ "$interval" -lt 1 ] || [ "$retention" -lt 1 ]; then
    echo "Backup interval and retention must be positive integers." >&2
    exit 1
fi

while :; do
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    target="/backups/lgu-sso-${stamp}.sql.gz"
    temporary="/backups/.lgu-sso-${stamp}.sql.partial"
    storage_target="/backups/lgu-sso-storage-${stamp}.tar.gz"
    storage_temporary="${storage_target}.partial"
    if mysqldump --single-transaction --quick --no-tablespaces \
        --set-gtid-purged=OFF --host=mysql --user="$MYSQL_USER" \
        "$MYSQL_DATABASE" > "$temporary" \
        && gzip -9 "$temporary" \
        && gzip -t "${temporary}.gz" \
        && tar -czf "$storage_temporary" -C /app/storage app \
        && gzip -t "$storage_temporary"; then
        mv "${temporary}.gz" "$target"
        mv "$storage_temporary" "$storage_target"
        date -u +%Y-%m-%dT%H:%M:%SZ > /backups/.last-success
        echo "Backup completed: ${target} and ${storage_target}"
        find /backups -type f -name 'lgu-sso-*.sql.gz' -mtime "+${retention}" -delete
        find /backups -type f -name 'lgu-sso-storage-*.tar.gz' -mtime "+${retention}" -delete
    else
        rm -f "$temporary" "${temporary}.gz" "$storage_temporary"
        echo "Backup failed." >&2
        exit 1
    fi
    sleep "$interval"
done
