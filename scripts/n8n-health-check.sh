#!/usr/bin/env bash
# n8n health check — reports failed executions for Davya workflows in the last 24h.
# Reads N8N_API_KEY and N8N_BASE_URL from the project .env.
# Exit codes: 0 = ok, 1 = errors found, 2 = API/config failure.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: $ENV_FILE not found" >&2
  exit 2
fi

# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a

: "${N8N_API_KEY:?N8N_API_KEY missing from .env}"
: "${N8N_BASE_URL:=https://n8n.srv1117424.hstgr.cloud}"

WORKFLOWS=(
  "2nkOuzg3IK4n2Mhy|Davya Lead Capture — Sheets → CRM"
  "yO0nzgy8KvdneITL|Davya Finance — Slack → CRM"
)

SINCE_EPOCH=$(( $(date +%s) - 86400 ))
TOTAL_ERRORS=0

for entry in "${WORKFLOWS[@]}"; do
  wid="${entry%%|*}"
  name="${entry#*|}"
  body=$(curl -sS --max-time 15 \
    -H "X-N8N-API-KEY: $N8N_API_KEY" \
    "$N8N_BASE_URL/api/v1/executions?workflowId=$wid&limit=50&status=error")

  errs=$(python3 - "$body" "$SINCE_EPOCH" <<'PY'
import json, sys, datetime
body, since = sys.argv[1], int(sys.argv[2])
try:
    data = json.loads(body).get('data', [])
except Exception as e:
    print(f'PARSE_ERROR:{e}')
    sys.exit(0)
recent = []
for e in data:
    ts = e.get('stoppedAt') or e.get('startedAt') or e.get('createdAt')
    if not ts: continue
    try:
        t = datetime.datetime.fromisoformat(ts.replace('Z','+00:00')).timestamp()
    except Exception:
        continue
    if t >= since:
        recent.append(f"  - {ts}  id={e.get('id')}  mode={e.get('mode')}")
print(len(recent))
for line in recent: print(line)
PY
)
  count=$(echo "$errs" | head -n1)
  echo "[$wid] $name — errors in last 24h: $count"
  if [[ "$count" =~ ^[0-9]+$ ]] && [[ "$count" -gt 0 ]]; then
    echo "$errs" | tail -n +2
    TOTAL_ERRORS=$((TOTAL_ERRORS + count))
  fi
done

echo ""
if [[ "$TOTAL_ERRORS" -gt 0 ]]; then
  echo "UNHEALTHY: $TOTAL_ERRORS error execution(s) in last 24h"
  exit 1
fi
echo "OK: no errors in last 24h"
exit 0
