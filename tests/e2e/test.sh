#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
compose_project="getpagespeed-cache-configuration-e2e-$$"
test_port=${TEST_PORT:-8090}
base_url="http://127.0.0.1:${test_port}"
api_url="${base_url}/wp-json/getpagespeed-cache-configuration-test/v1"
headers_file=$(mktemp /tmp/getpagespeed-cache-configuration-headers.XXXXXX)
body_file=$(mktemp /tmp/getpagespeed-cache-configuration-body.XXXXXX)

compose() {
    TEST_PORT="$test_port" docker compose --project-name "$compose_project" --file "$script_dir/docker-compose.yml" "$@"
}

cleanup() {
    compose down --volumes >/dev/null 2>&1 || true
    rm -f "$headers_file" "$body_file"
}
trap cleanup EXIT INT TERM

fail() {
    echo "FAIL: $1" >&2
    # Show the last exchange: without it a CI failure cannot be diagnosed.
    echo '--- last response headers ---' >&2
    cat "$headers_file" >&2 || true
    echo '--- last response body (first 20 lines) ---' >&2
    head -n 20 "$body_file" >&2 || true
    exit 1
}

post_json() {
    endpoint=$1
    payload=$2
    curl --fail --silent --show-error \
        --header 'Host: nginx' \
        --header 'Accept: application/json' \
        --header 'Content-Type: application/json' \
        --request POST \
        --data "$payload" \
        --output "$body_file" \
        "${api_url}/${endpoint}"
    grep -Eq '"(created|updated)":true' "$body_file" || fail "Test API ${endpoint} did not confirm the requested change"
}

request() {
    curl --silent --show-error \
        --dump-header "$headers_file" \
        --output "$body_file" \
        --header 'Host: nginx' \
        "$@"
}

assert_header() {
    name=$1
    value=$2
    grep -Eiq "^${name}: ${value}([[:space:]]|$)" "$headers_file" || fail "Expected ${name}: ${value}"
}

# The Nginx container reloads when the generated file changes. Wait until the
# probe answers with the token the test API just reported for the file on disk.
wait_for_reload() {
    token=$(sed -n 's/.*"token":"\([^"]*\)".*/\1/p' "$body_file")
    [ -n "$token" ] || fail 'Test API did not report the generated probe token'
    attempt=0
    while [ "$attempt" -lt 20 ]; do
        request "${base_url}/getpagespeed-cache-configuration-probe"
        if grep -Fxq "$token" "$body_file"; then
            return 0
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail "Nginx did not load the regenerated configuration (${token})"
}

assert_body() {
    expected=$1
    grep -Fq "$expected" "$body_file" || fail "Expected body to contain: ${expected}"
}

compose up --detach mysql wordpress
compose run --rm setup
compose up --detach --build --wait nginx

post_json cache '{"path":"/cached-page/","content":"DESKTOP_CACHE_MARKER"}'
request "${base_url}/cached-page/"
assert_header X-Rocket-Nginx-Serving-Static HIT
assert_body DESKTOP_CACHE_MARKER
echo 'PASS desktop cache hit'

post_json cache '{"path":"/tracked/","content":"TRACKING_CACHE_MARKER"}'
request "${base_url}/tracked/?utm_source=e2e"
assert_header X-Rocket-Nginx-Serving-Static HIT
assert_body TRACKING_CACHE_MARKER
echo 'PASS tracking query is ignored'

request "${base_url}/cached-page/?unknown=1"
assert_header X-Rocket-Nginx-Serving-Static BYPASS
echo 'PASS unknown query bypasses cache'

request --header 'Cookie: wordpress_logged_in_test=1' "${base_url}/cached-page/"
assert_header X-Rocket-Nginx-Serving-Static BYPASS
echo 'PASS authenticated cookie bypasses cache'

post_json settings '{"cache_mobile":1,"do_caching_mobile_files":1,"cache_query_strings":["amp"]}'
post_json cache '{"path":"/amp-page/","args":"amp=1","content":"AMP_MOBILE_CACHE_MARKER","mobile":true,"mobile_marker":true}'

attempt=0
while [ "$attempt" -lt 20 ]; do
    request --header 'User-Agent: Mozilla/5.0 (iPhone; Mobile)' "${base_url}/amp-page/?amp=1"
    if grep -Eiq '^X-Rocket-Nginx-Serving-Static: HIT' "$headers_file"; then
        break
    fi
    attempt=$((attempt + 1))
    sleep 1
done
assert_header X-Rocket-Nginx-Serving-Static HIT
assert_header X-Rocket-Nginx-Device mobile
assert_body AMP_MOBILE_CACHE_MARKER
grep -Fq 'index-mobile.html' "$headers_file" || fail 'Expected mobile cache filename in diagnostic header.'
echo 'PASS issue 232 query-string mobile cache regression'

post_json asset '{"path":"/uploads/e2e.css","content":"body{color:#123}"}'
request "${base_url}/wp-content/uploads/e2e.css"
assert_header Cache-Control 'max-age=7776000'
assert_body 'body{color:#123}'
echo 'PASS Nginx serves static assets directly'

request "${base_url}/wp-content/uploads/getpagespeed-cache-configuration/default.conf"
grep -Eq '^HTTP/[0-9.]+ 403' "$headers_file" || fail 'Generated configuration must return HTTP 403.'
echo 'PASS generated configuration is not web-accessible'

post_json check '{}'
grep -Fq '"status":"active"' "$body_file" || fail "Include check must report active, got: $(cat "$body_file")"
echo 'PASS include check confirms Nginx loaded the WP Rocket configuration'

# Switch to WP Super Cache: its settings live in wp-cache-config.php, not in an option.
post_json wp-super-cache '{"cache_compression":0}'
wait_for_reload
post_json cache '{"layout":"supercache","path":"/wpsc-page/","content":"WPSC_PLAIN_MARKER"}'
request "${base_url}/wpsc-page/"
assert_header X-GetPageSpeed-Cache HIT
assert_header Cache-Control 'max-age=3, must-revalidate'
assert_header Content-Type 'text/html; charset=UTF-8'
assert_body WPSC_PLAIN_MARKER
grep -Fq 'supercache/nginx/wpsc-page/index.html' "$headers_file" || fail 'Expected the supercache filename in the diagnostic header.'
echo 'PASS WP Super Cache hit'

post_json cache '{"layout":"supercache","path":"/wpsc-page/","content":"WPSC_HTTPS_MARKER","https":true}'
request --header 'X-Forwarded-Proto: https' "${base_url}/wpsc-page/"
assert_header X-GetPageSpeed-Cache HIT
assert_body WPSC_HTTPS_MARKER
echo 'PASS WP Super Cache serves the HTTPS variant'

request --header 'Cookie: wordpress_logged_in_test=1' "${base_url}/wpsc-page/"
assert_header X-GetPageSpeed-Cache BYPASS
request --header 'Cookie: comment_author_abc=me' "${base_url}/wpsc-page/"
assert_header X-GetPageSpeed-Cache BYPASS
echo 'PASS WP Super Cache cookies bypass cache'

request "${base_url}/wpsc-page/?unknown=1"
assert_header X-GetPageSpeed-Cache BYPASS
request --request POST "${base_url}/wpsc-page/"
assert_header X-GetPageSpeed-Cache BYPASS
echo 'PASS WP Super Cache query strings and POST bypass cache'

request "${base_url}/wpsc-page"
assert_header X-GetPageSpeed-Cache BYPASS
request --path-as-is "${base_url}/wpsc-page/x/../"
assert_header X-GetPageSpeed-Cache BYPASS
echo 'PASS WP Super Cache missing trailing slash and dot segments reach PHP'

request "${base_url}/wp-content/cache/supercache/nginx/wpsc-page/index.html"
grep -Eq '^HTTP/[0-9.]+ 404' "$headers_file" || fail 'Cache files must not be directly requestable.'
echo 'PASS WP Super Cache files are internal'

request "${base_url}/missing-page/"
assert_header X-GetPageSpeed-Cache MISS
echo 'PASS WP Super Cache miss reaches PHP'

post_json wp-super-cache '{"cache_compression":1,"wp_cache_mobile_enabled":1}'
wait_for_reload
post_json cache '{"layout":"supercache","path":"/wpsc-gzip/","content":"WPSC_PLAIN_MARKER","gzip_content":"WPSC_GZIP_MARKER"}'
request --compressed "${base_url}/wpsc-gzip/"
assert_header X-GetPageSpeed-Cache HIT
assert_header Content-Encoding gzip
assert_header Content-Type 'text/html; charset=UTF-8'
assert_body WPSC_GZIP_MARKER
# Explicit header: some curl builds advertise gzip by default.
request --header 'Accept-Encoding: identity' "${base_url}/wpsc-gzip/"
assert_header X-GetPageSpeed-Cache HIT
assert_body WPSC_PLAIN_MARKER
grep -Eiq '^Content-Encoding:' "$headers_file" && fail 'A client that does not accept gzip must receive the plain file.'
echo 'PASS WP Super Cache serves the pre-compressed file only to gzip clients'

request --header 'User-Agent: Mozilla/5.0 (iPhone; Mobile)' "${base_url}/wpsc-gzip/"
assert_header X-GetPageSpeed-Cache BYPASS
echo 'PASS WP Super Cache mobile support sends mobile browsers to PHP'

post_json asset '{"path":"/uploads/e2e-wpsc.css","content":"body{color:#456}"}'
request "${base_url}/wp-content/uploads/e2e-wpsc.css"
assert_header Cache-Control 'max-age=7776000'
echo 'PASS static assets keep their rules under WP Super Cache'

post_json check '{}'
grep -Fq '"status":"active"' "$body_file" || fail "Include check must report active, got: $(cat "$body_file")"
echo 'PASS include check confirms Nginx loaded the WP Super Cache configuration'

echo '19 E2E tests, 0 failures'
