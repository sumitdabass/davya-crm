#!/usr/bin/env python3
"""
Patch the 3 active lead-capture workflows on shared n8n.

Fixes (per morning audit 2026-04-25):
  1. POST /api/leads HTTP node: empty bodyParameters -> JSON body, drop neverError
  2. Append to Rejected tab: empty `value` map -> 4 column bindings

Snapshots saved at deployment/backups/n8n-20260425/ before run.
Re-runnable: idempotent (sets fields to known-good values).
"""
import json
import os
import sys
import urllib.request
import urllib.error

ENV_PATH = "/Users/Sumit/kyne/deployment/.env"

def load_env(path):
    out = {}
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, _, v = line.partition("=")
            out[k.strip()] = v.strip().strip('"').strip("'")
    return out

env = load_env(ENV_PATH)
API_KEY = env["N8N_API_KEY"]
BASE = env["N8N_BASE_URL"].rstrip("/")

WORKFLOWS = {
    "7cqS00mq6r2yGJDG": ("sumit",  "1vPqJBM8h_QQ-LhDsfCY76sbJJr0AffnldUMFmZ9s98w"),
    "v3b8K2UC08QY4V3H": ("nikhil", "13woSPXMw0cP0EzhiGL6EnQzsZQTicRju0BR09kdt-HM"),
    "P1e55kFMiE7AYlmN": ("sonam",  "11h8Sqpzc-5lPu8ec2ljfGvG_E16-3IaBmRS1G5mfDI4"),
}
REJECTIONS_DOC = "10tjTmA39Lmdq3kJhWI_MZCOZmswRcSz9zpjlgEwQcHs"

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
        body = e.read().decode()
        return e.code, body

def patch_http_node(node):
    p = node["parameters"]
    p.pop("bodyParameters", None)
    p["specifyBody"] = "json"
    p["jsonBody"] = "={{ JSON.stringify($json) }}"
    headers = p.setdefault("headerParameters", {}).setdefault("parameters", [])
    names = {h.get("name") for h in headers}
    if "Content-Type" not in names:
        headers.append({"name": "Content-Type", "value": "application/json"})
    options = p.setdefault("options", {}).setdefault("response", {}).setdefault("response", {})
    options["fullResponse"] = True
    options["neverError"] = False  # surface 4xx/5xx as failures in n8n UI
    return node

def patch_append_node(node, source_sheet_id):
    p = node["parameters"]
    cols = p.setdefault("columns", {})
    cols["mappingMode"] = "defineBelow"
    cols["matchingColumns"] = []
    cols["schema"] = []
    cols["attemptToConvertTypes"] = False
    cols["convertFieldsToString"] = False
    cols["value"] = {
        "Timestamp":           "={{ $now.toISO() }}",
        "Owner":               "={{ $json.owner_name }}",
        "Source Sheet Id":     source_sheet_id,
        "Original Row Number": "={{ $('Google Sheets Trigger').item.json.row_number }}",
        "Row Data JSON":       "={{ JSON.stringify($('Google Sheets Trigger').item.json) }}",
        "Error":               "={{ ($json.phone ? '' : 'phone empty; ') + ($json.course ? '' : 'course empty') }}",
    }
    return node

SETTINGS_ALLOWED = {
    "executionOrder", "saveExecutionProgress", "saveManualExecutions",
    "saveDataErrorExecution", "saveDataSuccessExecution",
    "executionTimeout", "errorWorkflow", "timezone", "callerPolicy",
}

def update_workflow(wf):
    # Public API only accepts: name, nodes, connections, settings, staticData
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
    dry = "--apply" not in sys.argv
    print(f"Mode: {'DRY-RUN' if dry else 'APPLY'}\n")
    for wid, (label, source_id) in WORKFLOWS.items():
        print(f"--- {label} ({wid}) ---")
        code, wf = http("GET", f"/api/v1/workflows/{wid}")
        if code != 200:
            print(f"  GET failed: {code} {wf}")
            continue
        for n in wf["nodes"]:
            if n["name"] == "POST /api/leads":
                patch_http_node(n)
                print("  patched HTTP node")
            elif n["name"] == "Append to Rejected tab":
                patch_append_node(n, source_id)
                print("  patched Append node")
        if dry:
            preview = next(n for n in wf["nodes"] if n["name"] == "POST /api/leads")
            print(f"  preview specifyBody={preview['parameters'].get('specifyBody')}  neverError={preview['parameters']['options']['response']['response']['neverError']}")
            continue
        code, resp = update_workflow(wf)
        print(f"  PUT {code}")
        if code >= 400:
            print(f"  body: {str(resp)[:400]}")
    print("\nDone.")

if __name__ == "__main__":
    main()
