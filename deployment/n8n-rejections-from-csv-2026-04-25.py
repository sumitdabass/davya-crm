#!/usr/bin/env python3
"""
Post-process bulk-backfill CSV: append every non-success row to the central
Rejections Google Sheet so it's visible alongside n8n-flow rejections.

A "non-success" row is anything where status is NOT 201 (created) and NOT 409
(silent dupe). That captures: 422 (validation), 0 (network), 5xx, 429, "skip".

Sheet headers (must match): Timestamp | Owner | Source Sheet Id | Original Row Number | Row Data JSON | Error
"""
import csv
import json
import os
import sys
import time
import urllib.parse
import urllib.request

ENV = "/Users/Sumit/davya-crm/.env"
RESULTS_CSV = "/Users/Sumit/davya-crm/deployment/backups/n8n-20260425/backfill-results.csv"
REJECTIONS_DOC = "10tjTmA39Lmdq3kJhWI_MZCOZmswRcSz9zpjlgEwQcHs"
REJECTIONS_TAB = "Rejections"

SHEET_OWNER = {"sumit": "Sumit", "nikhil": "Nikhil", "sonam": "Sonam"}
SHEET_DOC = {
    "sumit":  "1vPqJBM8h_QQ-LhDsfCY76sbJJr0AffnldUMFmZ9s98w",
    "nikhil": "13woSPXMw0cP0EzhiGL6EnQzsZQTicRju0BR09kdt-HM",
    "sonam":  "11h8Sqpzc-5lPu8ec2ljfGvG_E16-3IaBmRS1G5mfDI4",
}

def load_env(path):
    out = {}
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line: continue
            k, _, v = line.partition("=")
            out[k.strip()] = v.strip().strip('"').strip("'")
    return out

env = load_env(ENV)

def get_access_token():
    body = urllib.parse.urlencode({
        "client_id":     env["GOOGLE_DRIVE_CLIENT_ID"],
        "client_secret": env["GOOGLE_DRIVE_CLIENT_SECRET"],
        "refresh_token": env["GOOGLE_DRIVE_REFRESH_TOKEN"],
        "grant_type":    "refresh_token",
    }).encode()
    req = urllib.request.Request("https://oauth2.googleapis.com/token", data=body, method="POST")
    with urllib.request.urlopen(req) as r:
        return json.loads(r.read())["access_token"]

def append_rows(token, rows):
    """rows is a list of [Timestamp, Owner, Source Sheet Id, Original Row Number, Row Data JSON, Error]."""
    if not rows: return 0
    rng = urllib.parse.quote(f"{REJECTIONS_TAB}!A1")
    url = f"https://sheets.googleapis.com/v4/spreadsheets/{REJECTIONS_DOC}/values/{rng}:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS"
    payload = {"values": rows}
    req = urllib.request.Request(
        url, data=json.dumps(payload).encode(), method="POST",
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req) as r:
        json.loads(r.read())  # raises if non-2xx
    return len(rows)

def parse_body_for_error(body):
    if not body: return ""
    try:
        j = json.loads(body)
        if isinstance(j, dict):
            if "errors" in j:
                return "; ".join(f"{k}: {','.join(v) if isinstance(v,list) else v}" for k,v in j["errors"].items())
            if "message" in j:
                return j["message"]
        return str(j)[:300]
    except Exception:
        return body[:300]

def main():
    if not os.path.exists(RESULTS_CSV):
        print(f"no CSV at {RESULTS_CSV} yet")
        return
    rows_to_append = []
    timestamp = time.strftime("%Y-%m-%dT%H:%M:%S%z") or time.strftime("%Y-%m-%dT%H:%M:%S")
    with open(RESULTS_CSV) as f:
        rdr = csv.DictReader(f)
        for r in rdr:
            status = r["status"]
            if status in ("201","409"):  # success or silent dupe — not a rejection
                continue
            sheet = r["sheet"]
            err_msg = r["phone_or_msg"] if status == "skip" else parse_body_for_error(r["body"])
            row_data_json = json.dumps({
                "phone":   r.get("phone_or_msg") if status != "skip" else "",
                "course":  r.get("course"),
                "status":  status,
            })
            rows_to_append.append([
                timestamp,
                SHEET_OWNER.get(sheet, sheet),
                SHEET_DOC.get(sheet, ""),
                r["sheet_row"],
                row_data_json,
                f"backfill-2026-04-25 status={status}: {err_msg}",
            ])
    print(f"will append {len(rows_to_append)} rejection rows to '{REJECTIONS_TAB}'")
    if "--apply" not in sys.argv:
        print("(dry run; pass --apply to write)")
        for r in rows_to_append[:5]:
            print(f"  {r}")
        return
    tok = get_access_token()
    # batch in chunks of 200 to keep payloads sane
    written = 0
    for i in range(0, len(rows_to_append), 200):
        chunk = rows_to_append[i:i+200]
        written += append_rows(tok, chunk)
        time.sleep(0.5)
    print(f"appended {written} rows")

if __name__ == "__main__":
    main()
