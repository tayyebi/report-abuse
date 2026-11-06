#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Runtime directories are often a mounted volume: make sure they exist.
mkdir -p storage/uploads storage/outbox storage/logs
chown -R www-data:www-data storage 2>/dev/null || true
chmod -R 775 storage 2>/dev/null || true

echo "→ Applying database schema (idempotent)…"
php bin/console migrate

echo "→ Seeding the bootstrap administrator…"
php bin/console seed || echo "  (skipped: no ADMIN_EMAIL configured)"

if [ "${APP_DEBUG:-false}" = "true" ]; then
    echo "→ Debug mode is ON"
fi

echo "→ Starting: $*"
exec "$@"
