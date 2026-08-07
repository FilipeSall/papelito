#!/bin/sh

set -eu

if [ ! -x vendor/bin/phpcs ]; then
  composer install --no-interaction --prefer-dist
fi

exec ./vendor/bin/phpcs --standard=phpcs.xml.dist "$@"
