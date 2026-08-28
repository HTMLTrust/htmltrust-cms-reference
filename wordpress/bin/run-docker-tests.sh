#!/usr/bin/env bash

set -euo pipefail

: "${DB_NAME:=wordpress_test}"
: "${DB_USER:=wordpress}"
: "${DB_PASS:=wordpress}"
: "${DB_HOST:=db}"
: "${WP_VERSION:=6.9.4}"
: "${WP_TESTS_DIR:=/var/lib/wordpress-test-assets/tests}"
: "${WP_CORE_DIR:=/var/lib/wordpress-test-assets/wordpress}"
: "${RUN_PHPCS:=0}"

export TMPDIR="${TMPDIR:-/var/lib/wordpress-test-assets/tmp}"
export WP_TESTS_DIR WP_CORE_DIR
# A Git worktree stores .git as a pointer to the primary checkout, which is
# outside this container mount. Supplying the root version keeps Composer from
# following that host-only pointer while resolving the local root package.
export COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-dev-main}"

mkdir -p "$TMPDIR"

# The repository is mounted read-only and may belong to a different host UID.
# Composer asks Git to trust the mounted checkout before inspecting its root.
(
    cd /
    git config --global --add safe.directory /workspace
    git config --global --add safe.directory /workspace/wordpress
)

echo "Installing Composer dependencies from composer.lock..."
composer install --no-interaction --prefer-dist --no-progress

echo "Installing WordPress ${WP_VERSION} test assets..."
bin/install-wp-tests.sh \
    "$DB_NAME" \
    "$DB_USER" \
    "$DB_PASS" \
    "$DB_HOST" \
    "$WP_VERSION" \
    true

echo "Running PHPUnit..."
vendor/bin/phpunit --do-not-cache-result

if [[ "$RUN_PHPCS" == "1" ]]; then
    echo "Running PHPCS..."
    vendor/bin/phpcs --standard=WordPress --report=summary content-signing.php includes admin public
fi
