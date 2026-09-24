#!/usr/bin/env bash
set -euo pipefail
trap 'result=$?; if [[ $result -ne 0 ]]; then echo "Deployment stopped at line $LINENO (exit $result). See the message above."; fi' EXIT
printf '%s\n' 'Starting DroopNexa deployment.'

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
private=$(dirname "$repo")/droopnexa-private
php_bin=/opt/cpanel/ea-php83/root/usr/bin/php
if [[ ! -x "$php_bin" ]]; then php_bin=/opt/alt/php83/usr/bin/php; fi
if [[ ! -x "$php_bin" ]]; then echo 'PHP 8.3 CLI was not found. Enable PHP 8.3 in cPanel.' >&2; exit 1; fi
"$php_bin" -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 3) { fwrite(STDERR, "PHP 8.3 is required.\n"); exit(1); }'
printf '%s\n' "PHP executable: $php_bin"
mkdir -p "$private"
rsync -a --delete --exclude='.env' --exclude='.deployment-ready' --exclude='storage/' --exclude='bootstrap/cache/*.php' "$repo/.release/backend/" "$private/"
mkdir -p "$private/storage/app/public" "$private/storage/app/private" "$private/storage/framework/cache/data" "$private/storage/framework/sessions" "$private/storage/framework/views" "$private/storage/logs" "$private/bootstrap/cache"
chmod -R u+rwX,go+rX "$private/storage" "$private/bootstrap/cache"
cd "$private"
if [[ ! -f .env ]]; then
  cp .env.production.example .env
  chmod 600 .env
  echo "Created $private/.env. Set DB_PASSWORD in this file, then click Deploy HEAD Commit again."
  exit 1
fi
if ! "$php_bin" -r 'require "vendor/autoload.php"; $env = Dotenv\Dotenv::createImmutable(getcwd())->safeLoad(); exit(empty($env["DB_PASSWORD"]) ? 1 : 0);'; then
  echo "Set DB_PASSWORD in $private/.env, then deploy again." >&2
  exit 1
fi
"$php_bin" artisan config:clear --no-interaction
"$php_bin" artisan package:discover --no-interaction
if ! "$php_bin" -r 'require "vendor/autoload.php"; $env = Dotenv\Dotenv::createImmutable(getcwd())->safeLoad(); exit(empty($env["APP_KEY"]) ? 1 : 0);'; then
  "$php_bin" artisan key:generate --force --no-interaction
fi
"$php_bin" artisan migrate --force --no-interaction
"$php_bin" artisan config:cache --no-interaction
"$php_bin" artisan route:cache --no-interaction
if [[ ! -e "$repo/storage" ]]; then ln -s "$private/storage/app/public" "$repo/storage"; fi
touch "$private/.deployment-ready"
printf '%s\n' 'DroopNexa deployed with PHP 8.3. No demo users or demo orders were created.'
