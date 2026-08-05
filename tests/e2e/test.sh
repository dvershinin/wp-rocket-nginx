#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
compose_project="wp-rocket-nginx-e2e-$$"
test_port=${TEST_PORT:-8090}
base_url="http://127.0.0.1:${test_port}"
api_url="${base_url}/wp-json/wp-rocket-nginx-test/v1"
headers_file=$(mktemp /tmp/wp-rocket-nginx-headers.XXXXXX)
body_file=$(mktemp /tmp/wp-rocket-nginx-body.XXXXXX)

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

request "${base_url}/wp-content/wp-rocket-nginx/default.conf"
grep -Eq '^HTTP/[0-9.]+ 403' "$headers_file" || fail 'Generated configuration must return HTTP 403.'
echo 'PASS generated configuration is not web-accessible'

echo '7 E2E tests, 0 failures'
