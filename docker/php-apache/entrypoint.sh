#!/bin/sh
set -e

uploads_dir=/var/www/html/wp-content/uploads
owner_path=$uploads_dir

if [ ! -d "$owner_path" ]; then
  owner_path=/var/www/html/wp-content
fi

if [ -d "$owner_path" ]; then
  owner_ids=$(stat -c '%u:%g' "$owner_path")
  owner_uid=${owner_ids%:*}
  owner_gid=${owner_ids#*:}

  if [ "$owner_uid" != "0" ] && [ "$owner_gid" != "0" ]; then
    groupmod -o -g "$owner_gid" www-data
    usermod -o -u "$owner_uid" -g "$owner_gid" www-data
  fi
fi

install -d -o www-data -g www-data -m 0755 "$uploads_dir"
find "$uploads_dir" -type d -exec chown www-data:www-data {} +
mkdir -p /var/www/papelito-private/labels
chown -R www-data:www-data /var/www/papelito-private

exec docker-php-entrypoint "$@"
