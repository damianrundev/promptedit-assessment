#!/usr/bin/env bash
# Deploy from a developer machine to the shared Ubuntu/Apache server. Additive: touches only /var/www/promptedit.
#   HOST=ubuntu@1.2.3.4 bash deploy/deploy.sh
set -euo pipefail
cd "$(dirname "$0")/.."
HOST=${HOST:?set HOST=user@server}
APP=/var/www/promptedit
PHP=/usr/bin/php8.4

[ -d vendor/tightenco/ziggy ] || composer install --no-dev --ignore-platform-reqs   # the JS build imports ziggy from vendor/
npm ci --silent && npm run build

rsync -az --delete \
  --exclude=.git --exclude=.env --exclude=.env.testing --exclude=node_modules --exclude=vendor \
  --exclude=/storage --exclude=/bootstrap/cache --exclude=/tests --exclude=/deploy \
  ./ "$HOST:$APP/"

ssh "$HOST" "set -e; cd $APP
  composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction -q
  sudo chown -R www-data:www-data storage bootstrap/cache
  sudo -u www-data $PHP artisan migrate --force
  sudo -u www-data $PHP artisan storage:link --force >/dev/null
  sudo -u www-data $PHP artisan optimize
  sudo -u www-data $PHP artisan queue:restart"
echo "Deployed."
