#!/bin/sh
set -e

mkdir -p /var/www/papelito-private/labels
chown -R www-data:www-data /var/www/papelito-private

exec docker-php-entrypoint "$@"
