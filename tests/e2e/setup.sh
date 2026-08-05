#!/bin/sh
set -eu

cd /var/www/html

if ! wp core is-installed --allow-root 2>/dev/null; then
    wp core install \
        --url=http://nginx \
        --title='WP Rocket Nginx E2E' \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.com \
        --skip-email \
        --allow-root
fi

wp rewrite structure '/%postname%/' --allow-root
wp rewrite flush --allow-root
wp plugin activate wp-rocket-nginx --allow-root
wp eval 'update_option("wp_rocket_nginx_settings", array("debug" => true)); WPRocketNginx\wp_rocket_nginx()->regenerate_config();' --allow-root

test -s /var/www/html/wp-content/wp-rocket-nginx/default.conf
