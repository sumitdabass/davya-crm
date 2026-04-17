# Lead Capture API

Single endpoint for creating a Student from an external source (Google Form → n8n → here).

**URL:** `POST https://davyas.ipu.co.in/api/leads`

**Auth:** custom header `X-Lead-Token: <32-char hex>`. The token lives in the server's `.env` as `LEAD_CAPTURE_TOKEN`. Rotate by editing `.env`, running `php artisan config:cache`, and updating the n8n credential.

## Request

```http
POST /api/leads HTTP/1.1
Host: davyas.ipu.co.in
Content-Type: application/json
X-Lead-Token: <token>

{
  "phone": "9999911111",
  "name": "Ankit Sharma",
  "father_name": "Mr Sharma",
  "phone_2": null,
  "exam_appeared": "IPU CET",
  "twelfth_marks": "85%",
  "category": "Delhi",
  "course": "BCA",
  "referrer_name": "Nisha",
  "description": "Walked in via Google Form"
}
```

### Field rules

| Field | Required | Type | Constraint |
|---|---|---|---|
| `phone` | ✓ | string | Normalized to 10 digits server-side. Accepts `+91 99999 11111` etc.; country code `91` prefix is stripped. |
| `name` | ✓ | string | Max 120 chars |
| `father_name` |  | string | Max 120 |
| `phone_2` |  | string | Same normalization as `phone` |
| `exam_appeared` |  | enum | `IPU CET` \| `CUET` \| `JEE` \| `Other` |
| `twelfth_marks` |  | string | Max 20 (freeform: %, CGPA, marks) |
| `category` |  | enum | `Delhi` \| `Outside` |
| `course` |  | string | Max 80 |
| `referrer_name` | ✓ | string | Max 60. See "Referrer dropdown" below |
| `description` |  | string | Max 2000 |

### Referrer dropdown (8 options)

| Label | Result |
|---|---|
| `Sumit` | referrer=Sumit, owner=Sumit (head) |
| `Sonam` | referrer=Sonam, owner=Sonam (head) |
| `Nikhil` | referrer=Nikhil, owner=Nikhil (head) |
| `Nisha` | referrer=Nisha, owner=Nikhil (her head) |
| `Poonam` | referrer=Poonam, owner=Sonam (her head) |
| `Neetu` | referrer=Neetu, owner=Sonam (her head) |
| `Kapil` | referrer=Kapil, owner=Sumit (his head) |
| `Walk-in / Self` | referrer=null, owner=Sumit (admin default) |

Match is case-insensitive. Any unrecognized name falls through to `referrer=null, owner=Sumit` (no request fails for a typo in the dropdown).

## Responses

### 201 Created

```json
{
  "id": 42,
  "stage": "Lead Captured",
  "owner": "Nikhil",
  "referrer": "Nisha"
}
```

### 401 Unauthorized

Missing or invalid `X-Lead-Token`.

```json
{ "error": "unauthorized" }
```

### 422 Unprocessable Entity

Validation failed. Body follows the Laravel format:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "phone": ["The phone field format is invalid."]
  }
}
```

### 409 Conflict

Phone number already exists on another Student. Use `existing_id` to reconcile in your upstream system (e.g., n8n Slack-on-409 node).

```json
{
  "error": "duplicate_phone",
  "existing_id": 12
}
```

## Rate limit

`60 requests/minute` per client IP. More than enough for Davya's lead volume; exists to cap runaway loops if the token leaks.

## Sample curl

```sh
curl -X POST https://davyas.ipu.co.in/api/leads \
  -H 'Content-Type: application/json' \
  -H "X-Lead-Token: $LEAD_CAPTURE_TOKEN" \
  -d '{
    "phone": "9999911111",
    "name": "Ankit Sharma",
    "referrer_name": "Nisha",
    "category": "Delhi",
    "course": "BCA"
  }'
```

## n8n wiring notes (for when you set up the workflow)

1. **Credential:** create a n8n credential of type "Header Auth" or inline it on the HTTP Request node.
   - Name: `Davya Lead Capture Token`
   - Header name: `X-Lead-Token`
   - Header value: the `LEAD_CAPTURE_TOKEN` from server `.env`.
2. **Trigger:** Google Sheets Trigger on the Form's linked response sheet, mode "Row Added", poll 1 min.
3. **Map columns → JSON fields** (Set node or Function node). Form question order dictates the column mapping. Keep the JSON keys exactly matching the field names in "Field rules" above.
4. **HTTP Request node:**
   - Method: `POST`
   - URL: `https://davyas.ipu.co.in/api/leads`
   - Authentication: the credential from step 1
   - Body content type: JSON
   - Body: the mapped object
   - Options → Response → ignore HTTP status code errors: **off** (so 4xx/5xx bubble up to the error branch).
5. **Error branch:** Slack or email node pointed at Sumit. Include the response body so 409 conflicts and 422 validation failures are visible.
6. **Test:** submit a test form response → wait ≤60 s → confirm row appears in `/admin/students`.

## Changing the set of referrer labels

Dropdown strings match User `name` column case-insensitively. To add a new referrer:

1. Create the user via `/admin/users` (Laravel seeder plus any additional Filament-created users).
2. Add the new name to the Google Form's dropdown options with the exact `User.name` string.
3. No CRM code change needed — the controller looks up by name at request time.

To retire a referrer, remove them from the Form dropdown only. Historical students still reference their `referrer_id`; the User record should stay.
