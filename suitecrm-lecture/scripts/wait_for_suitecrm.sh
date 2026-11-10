#!/usr/bin/env bash
set -euo pipefail

TIMEOUT_SECONDS=1200
INTERVAL_SECONDS=15
START_TIME=$(date +%s)
LEGACY_CONFIG_PATH="/bitnami/suitecrm/public/legacy/config.php"
WAIT_URL="${SUITECRM_WAIT_URL:-http://localhost:8080/}"

echo "[wait] Waiting up to ${TIMEOUT_SECONDS}s for SuiteCRM at ${WAIT_URL} ..."

check_ready() {
    local http_code
    http_code=$(curl -s -o /dev/null -w "%{http_code}" -L --max-time 10 "${WAIT_URL}" || true)
    if [[ "${http_code}" != "200" ]]; then
        return 1
    fi

    if ! docker compose exec -T suitecrm bash -c "test -f '${LEGACY_CONFIG_PATH}' && grep -Eq \"'installer_locked'[[:space:]]*=>[[:space:]]*true\" '${LEGACY_CONFIG_PATH}'" >/dev/null 2>&1; then
        return 2
    fi

    return 0
}

while true; do
    NOW=$(date +%s)
    ELAPSED=$((NOW - START_TIME))

    if check_ready; then
        # Confirm a second time 5s later so we do not catch transient Apache during Bitnami wizard before persist_app finishes
        sleep 5
        if check_ready; then
            echo "[wait] READY after ${ELAPSED}s (HTTP 200 + ${LEGACY_CONFIG_PATH} installer_locked verified)."
            exit 0
        fi
    fi

    if (( ELAPSED >= TIMEOUT_SECONDS )); then
        echo "[wait] ERROR: Timed out after ${ELAPSED}s waiting for SuiteCRM readiness." >&2
        echo "[wait] Last 100 lines of suitecrm container logs:" >&2
        docker compose logs --tail=100 suitecrm >&2 || true
        exit 1
    fi

    http_now=$(curl -s -o /dev/null -w "%{http_code}" -L --max-time 5 "${WAIT_URL}" || true)
    echo "[wait] Still initializing... elapsed=${ELAPSED}s/${TIMEOUT_SECONDS}s (http_code=${http_now:-000})"
    sleep "${INTERVAL_SECONDS}"
done
