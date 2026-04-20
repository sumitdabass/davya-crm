#!/usr/bin/env python3
"""
Idempotent patch: add Payment-image branch to the n8n finance workflow.

Inserts two nodes between `Route by target` main[1] and the existing `POST to CRM` node.

  Route by target --main[1]--> [Payment with image?]
    TRUE  (target==payments AND first file is image) --> [Attach proof_url] --> POST to CRM
    FALSE (anything else)                            --------------------------> POST to CRM

Refuses to re-apply if 'Payment with image?' already exists.

Notes on structure:
- 'Route by target' is an IF node, not a switch. main[1] carries every non-assistant
  target (payments + expenses + investments + failed). We MUST gate on target==payments
  inside our new IF to avoid attaching proof_url to expense/investment payloads.
- 'POST to CRM' is a single shared HTTP node; URL is built from $json.target at runtime.
"""
import json
import os
import sys

WF_PATH = os.path.join(os.path.dirname(__file__), 'n8n-finance-workflow.json')

PAYMENT_POST_NODE = 'POST to CRM'
IF_NODE_NAME      = 'Payment with image?'
ATTACH_NODE_NAME  = 'Attach proof_url'

IF_NODE = {
    'name': IF_NODE_NAME,
    'type': 'n8n-nodes-base.if',
    'typeVersion': 2.2,
    'position': [1800, 600],
    'parameters': {
        'conditions': {
            'options': {'caseSensitive': False, 'typeValidation': 'loose'},
            'combinator': 'and',
            'conditions': [
                {
                    'id': 'target-is-payments',
                    'leftValue': "={{ $json.target }}",
                    'rightValue': 'payments',
                    'operator': {'type': 'string', 'operation': 'equals'},
                },
                {
                    'id': 'first-file-is-image',
                    'leftValue': "={{ ($('Slack Trigger — new message').item.json.files || [])[0]?.mimetype || '' }}",
                    'rightValue': 'image/',
                    'operator': {'type': 'string', 'operation': 'startsWith'},
                },
            ],
        },
    },
}

ATTACH_NODE = {
    'name': ATTACH_NODE_NAME,
    'type': 'n8n-nodes-base.code',
    'typeVersion': 2,
    'position': [2000, 500],
    'parameters': {
        'jsCode': (
            "// Attach Slack file permalink as proof_url on the payment payload.\n"
            "const dispatch = $('Dispatch by category').item.json;\n"
            "const files = $('Slack Trigger — new message').item.json.files || [];\n"
            "const firstImage = files.find(f => (f.mimetype || '').startsWith('image/'));\n"
            "if (firstImage) {\n"
            "  dispatch.payload = dispatch.payload || {};\n"
            "  dispatch.payload.proof_url = firstImage.permalink;\n"
            "}\n"
            "return { json: dispatch };\n"
        ),
    },
}


def main() -> int:
    with open(WF_PATH) as f:
        wf = json.load(f)

    names = {n['name'] for n in wf['nodes']}
    if IF_NODE_NAME in names:
        print(f"noop: '{IF_NODE_NAME}' already present")
        return 0
    if PAYMENT_POST_NODE not in names:
        print(
            f"ERROR: target node '{PAYMENT_POST_NODE}' not found in workflow. "
            "Confirm name and update PAYMENT_POST_NODE.",
            file=sys.stderr,
        )
        return 2

    router_conns = wf['connections']['Route by target']['main']
    if len(router_conns) < 2:
        print(
            f"ERROR: Route by target has fewer than 2 main outputs: {router_conns}",
            file=sys.stderr,
        )
        return 3
    main1 = router_conns[1]
    if not main1 or main1[0]['node'] != PAYMENT_POST_NODE:
        print(
            f"ERROR: Route by target main[1] does not go to '{PAYMENT_POST_NODE}'. "
            f"Got: {main1}",
            file=sys.stderr,
        )
        return 4

    wf['nodes'].append(IF_NODE)
    wf['nodes'].append(ATTACH_NODE)

    router_conns[1] = [{'node': IF_NODE_NAME, 'type': 'main', 'index': 0}]

    wf['connections'][IF_NODE_NAME] = {
        'main': [
            [{'node': ATTACH_NODE_NAME, 'type': 'main', 'index': 0}],
            [{'node': PAYMENT_POST_NODE, 'type': 'main', 'index': 0}],
        ],
    }
    wf['connections'][ATTACH_NODE_NAME] = {
        'main': [[{'node': PAYMENT_POST_NODE, 'type': 'main', 'index': 0}]],
    }

    with open(WF_PATH, 'w') as f:
        json.dump(wf, f, indent=2)
    print(
        f"patched: added {IF_NODE_NAME} and {ATTACH_NODE_NAME}; "
        f"rewired Route by target main[1] -> {IF_NODE_NAME}"
    )
    return 0


if __name__ == '__main__':
    sys.exit(main())
