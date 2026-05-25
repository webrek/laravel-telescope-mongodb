#!/usr/bin/env bash

# Benchmark Telescope's storage path: same Laravel app, same routes,
# same request count — one variant writes through this driver to
# MongoDB, the other writes through the stock Eloquent driver to MySQL.
#
# Designed to run from the host. Expects:
#   docker compose --profile bench up -d
# and both playground/ and playground-mysql/ already bootstrapped via
# the scripts in this directory.

set -euo pipefail

REQUESTS="${REQUESTS:-500}"
CONCURRENCY="${CONCURRENCY:-10}"
ROUTE="${ROUTE:-/log-something}"

MONGO_URL="http://127.0.0.1:8000${ROUTE}"
MYSQL_URL="http://127.0.0.1:8001${ROUTE}"

bold() { printf "\033[1m%s\033[0m\n" "$1"; }
hr()   { printf -- "--------------------------------------------------\n"; }

require_endpoint() {
    local url="$1"
    if ! curl -sSf -o /dev/null --max-time 5 "$url"; then
        echo "ERROR: cannot reach $url. Is the playground container running?"
        exit 1
    fi
}

bench_one() {
    local label="$1"
    local url="$2"
    local container="$3"

    bold "[$label] warming up"
    for _ in $(seq 1 20); do curl -sS -o /dev/null "$url"; done

    bold "[$label] resetting Telescope entries"
    case "$label" in
        mongo)
            docker compose exec -T mongo mongosh --quiet telescope_playground \
                --eval 'db.telescope_entries.deleteMany({})' >/dev/null
            ;;
        mysql)
            docker compose exec -T mysql mysql -uroot -proot telescope_bench \
                -e 'SET FOREIGN_KEY_CHECKS=0; TRUNCATE telescope_entries_tags; TRUNCATE telescope_entries; TRUNCATE telescope_monitoring; SET FOREIGN_KEY_CHECKS=1;' 2>/dev/null
            ;;
    esac

    bold "[$label] sampling baseline DB container CPU"
    local cpu_before
    cpu_before=$(docker stats --no-stream --format '{{.CPUPerc}}' "$container" | tr -d '%')

    bold "[$label] firing $REQUESTS requests with concurrency $CONCURRENCY against $url"
    local tmpfile
    tmpfile=$(mktemp)
    local start_ns end_ns elapsed_ms
    start_ns=$(date +%s%N)
    seq 1 "$REQUESTS" | xargs -n1 -P"$CONCURRENCY" -I{} \
        curl -sS -o /dev/null -w "%{time_total}\n" "$url" >>"$tmpfile"
    end_ns=$(date +%s%N)
    elapsed_ms=$(( (end_ns - start_ns) / 1000000 ))

    bold "[$label] sampling DB container CPU after load"
    local cpu_after
    cpu_after=$(docker stats --no-stream --format '{{.CPUPerc}}' "$container" | tr -d '%')

    bold "[$label] computing percentiles"
    sort -n "$tmpfile" -o "$tmpfile"
    local total p50 p95 p99
    total=$(wc -l < "$tmpfile" | tr -d ' ')
    p50=$(awk -v n="$total" 'NR==int(n*0.50){print; exit}' "$tmpfile")
    p95=$(awk -v n="$total" 'NR==int(n*0.95){print; exit}' "$tmpfile")
    p99=$(awk -v n="$total" 'NR==int(n*0.99){print; exit}' "$tmpfile")

    rm -f "$tmpfile"

    local rps
    rps=$(awk "BEGIN { printf \"%.1f\", $REQUESTS / ($elapsed_ms / 1000) }")

    hr
    echo "$label results"
    echo "  total wall time : ${elapsed_ms} ms"
    echo "  throughput      : ${rps} req/s"
    echo "  p50 latency     : ${p50} s"
    echo "  p95 latency     : ${p95} s"
    echo "  p99 latency     : ${p99} s"
    echo "  DB CPU before   : ${cpu_before}%"
    echo "  DB CPU after    : ${cpu_after}%"
    hr
    echo
}

require_endpoint "$MONGO_URL"
require_endpoint "$MYSQL_URL"

echo "Benchmark configuration"
hr
echo "  Route       : $ROUTE"
echo "  Requests    : $REQUESTS"
echo "  Concurrency : $CONCURRENCY"
hr
echo

bench_one mongo "$MONGO_URL" telescope-mongodb-test
bench_one mysql "$MYSQL_URL" telescope-mongodb-mysql

echo "Done."
