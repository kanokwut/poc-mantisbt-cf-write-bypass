#!/bin/bash
# Builds MantisBT inside the php:8.2-apache container and seeds the scenario.
# MANTIS_VERSION picks the tag: release-2.28.4 (vulnerable) or release-2.29.0 (fixed).
# The bug does not depend on the database. PostgreSQL is just what the lab uses.
set -e
MANTIS_VERSION="${MANTIS_VERSION:-release-2.28.4}"

echo "[*] Installing packages and PHP extensions"
apt-get update -qq
apt-get install -y -qq git unzip curl libpq-dev libpng-dev libjpeg-dev libfreetype6-dev \
    libxml2-dev libonig-dev libzip-dev libcurl4-openssl-dev >/dev/null 2>&1
docker-php-ext-configure gd --with-freetype --with-jpeg >/dev/null 2>&1
docker-php-ext-install pgsql pdo_pgsql mbstring gd zip soap curl >/dev/null 2>&1
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1

echo "[*] Fetching MantisBT $MANTIS_VERSION"
cd /var/www
rm -rf html
git clone -q --depth 1 --branch "$MANTIS_VERSION" https://github.com/mantisbt/mantisbt.git html
cd html
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction -q 2>/dev/null \
  || COMPOSER_ALLOW_SUPERUSER=1 composer update --no-dev --no-interaction -q

# Only what an install needs. Everything else stays at the shipped defaults.
cat > config/config_inc.php <<'CONF'
<?php
$g_hostname      = 'db';
$g_db_type       = 'pgsql';
$g_database_name = 'bugtracker';
$g_db_username   = 'mantis';
$g_db_password   = 'mantis';
$g_default_timezone = 'UTC';
$g_crypto_master_salt = 'lab-only-salt-0123456789abcdef0123456789abcdef';
$g_enable_email_notification = OFF;
CONF

echo "[*] Starting Apache"
apache2-foreground &
APACHE_PID=$!
sleep 3

echo "[*] Running the web installer"
curl -s -o /dev/null -X POST "http://localhost/admin/install.php" \
    -d "install=2" -d "db_type=pgsql" -d "hostname=db" -d "database_name=bugtracker" \
    -d "db_username=mantis" -d "db_password=mantis" \
    -d "admin_username=mantis" -d "admin_password=mantis"
rm -rf admin

echo "[*] Seeding the scenario"
cp /seed.php /var/www/html/lab_seed.php
php /var/www/html/lab_seed.php
rm /var/www/html/lab_seed.php

echo "[*] MantisBT $MANTIS_VERSION is ready"
wait $APACHE_PID
