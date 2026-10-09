#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p tests/.runtime
if [ ! -f tests/.runtime/wp-cli.phar ]; then
  curl --fail --location --silent --show-error https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o tests/.runtime/wp-cli.phar
fi
export WCMCF_TEST_PORT="${WCMCF_TEST_PORT:-18086}"
compose=(docker compose -p wcmcf-integration -f tests/compose.yaml)
cleanup() {
  if [ "${WCMCF_KEEP_TEST_SITE:-0}" != "1" ]; then
    "${compose[@]}" down --volumes --remove-orphans >/dev/null
  fi
}
trap cleanup EXIT
"${compose[@]}" up --detach --wait --wait-timeout 180
wp() { "${compose[@]}" exec -T --user www-data --env WP_CLI_CACHE_DIR=/tmp/wp-cli-cache wordpress php /opt/wcmcf-tests/.runtime/wp-cli.phar "$@"; }
# Pin the lowest supported WordPress/WooCommerce series rather than testing only latest.
wp core download --version="${WCMCF_TEST_WP_VERSION:-6.6.2}" --force --skip-content --quiet
wp core install --url="http://localhost:${WCMCF_TEST_PORT}" --title="WCMCF integration" --admin_user=wcmcf-admin --admin_password=wcmcf-local-test-only --admin_email=test@example.org --skip-email
wp plugin install woocommerce --version="${WCMCF_TEST_WC_VERSION:-9.6.2}" --activate
if wp cli has-command "action-scheduler migrate"; then
  wp action-scheduler migrate
fi
wp option update woocommerce_currency ALL
wp plugin activate woocommerce-meta-catalog-feed
python3 tests/check-http.py "http://localhost:${WCMCF_TEST_PORT}" --initial
wp eval-file /opt/wcmcf-tests/integration.php
wp eval-file /opt/wcmcf-tests/selection.php
wp plugin install woo-discount-rules --version=2.6.18 --activate
wp eval-file /opt/wcmcf-tests/discounts.php
if [ -n "${WCMCF_PLAYWRIGHT_MODULE:-}" ]; then
  node tests/check-ui.cjs "http://localhost:${WCMCF_TEST_PORT}"
fi
python3 tests/check-http.py "http://localhost:${WCMCF_TEST_PORT}"
wp action-scheduler run --group=wcmcf --batch-size=100
wp eval-file /opt/wcmcf-tests/scheduler-check.php
wp eval-file /opt/wcmcf-tests/missing-woocommerce.php --skip-plugins=woocommerce
wp eval-file /opt/wcmcf-tests/uninstall-check.php
echo 'Integration and HTTP checks passed.'
