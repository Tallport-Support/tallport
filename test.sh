#!/usr/bin/env bash
#
# Run the test suite.
#
#   ./test.sh [phpunit options]      e.g. ./test.sh --filter SelfUpdater
#
# Test tools are installed into dev/vendor (see dev/composer.json). Tests run
# on an in-memory SQLite database (the "testing" connection, config/database.php),
# built at the start of each run; DB_TEST_DRIVER=mysql or pgsql runs it on MariaDB or
# MySQL (DB_TEST_PORT) or PostgreSQL instead. The few that need MariaDB
# (Tests\Concerns\UsesMariaDB: the search on MariaDB, production's schema) use the
# "testing_mariadb" connection and are skipped without it: by default database, user and
# password "freescout-test" on 127.0.0.1; override with DB_TEST_HOST, DB_TEST_MARIADB_PORT,
# DB_TEST_DATABASE, DB_TEST_USERNAME and DB_TEST_PASSWORD. To create that database (as a
# MySQL/MariaDB admin):
#
#   CREATE DATABASE `freescout-test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#   CREATE USER 'freescout-test'@'localhost' IDENTIFIED BY 'freescout-test';
#   GRANT ALL ON `freescout-test`.* TO 'freescout-test'@'localhost';

set -euo pipefail

cd "$(dirname "$0")"

if [ ! -f dev/vendor/autoload.php ] || [ dev/composer.lock -nt dev/vendor/autoload.php ]; then
    composer install -d dev --no-interaction --quiet
    touch dev/vendor/autoload.php
fi

# A cached config makes Laravel ignore the testing environment from phpunit.xml.
if [ -f bootstrap/cache/config.php ]; then
    php artisan config:clear >/dev/null
fi

# A full run (no arguments) also checks the code style (phpcs.xml, as CI does)
# and that everything the application exposes is exercised by some test
# (tests/inventory.php).
if [ $# -gt 0 ]; then
    exec php dev/vendor/bin/phpunit "$@"
fi

mkdir -p coverage
export TALLPORT_EXERCISED_LOG="$PWD/coverage/exercised.log"
: > "$TALLPORT_EXERCISED_LOG"

php dev/vendor/bin/phpcs -q
php dev/vendor/bin/phpstan analyse --configuration=dev/phpstan.neon --no-progress --memory-limit=1G
php dev/vendor/bin/phpunit
php tests/inventory.php "$TALLPORT_EXERCISED_LOG"
