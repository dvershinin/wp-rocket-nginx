#!/bin/sh
set -eu

cd /var/www/html

if ! wp core is-installed --allow-root 2>/dev/null; then
    wp core install \
        --url=http://nginx \
        --title='GetPageSpeed Cache Configuration E2E' \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.com \
        --skip-email \
        --allow-root
fi

wp rewrite structure '/%postname%/' --allow-root
wp rewrite flush --allow-root
wp plugin activate getpagespeed-cache-configuration --allow-root
wp eval 'update_option("getpagespeed_cache_configuration_settings", array("debug" => true)); GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->regenerate_config();' --allow-root

test -s /var/www/html/wp-content/uploads/getpagespeed-cache-configuration/default.conf
