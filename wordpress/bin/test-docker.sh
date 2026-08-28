#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
compose_file="$repo_root/wordpress/docker-compose.test.yml"
checkout_id=$(printf '%s' "$repo_root" | cksum | awk '{print $1}')
compose_project=${HTMLTRUST_CMS_TEST_PROJECT:-htmltrust-cms-test-$checkout_id}
run_phpcs=0

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required. Install Docker Desktop or Docker Engine first." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose v2 is required (run: docker compose version)." >&2
    exit 1
fi

case "${1:-}" in
    "") ;;
    --lint) run_phpcs=1 ;;
    --clean)
        docker compose -p "$compose_project" -f "$compose_file" down -v --remove-orphans
        exit 0
        ;;
    *)
        echo "usage: $0 [--lint|--clean]" >&2
        exit 2
        ;;
esac

cleanup() {
    docker compose -p "$compose_project" -f "$compose_file" down --remove-orphans >/dev/null
}
trap cleanup EXIT INT TERM

RUN_PHPCS=$run_phpcs docker compose -p "$compose_project" -f "$compose_file" run --build --rm test
