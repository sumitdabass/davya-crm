# Bulk backfill summary — 2026-04-25

## Final tallies (from /tmp/backfill.log)
```
  TOTAL sumit: {'201': 91, '409': 726, '422': 0, 'other': 1, 'skipped_invalid': 22}
  TOTAL nikhil: {'201': 487, '409': 0, '422': 0, 'other': 2, 'skipped_invalid': 63}
  TOTAL sonam: {'201': 212, '409': 0, '422': 0, 'other': 0, 'skipped_invalid': 4}
=== GRAND TOTAL ===
```

## Rejections sheet append
```
will append 92 rejection rows to 'Rejections'
appended 92 rows
```

## Per-status counts (from CSV)
```
   3 0
 790 201
 726 409
  89 skip
```

## What to do next
- Review the [Rejections sheet](https://docs.google.com/spreadsheets/d/10tjTmA39Lmdq3kJhWI_MZCOZmswRcSz9zpjlgEwQcHs) for new entries with Error starting `backfill-2026-04-25`.
- Delete smoke-test student #641 (phone 9999000099) from prod CRM.
- Verify CRM dashboard student count increased by ~ (sum of 201 across all 3 sheets).
