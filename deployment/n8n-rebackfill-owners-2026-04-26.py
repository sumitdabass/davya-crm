#!/usr/bin/env python3
"""
Re-POST Nikhil + Sonam sheet rows with FULL owner names so the existing
wrongly-Sumit-owned students (created during 2026-04-25 backfill) get
MERGE'd into the correct head ownership.

Tier flow per row (LeadIntakeService):
  existing.owner = Sumit Dabas (admin)  -> tier=1
  incoming "Nikhil Saini"               -> tier=2
  -> MERGE: delete Sumit row, create Nikhil-Saini-owned, reparent payments/notes/round_history

Skips Sumit sheet entirely (already correct via admin fallback).
"""
import csv
import json
import os
import sys
import time
import urllib.request
import urllib.error

CRM = "https://davyas.ipu.co.in/api/leads"
TOKEN = "322e506f962c832c34c92c0035f54c77"
RESULTS_CSV = "/Users/Sumit/davya-crm/deployment/backups/n8n-20260425/rebackfill-results.csv"

def normalize_phone(v):
    if not v: return ""
    digits = "".join(c for c in str(v) if c.isdigit())
    if len(digits) == 12 and digits.startswith("91"):
        digits = digits[2:]
    return digits

def category_from_d_od(v):
    if not v: return None
    s = str(v).strip().lower()
    if s.startswith("d") or "delhi" in s:
        return "Delhi"
    if "od" == s or "outsid" in s or s.startswith("o "):
        return "Outside"
    return None

def map_nikhil(row):
    if len(row) < 11: return None
    state_raw = (row[9] if len(row) > 9 else "").strip()
    return {
        "phone":         normalize_phone(row[10]),
        "course":        (row[4] if len(row) > 4 else "").strip()[:80],
        "name":          (row[2] if len(row) > 2 else "").strip()[:120],
        "father_name":   (row[3] if len(row) > 3 else "").strip()[:120] or None,
        "twelfth_marks": (row[7] if len(row) > 7 else "").strip()[:20] or None,
        "rank":          (row[8] if len(row) > 8 else "").strip()[:40] or None,
        "state":         state_raw[:40] or None,
        "category":      category_from_d_od(state_raw),
        "college":       (row[5] if len(row) > 5 else "").strip()[:120] or None,
        "remarks":       (row[13] if len(row) > 13 else "").strip()[:2000] or None,
        "source":        "Nikhil Sheet",
        "owner_name":    "Nikhil Saini",
    }

def map_sonam(row):
    if len(row) < 3: return None
    d_od = (row[4] if len(row) > 4 else "").strip()
    return {
        "phone":         normalize_phone(row[1]),
        "course":        (row[2] if len(row) > 2 else "").strip()[:80],
        "rank":          (row[3] if len(row) > 3 else "").strip()[:40] or None,
        "category":      category_from_d_od(d_od),
        "remarks":       (row[5] if len(row) > 5 else "").strip()[:2000] or None,
        "referrer_name": (row[6] if len(row) > 6 else "").strip()[:60] or None,
        "source":        "Sonam Sheet",
        "owner_name":    "Sonam Sumit",
    }

# Push order: Nikhil (tier 2) first, then Sonam (tier 3) — Sonam wins any cross-sheet dupes
SHEETS = [
    ("nikhil", "/tmp/sheet_nikhil.json", map_nikhil),
    ("sonam",  "/tmp/sheet_sonam.json",  map_sonam),
]

def post_once(payload):
    req = urllib.request.Request(
        CRM, data=json.dumps(payload).encode(), method="POST",
        headers={"X-Lead-Token": TOKEN, "Content-Type": "application/json", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()
    except Exception as e:
        return 0, str(e)

def post(payload):
    code, body = post_once(payload)
    if code == 429:
        time.sleep(65)
        code, body = post_once(payload)
    return code, body

def main():
    dry = "--apply" not in sys.argv
    print(f"Mode: {'DRY-RUN' if dry else 'APPLY'}\n")
    totals = {"201":0, "409":0, "422":0, "other":0, "skipped_invalid":0}
    rows_out = []

    for label, path, mapper in SHEETS:
        d = json.load(open(path))
        rows = d.get("values", [])[1:]
        print(f"--- {label}: {len(rows)} data rows ---")
        per = {"201":0, "409":0, "422":0, "other":0, "skipped_invalid":0}
        for i, r in enumerate(rows, start=2):
            payload = mapper(r) or {}
            if not payload.get("phone") or len(payload["phone"]) != 10 or not payload.get("course"):
                per["skipped_invalid"] += 1
                rows_out.append([label, i, "skip", "missing phone/course", payload.get("phone",""), payload.get("course",""), ""])
                continue
            if dry: continue
            code, body = post(payload)
            key = str(code) if code in (201,409,422) else "other"
            per[key] += 1
            rid = ""
            try:
                j = json.loads(body)
                rid = j.get("id") or j.get("existing_id") or ""
            except: pass
            rows_out.append([label, i, code, payload["phone"], payload["course"][:30], rid, body[:200]])
            if (len(rows_out) % 50) == 0:
                os.makedirs(os.path.dirname(RESULTS_CSV), exist_ok=True)
                with open(RESULTS_CSV, "w", newline="") as f:
                    w = csv.writer(f)
                    w.writerow(["sheet","sheet_row","status","phone_or_msg","course","crm_id","body"])
                    w.writerows(rows_out)
            if (i % 25) == 0:
                print(f"  ...{i}/{len(rows)+1}  201={per['201']} 409={per['409']} 422={per['422']} other={per['other']} skip={per['skipped_invalid']}", flush=True)
            time.sleep(1.1)
        print(f"  TOTAL {label}: {per}", flush=True)
        for k in totals: totals[k] += per[k]

    print(f"\n=== GRAND TOTAL ===")
    print(json.dumps(totals, indent=2))

    if not dry:
        os.makedirs(os.path.dirname(RESULTS_CSV), exist_ok=True)
        with open(RESULTS_CSV, "w", newline="") as f:
            w = csv.writer(f)
            w.writerow(["sheet","sheet_row","status","phone_or_msg","course","crm_id","body"])
            w.writerows(rows_out)
        print(f"results -> {RESULTS_CSV}")

if __name__ == "__main__":
    main()
