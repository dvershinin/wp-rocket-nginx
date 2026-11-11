#!/bin/sh
set -eu

config_file=/var/www/html/wp-content/uploads/getpagespeed-cache-configuration/default.conf
config_dir=$(dirname "$config_file")

while [ ! -s "$config_file" ]; do
    sleep 1
done

watch_config() {
    while inotifywait --quiet --event close_write,create,move "$config_dir"; do
        if nginx -t >/dev/null 2>&1; then
            nginx -s reload
        fi
    done
}

watch_config &
exec nginx -g 'daemon off;'
