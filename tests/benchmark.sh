#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
compose_project="getpagespeed-cache-configuration-benchmark-$$"
test_port=${TEST_PORT:-18091}
duration=${DURATION:-15s}
connections=${CONNECTIONS:-16}
runs=${RUNS:-5}
base_url="http://127.0.0.1:${test_port}"
output_dir=${OUTPUT_DIR:-$(mktemp -d /tmp/getpagespeed-cache-configuration-benchmark.XXXXXX)}
source_body="$output_dir/source-body.html"
php_body="$output_dir/php-fpm-body.html"
cache_body="$output_dir/nginx-body.html"
headers_file="$output_dir/headers.txt"
payload_file="$output_dir/cache-payload.json"
summary_file="$output_dir/summary.tsv"

compose() {
    TEST_PORT="$test_port" docker compose --project-name "$compose_project" --file "$script_dir/e2e/docker-compose.yml" "$@"
}

cleanup() {
    compose down --volumes >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

for command_name in column curl docker jq oha; do
    command -v "$command_name" >/dev/null 2>&1 || {
        echo "Missing required command: $command_name" >&2
        exit 1
    }
done

mkdir -p "$output_dir"

compose up --detach mysql wordpress
compose run --rm setup
compose up --detach --build --wait nginx
dd if=/dev/zero bs=1024 count=64 2>/dev/null | tr '\000' 'X' >"$source_body"
jq --null-input --rawfile content "$source_body" '{path:"/benchmark/",content:$content}' >"$payload_file"
curl --fail --silent --show-error \
    --header 'Host: nginx' \
    --header 'Accept: application/json' \
    --header 'Content-Type: application/json' \
    --request POST \
    --data-binary @"$payload_file" \
    "${base_url}/wp-json/getpagespeed-cache-configuration-test/v1/cache" | grep -q '"created":true'

jq --null-input \
    --arg content '<?php header("Content-Type: text/html"); readfile("/var/www/html/wp-content/cache/wp-rocket/nginx/benchmark/index.html");' \
    '{path:"/benchmark.php",content:$content}' >"$payload_file"
curl --fail --silent --show-error \
    --header 'Host: nginx' \
    --header 'Accept: application/json' \
    --header 'Content-Type: application/json' \
    --request POST \
    --data-binary @"$payload_file" \
    "${base_url}/wp-json/getpagespeed-cache-configuration-test/v1/asset" | grep -q '"created":true'

curl --fail --silent --show-error \
    --dump-header "$headers_file" \
    --output "$php_body" \
    --header 'Host: nginx' \
    "${base_url}/wp-content/benchmark.php"
grep -Eiq '^X-Rocket-Nginx-Serving-Static: MISS' "$headers_file"

curl --fail --silent --show-error \
    --dump-header "$headers_file" \
    --output "$cache_body" \
    --header 'Host: nginx' \
    "${base_url}/benchmark/"
grep -Eiq '^X-Rocket-Nginx-Serving-Static: HIT' "$headers_file"
cmp -s "$source_body" "$php_body" && cmp -s "$source_body" "$cache_body" || {
    echo 'Benchmark responses differ; refusing to compare unlike payloads.' >&2
    exit 1
}

run_benchmark() {
    mode=$1
    run_number=$2
    url=$3
    result_file="$output_dir/${mode}-${run_number}.json"

    env -u NO_COLOR oha \
        --no-tui \
        --wait-ongoing-requests-after-deadline \
        -z "$duration" \
        -c "$connections" \
        --host nginx \
        --output-format json \
        "$url" >"$result_file"

    jq --raw-output \
        --arg mode "$mode" \
        --arg run "$run_number" \
        '[
            $mode,
            $run,
            .summary.requestsPerSec,
            (.latencyPercentiles.p50 * 1000),
            (.latencyPercentiles.p95 * 1000),
            .summary.successRate,
            .summary.sizePerRequest
        ] | @tsv' \
        "$result_file" >>"$summary_file"
}

env -u NO_COLOR oha --no-tui -n 100 -c 8 --host nginx --output-format quiet "${base_url}/benchmark/" >/dev/null
env -u NO_COLOR oha --no-tui -n 100 -c 8 --host nginx --output-format quiet "${base_url}/wp-content/benchmark.php" >/dev/null

printf 'mode\trun\trequests_per_second\tp50_ms\tp95_ms\tsuccess_rate\tbytes_per_response\n' >"$summary_file"

run_number=1
while [ "$run_number" -le "$runs" ]; do
    if [ $((run_number % 2)) -eq 1 ]; then
        run_benchmark php_fpm "$run_number" "${base_url}/wp-content/benchmark.php"
        run_benchmark nginx "$run_number" "${base_url}/benchmark/"
    else
        run_benchmark nginx "$run_number" "${base_url}/benchmark/"
        run_benchmark php_fpm "$run_number" "${base_url}/wp-content/benchmark.php"
    fi
    run_number=$((run_number + 1))
done

echo "Benchmark results: $summary_file"
column -t -s "$(printf '\t')" "$summary_file"
