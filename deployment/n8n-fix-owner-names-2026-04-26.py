#!/usr/bin/env python3
"""
Fix Map columns owner_name in Nikhil + Sonam workflows on shared n8n.

Why: the prior config used short names ("Nikhil"/"Sonam"), but
LeadIntakeService::findUserByName() does an exact LOWER(name) match against
the User table. Real User records are "Nikhil Saini" / "Sonam Sumit", so
short names fell through to adminId() and every row landed Sumit-owned.

LeadPriority::tier() uses substring match, so the tier logic still worked —
this fix only corrects the user-record lookup so future organic rows land
on the right head.
"""
import json
import os
import sys
import urllib.request
import urllib.error

ENV = "/Users/Sumit/kyne/deployment/.env"

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
API_KEY = env["N8N_API_KEY"]
BASE = env["N8N_BASE_URL"].rstrip("/")

WORKFLOWS = {
    "v3b8K2UC08QY4V3H": "Nikhil Saini",   # nikhil
    "P1e55kFMiE7AYlmN": "Sonam Sumit",    # sonam
}

SETTINGS_ALLOWED = {
    "executionOrder", "saveExecutionProgress", "saveManualExecutions",
    "saveDataErrorExecution", "saveDataSuccessExecution",
    "executionTimeout", "errorWorkflow", "timezone", "callerPolicy",
}

def http(method, path, body=None):
    url = f"{BASE}{path}"
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={
        "X-N8N-API-KEY": API_KEY,
        "Content-Type": "application/json",
        "Accept": "application/json",
    })
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()

def patch_owner(node, full_name):
    """Update Map columns node's owner_name assignment to the full user name."""
    p = node["parameters"]
    vals = p.get("values", {}).get("string", [])
    for v in vals:
        if v.get("name") == "owner_name":
            v["value"] = full_name
            return True
    # not found — append
    vals.append({"name": "owner_name", "value": full_name})
    p.setdefault("values", {})["string"] = vals
    return True

def update_workflow(wf):
    settings = {k: v for k, v in (wf.get("settings") or {}).items() if k in SETTINGS_ALLOWED}
    payload = {
        "name":        wf["name"],
        "nodes":       wf["nodes"],
        "connections": wf["connections"],
        "settings":    settings,
    }
    if wf.get("staticData"):
        payload["staticData"] = wf["staticData"]
    return http("PUT", f"/api/v1/workflows/{wf['id']}", payload)

def main():
    apply = "--apply" in sys.argv
    print(f"Mode: {'APPLY' if apply else 'DRY-RUN'}\n")
    for wid, full_name in WORKFLOWS.items():
        code, wf = http("GET", f"/api/v1/workflows/{wid}")
        if code != 200:
            print(f"GET {wid} failed: {code}")
            continue
        for n in wf["nodes"]:
            if n["name"] == "Map columns":
                patch_owner(n, full_name)
                print(f"  {wid}: set owner_name = {full_name!r}")
        if not apply:
            continue
        code, resp = update_workflow(wf)
        print(f"  PUT {code}")
        if code >= 400:
            print(f"  body: {str(resp)[:300]}")

if __name__ == "__main__":
    main()
