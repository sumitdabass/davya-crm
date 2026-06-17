# JAC Delhi cutoff import runbook

Imports JAC Delhi (DTU / NSUT / IGDTUW) category-cutoff PDFs into the `ranks`
DB so the DTU Rank Predictor reflects the newest round.

Source: **https://jacdelhi.admissions.nic.in/notices/** (PDFs hosted on
`cdnbbsr.s3waas.gov.in`). Each institute publishes a separate "Round-N Cut Off"
PDF per year.

## Status (2026)
- DTU R1 2026 — imported (433 rows).
- NSUT R1 2026 — imported (413 rows).
- IGDTUW R1 2026 — **NOT YET PUBLISHED** as of 2026-06-17. Import when it appears.
- IIITD — intentionally excluded (Sumit). DSEU — not wanted.

## PDF format (DTU / NSUT)
Compact matrix: rows = branches, columns = 5-char codes `[cat2][sub2][region1]`:
- cat: `GN`=general `EW`=ews `OB`=obc `SC`=sc `ST`=st
- sub: `GN`=gender_neutral `GL`=girl `SG`=single_girl `PD`=pwd `CW`=defense_cw
- region: `D`=delhi `O`=outside_delhi (page 1 Delhi, page 2 Outside)
Blank cell = no admission. CW values carry `(VI)` priority annotations (ignored).
NSUT encodes campus in branch-code asterisks (none=Main, `*`=East, `**`=West).
KM (Kashmiri Migrant) column is skipped — 2025 data had none.

## Procedure
```sh
cd scripts/rank/jac
# 1. download the institute's Round-N PDF from the notices page (find URL there)
curl -sL -o igdtuw.pdf "<PDF_URL>"
# 2. extract text/coords
pdftotext -bbox-layout igdtuw.pdf igdtuw.xml      # needs poppler (pdftotext)
# 3. parse -> long CSV. extract_dtu.py / extract_nsut.py are templates;
#    write extract_igdtuw.py modelled on them (uses parse_matrix.py core).
#    IGDTUW is women-only, single campus -> institute name "IGDTUW".
python3 extract_igdtuw.py     # -> emits rows: institute,branch,round,region,category,sub_category,closing_rank
#    sub_category MUST be the importer's hyphenated input vocab:
#    gender-neutral / girl / single-girl / pwd / defense-cw
# 4. VERIFY before importing: spot-check several cells against the PDF, confirm
#    row count is sane (>0, ranks plausible), branches look right. If unsure, STOP.
# 5. append to storage/app/rank/jacdelhi_cutoffs_2026_r1.csv (same 7-col header)
# 6. import (idempotent updateOrCreate):
php artisan rank:import-jac --file=storage/app/rank/jacdelhi_cutoffs_2026_r1.csv --year=2026
```

## Predictor behaviour
`DatasetCutoffPredictor` uses latest-year **per institute** for the JAC/DTU
dataset (`RankDataset::usesPerInstituteYear`), so importing IGDTUW 2026 R1 makes
IGDTUW jump from 2025 to 2026 automatically; other institutes are unaffected.
IPU stays on a single dataset-wide year (do not change).

## Deploy
Follow `DEPLOY.md` recurring recipe on prod, then run the `rank:import-jac`
command above on prod, then verify counts via `php artisan tinker`.
