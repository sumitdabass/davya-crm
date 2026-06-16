# Rank Tab Redesign — IPU + DTU (JAC Delhi), dataset-scoped roles

**Date:** 2026-06-16
**Status:** Design — awaiting approval
**Branch:** `feat/rank-tab-redesign`

## 1. Goal

Redesign the existing davya-crm **Rank** module so that:

1. The Rank tab has **two datasets**: **IPU** (existing, multi-course) and **DTU** (new — JAC Delhi: DTU + NSUT + IGDTUW, B.Tech only).
2. Team members get **per-dataset, per-capability** access: some see IPU only, some DTU only, some both; and within each, either **Predict** (use the predictor) or **Analyse** (view/edit data + trends).
3. The **DTU predictor** is the gold standard (gender/category/sub-category/region dropdowns, SAFE→UNLIKELY chance scale, NSUT campus, women-only + girl-quota logic, print/PDF). The **IPU predictor** is upgraded to the same quality, keeping IPU's multi-course selector.
4. Prediction **output quality** is improved by adding the reservation **category**, **sub-category**, and **gender** dimensions (the current module only models region).

This is an **extension of the existing Rank module**, not a rebuild. The schema is already multi-university capable.

## 2. Existing state (summary)

- `ranks` DB connection. Tables: `universities → institutes`, `universities → courses → branches`, `cutoffs` (links university/course/exam/process/institute/branch + year/round/shift/region + min_rank/max_rank), `seats`, `qualifying_exams`, `admission_processes`.
- Predictor: `app/Services/Rank/StudentChoicePredictor.php` (hardcoded IPU/B.Tech/JEE_MAIN), `app/Services/Rank/RankPredictor.php` (cushion/bucket/eligibility), `app/Services/Rank/GeminiCounsellor.php` (IPU-hardcoded prompt).
- UI: `app/Filament/Pages/RankLanding.php` (nav group "Rank Predictor"), `app/Filament/Pages/Rank/RankLookup.php` + blade. CRUD resources in `app/Filament/Resources/Rank/`. Card metadata in `app/Rank/RankRegistry.php`.
- Roles: Spatie. `rank-admin` (perms `rank.view`, `rank.manage`); access gated via `RankRegistry::canAccess()` and the `RestrictsToRankRoles` trait (both check `['admin','rank-admin']`).
- Import: `rank:import-from-predictor` (from a standalone SQLite), bulk-paste pages for cutoffs/seats.
- Students: `students.rank` (string), `students.category` enum('Delhi','Outside') — **used as region**, `rank_prob_first_choice` (observer-computed via `StudentRankProbabilityObserver`).

## 3. Access model

Four composable Spatie roles (assignable in any combination per user via the Filament User screen):

| | Predict | Analyse |
|---|---|---|
| **IPU** | `rank-ipu-predict` | `rank-ipu-analyse` |
| **DTU** | `rank-dtu-predict` | `rank-dtu-analyse` |

Backed by permissions: `rank.ipu.predict`, `rank.ipu.analyse`, `rank.dtu.predict`, `rank.dtu.analyse`.

- **Predict** → access that dataset's predictor page only.
- **Analyse** → access that dataset's CRUD resources (Universities/Institutes/Courses/Branches/Cutoffs/Seats), trend analytics, and imports.
- `admin` / `super_admin` → all of the above (back-compat: legacy `rank-admin` maps to all four).
- Helpers on `User`: `canRankPredict('ipu'|'dtu')`, `canRankAnalyse('ipu'|'dtu')`, `rankDatasets()` (returns the datasets visible to the user).

**Dataset → University mapping:** each dataset maps to one or more `universities.code`:
- `ipu` → `['IPU']`
- `dtu` → `['JAC']` (the JAC Delhi university; see §5)

All Filament Rank resources are **query-scoped** to the universities the current user may analyse, so a DTU-analyse user never sees IPU rows and vice-versa.

## 4. Rank landing redesign

`RankLanding` renders dataset sections conditionally on roles:

```
RANK  (nav group)
├─ IPU         [if user has any rank-ipu-* role]
│   ├─ Predict   → /admin/rank/ipu/predict      (rank-ipu-predict)
│   └─ Analyse   → resources + /admin/rank/ipu/trends (rank-ipu-analyse)
└─ DTU         [if user has any rank-dtu-* role]
    ├─ Predict   → /admin/rank/dtu/predict      (rank-dtu-predict)
    └─ Analyse   → resources + /admin/rank/dtu/trends (rank-dtu-analyse)
```

`RankRegistry` gains a `cardsFor(User)` method that returns only the cards the user's roles permit. Empty datasets are hidden entirely.

## 5. Data model changes

### 5.1 `cutoffs` — add category dimensions
Add nullable columns (IPU's existing region-only rows remain valid until category data is loaded):
- `category` — string(16), nullable. Vocabulary: `general`, `ews`, `obc`, `sc`, `st` (extensible for IPU: `defence`, etc.).
- `sub_category` — string(24), nullable. Vocabulary: `gender_neutral`, `girl`, `single_girl`, `pwd`, `defense_cw`, `kashmiri_migrant`.

Update the `cutoffs_unique` composite index to include `category` and `sub_category`.

**Gender is NOT a cutoff column** — it is derived from `sub_category` (girl/single_girl = female-only) and used as a predictor input filter (males cannot select girl-quota seats or women-only institutes).

### 5.2 `branches` — campus handled via institutes (no change)
NSUT's three campuses are modelled as **three institutes** (NSUT Main (Dwarka), NSUT East Campus, NSUT West Campus). No new column needed; the predictor's institute column shows the campus naturally.

### 5.3 `students` — add demographic fields (for accurate auto-probability)
- `gender` — enum('male','female','other'), nullable.
- `reservation_category` — string(16), nullable (`general`/`ews`/`obc`/`sc`/`st`/…).
- Existing `category` enum('Delhi','Outside') is **left untouched** (it is region); the observer keeps using it as region.

### 5.4 JAC Delhi seed (dataset `dtu`)
- University: `name="JAC Delhi"`, `code="JAC"` (landing card labelled "DTU").
- Course: `B.Tech` (single).
- Institutes: `DTU`, `NSUT Main (Dwarka)`, `NSUT East Campus`, `NSUT West Campus`, `IGDTUW`.
- Qualifying exam: reuse `JEE_MAIN`. Admission process: new `JAC`.
- Branches + cutoffs imported from the parsed dataset (§7).

## 6. Predictor engine changes

### 6.1 De-hardcode `StudentChoicePredictor`
Accept a context object `{university_code, course, exam, process, category, sub_category, gender, region, rank}` instead of hardcoding IPU/B.Tech/JEE_MAIN. Filter cutoffs by category + sub_category when present.

### 6.2 Fix eligibility / chance (output-quality fix)
Current `RankPredictor::isEligible` excludes ranks **better than** the opening rank (`rank < min` → false, line 39) and cushion >50% (line 42), which **hides the safest options**. Replace the bucket/eligibility with the validated chance scale keyed on the closing rank (`max_rank`):

| Label | Condition (rank vs closing CR) |
|---|---|
| SAFE | rank ≤ CR × 0.85 |
| LIKELY | rank ≤ CR |
| BORDERLINE | rank ≤ CR × 1.08 |
| STRETCH | rank ≤ CR × 1.25 |
| UNLIKELY | rank > CR × 1.25 |

`min_rank` becomes informational only. JAC cutoffs (closing-rank only in source) load with `min_rank = 0`, `max_rank = closing rank`. A **"within reach only"** toggle hides UNLIKELY (default on). For datasets/rounds with both opening+closing (IPU bands), the band still displays; the chance is computed off the closing rank.

### 6.2a Benchmark-round selection (dataset- and category-aware) — CRITICAL

The "closing rank" the chance scale is measured against is **not always the final round**. The benchmark round is chosen per dataset and category:

- **IPU:**
  - **General** category → use the **Sliding**-round cutoff as the benchmark.
  - **Reserved** categories (EWS / OBC / SC / ST / …, i.e. any non-General) → use the **Round-3** cutoff as the benchmark.
  - (This supersedes the current code's region-keyed choice in `StudentChoicePredictor` — the real driver is category, not region. IPU displays R1/R2/R3 + Sliding; the benchmark is picked from these.)
- **DTU (JAC):** use the **final available round** (R5, or the highest round present for that branch×category×region cell) as the benchmark, for all categories.

The benchmark round is resolved in the engine via a per-dataset strategy (see `BenchmarkRoundStrategy` in §10). The display table still shows all rounds; only the **chance computation** uses the selected benchmark. If the selected benchmark round is missing for a cell, fall back to the nearest earlier round and flag it.

### 6.3 Gender / quota logic (ported from web tool)
- Male input → girl/single_girl sub-categories and women-only institutes (IGDTUW) are excluded from options.
- Female input → all applicable sub-categories available; can compare open vs girl-quota.
- Women-only institutes flagged in output.

### 6.4 `GeminiCounsellor`
Parameterize the system prompt with `{university_label, course_label}` so notes read correctly for both IPU and DTU. Cache key gains dataset + category + sub_category + gender.

## 7. Data import

- **DTU/JAC:** a CSV importer (`rank:import-jac --file=…` artisan command + an Analyse-side upload screen) that ingests the parsed `jacdelhi_orcr_cutoffs.csv` schema (institute, branch, category, sub_category, region, round, closing_rank) into `cutoffs`, creating institutes/branches as needed and mapping NSUT campuses to the three institutes. B.Arch and IIITD excluded (per the validated tool); IIITD R3/R4 absent (no JEE rank in source).
- The **Python parser** (`/Users/Sumit/jacdelhi_orcr_2025/parse_orcr.py`) remains the offline yearly tool that produces the CSV from JAC PDFs.
- **IPU category data:** when the user supplies category-wise IPU cutoffs, they load via the existing bulk-paste (extended with category/sub_category fields) or the same CSV importer pointed at IPU.

## 8. Predictor UI (both datasets)

A shared Livewire predictor component, configured per dataset:

- **Inputs:** Rank, Gender, Category, Sub-category, Region — dependent dropdowns (gender → category → sub-category → region) so invalid combos never appear. **IPU also shows a Course selector** (B.Tech + others); DTU fixes course to B.Tech and hides the selector.
- **Output table:** Chance chip (SAFE…UNLIKELY), Institute (= campus for NSUT), Branch, Final-round CR, R1 CR, Seats (if present). Women-only institutes flagged.
- **Controls:** "within reach only" toggle; **Print / Save-as-PDF** button (Filament page with a print stylesheet). AI counsellor notes (Analyse-grade) optional toggle.

## 9. Analyse views

Per dataset (gated by `rank-*-analyse`):
- The existing CRUD resources, query-scoped to that dataset's university/universities.
- A **Trends** page: closing-rank movement across rounds (R1→final) and across years, per institute/branch/category — table + simple chart. Export to CSV.

## 10. Components & boundaries

| Unit | Responsibility | Depends on |
|---|---|---|
| `RankAccess` (new) | Resolve a user's dataset/capability permissions | User, Spatie roles |
| `RankRegistry` (extend) | Build role-filtered landing cards | RankAccess |
| `PredictorContext` (new DTO) | Carry dataset+filters into the engine | — |
| `BenchmarkRoundStrategy` (new) | Pick benchmark round per dataset+category (IPU: general→sliding, reserved→R3; DTU→final round) | — |
| `StudentChoicePredictor` (refactor) | Dataset-agnostic prediction | PredictorContext, BenchmarkRoundStrategy, Cutoff, RankPredictor |
| `RankPredictor` (refactor) | Chance scale (replaces bucket/eligibility) | — |
| `RankPredictorPage` (new Livewire) | Shared predictor UI for both datasets | StudentChoicePredictor, RankAccess |
| `JacCutoffImporter` (new) | CSV → cutoffs for JAC | Cutoff, Institute, Branch |
| Rank Filament resources (scope) | Analyse CRUD, dataset-scoped | RankAccess |
| `RankTrends` (new page) | Trend analytics | Cutoff |

## 11. Testing

- **Unit:** chance-scale boundaries (each label), gender filtering (male excludes girl/IGDTUW), category filtering, `RankAccess` permission resolution, **`BenchmarkRoundStrategy`** (IPU general→sliding, IPU reserved→R3, DTU→final round; missing-round fallback).
- **Importer:** known JAC rows land with correct institute/campus/category/sub_category; row counts match the CSV; IIITD/B.Arch excluded.
- **Feature/Filament:** role A (ipu-predict) sees only IPU predictor; role B (dtu-analyse) sees only JAC resources + trends; role C (both) sees all; a no-rank-role user sees nothing. Resource query-scoping enforced.
- **Regression:** existing IPU prediction output unchanged where category is null (region-only path still works); `StudentRankProbabilityObserver` still computes.

## 12. Phasing (for the implementation plan)

1. Schema migrations (cutoffs category/sub_category; students gender/reservation_category) + JAC university/institutes/course/process seed + roles/permissions seeder.
2. Engine refactor: `PredictorContext`, de-hardcode `StudentChoicePredictor`, chance-scale `RankPredictor`, gender/category filtering, parameterized `GeminiCounsellor`.
3. `JacCutoffImporter` + artisan command; load the 2025 dataset.
4. Shared `RankPredictorPage` (Livewire) + DTU predictor (B.Tech only) with print/PDF.
5. IPU predictor on the shared component (course selector) + category bulk-paste extension.
6. `RankAccess`, role-filtered `RankLanding`, resource query-scoping.
7. Analyse `RankTrends` page + CSV export.

## 13. Out of scope / notes

- **HARD RULE — IPU and DTU never merge.** They are independent universities (`IPU` vs `JAC`), with independent roles, data, queries, and results. No query spans both; no list combines them; student auto-probability is IPU-only. Shared engine code (chance math, DTO, benchmark strategy) always receives the dataset token and operates on one dataset at a time — reusing a function is not merging data.

- IIITD and B.Arch remain excluded from predictions (separate exam / no JEE rank).
- IPU category-level accuracy depends on the category-wise IPU data the user will provide; until then IPU runs region-only (back-compat path).
- Naming: the menu label "DTU" represents the JAC Delhi dataset (DTU + NSUT + IGDTUW). Roles use the `dtu` token.
