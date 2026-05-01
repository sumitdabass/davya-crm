# Rank Predictor Module — davya-crm

**Date:** 2026-05-01
**Goal:** Multi-university, multi-course, multi-year rank/cutoff/seat module inside davya-crm with its own MySQL DB. Replaces the standalone `/Users/Sumit/davya-crm/rank/` app once data is migrated.

## Spec (locked)

- Separate MySQL DB `ipuc_rank` on Hostinger (prod), `davyas_ranks_dev` locally.
- 8 entities: universities, institutes, courses, qualifying_exams, admission_processes, branches, cutoffs, seats.
- Filament resources for all 8 entities with bulk-paste import for cutoffs + seats.
- Rank Lookup page (form: university+course+exam+process+year+region+rank → table + browser print).
- Filament Shield permissions; `sumitdabass@gmail.com` is super-admin.
- Future-ready: `official_website` on universities/institutes for auto-fetch.

## Schema

```
universities         (id, name, code, official_website, country, state, timestamps)
institutes           (id, university_id, name, code, official_website, city, timestamps)
courses              (id, university_id, name, code, timestamps)
qualifying_exams     (id, name, code, timestamps)
admission_processes  (id, name, code, timestamps)
branches             (id, course_id, name, family, timestamps)
cutoffs              (id, university_id, course_id, qualifying_exam_id, admission_process_id,
                      year, round ENUM[1,2,3,sliding], institute_id, branch_id,
                      shift ENUM[I,II] NULL, region ENUM[delhi,outside_delhi],
                      min_rank, max_rank, source ENUM[official,predicted,validated],
                      created_by FK users, updated_by FK users, timestamps, soft_deletes)
seats                (id, university_id, course_id, qualifying_exam_id, year,
                      institute_id, branch_id, shift, region, seat_count,
                      source_note TEXT NULL, created_by, updated_by, timestamps)
```

Cutoff unique key: `(university_id, course_id, qualifying_exam_id, admission_process_id, year, round, institute_id, branch_id, shift, region)`.
Seat unique key: `(university_id, course_id, qualifying_exam_id, year, institute_id, branch_id, shift, region)`.

## Phases

### Phase 0 — Connection scaffold
- [ ] Add `ranks` connection to `config/database.php` (reads `RANKS_DB_*` env)
- [ ] Append local creds to `.env` (RANKS_DB_DATABASE=davyas_ranks_dev, etc.)
- [ ] Create local MySQL DB `davyas_ranks_dev`
- [ ] Verify connection via `php artisan tinker → DB::connection('ranks')->getPdo()`

### Phase 1 — Migrations + Models
- [ ] Create `database/migrations/ranks/` directory
- [ ] Migration: universities
- [ ] Migration: institutes
- [ ] Migration: courses
- [ ] Migration: qualifying_exams
- [ ] Migration: admission_processes
- [ ] Migration: branches
- [ ] Migration: cutoffs (with unique index)
- [ ] Migration: seats (with unique index)
- [ ] Models in `app/Models/Rank/` with `protected $connection = 'ranks'` and relationships
- [ ] Run migrations: `php artisan migrate --database=ranks --path=database/migrations/ranks`

### Phase 2 — Filament resources (admin CRUD)
- [ ] `app/Filament/Resources/Rank/` directory
- [ ] UniversityResource
- [ ] InstituteResource
- [ ] CourseResource
- [ ] QualifyingExamResource
- [ ] AdmissionProcessResource
- [ ] BranchResource
- [ ] CutoffResource (with shift/region/round filters)
- [ ] SeatResource
- [ ] Group all under `getNavigationGroup() = 'Rank Predictor'`
- [ ] Custom navigation icon

### Phase 3 — Bulk paste import
- [ ] Cutoff bulk-paste page: textarea + university+course+exam+process+year+round picker + region picker → preview → commit. Reuses `CutoffCellParser` "Min Rank - X Max Rank - Y" format from rank-predictor.
- [ ] Seat bulk-paste page: textarea + university+course+exam+year picker → preview → commit. Format: institute\tbranch\tseat_count or institute\tbranch\tdelhi_seats\toutside_seats (Sumit confirms format on first paste).
- [ ] Both pages: institute/branch alias resolution (port from `SeedCutoffs2024Seeder` + 2026 alias maps).

### Phase 4 — Rank Lookup page
- [ ] `app/Filament/Pages/Rank/Lookup.php`
- [ ] Form: university + course + qualifying_exam + admission_process + year + region + user_rank
- [ ] Output: table grouped by institute → branch → R1/R2/R3/Sliding columns + seat count, highlighting cells where rank fits range
- [ ] Print stylesheet: hide nav/sidebar; A4 landscape; institute/branch/cutoffs as table

### Phase 5 — Reference data seeders
- [ ] Universities: IPU
- [ ] Courses: IPU B.Tech
- [ ] Qualifying exams: JEE Main, JEE Advanced, CUET
- [ ] Admission processes: Counselling, Sliding, Open Round, Spot Round
- [ ] Run `php artisan db:seed --class=RankReferenceDataSeeder --database=ranks`

### Phase 6 — Migrate existing rank-predictor data
- [ ] Export script: read `/Users/Sumit/davya-crm/rank/database/database.sqlite` years/institutes/branches/cutoffs
- [ ] Map to new schema (set university=IPU, course=B.Tech, qualifying_exam=JEE Main, admission_process=Counselling for all, sliding rows → admission_process=Sliding)
- [ ] Insert into `ipuc_rank` (locally first)
- [ ] Verify counts match (864 + 221 = 1085 cutoff rows expected)

### Phase 7 — Permissions + super-admin
- [ ] Install `bezhansalleh/filament-shield` if not already (check composer)
- [ ] Generate Shield permissions for all 8 resources + Lookup page
- [ ] Roles: super-admin, rank-admin, rank-viewer
- [ ] Seeder: ensure user `sumitdabass@gmail.com` exists with super-admin role
- [ ] Gate the navigation: only show "Rank Predictor" group to users with `view_rank` permission

### Phase 8 — Deploy to Hostinger
- [x] DEPLOY.md updated with rank-module env block + migrate/seed steps
- [x] Lint clean (`./vendor/bin/pint --test app/Models/Rank app/Filament/Resources/Rank app/Filament/Pages/Rank app/Console/Commands/Rank app/Services/Rank database/seeders/Rank database/migrations/ranks` → pass)
- [ ] **Sumit:** add `RANKS_DB_HOST=127.0.0.1` (or actual host) + RANKS_DB_DATABASE/USERNAME/PASSWORD to `.env` on `ipuc@ipu.co.in`
- [ ] git push laptop → server pulls → run migrate + seed per DEPLOY.md "Recurring deploy"
- [ ] One-time: `scp` standalone rank-predictor sqlite to server, run `php artisan rank:import-from-predictor`
- [ ] Verify by logging in to `https://davyas.ipu.co.in/admin` as `sumitdabass@gmail.com`

### Phase 9 — Sunset standalone rank-predictor
- [ ] Confirm prod davya-crm rank module is functional
- [ ] Archive `/Users/Sumit/davya-crm/rank/` (rename to `_rank_archive` or git tag)
- [ ] Update memory: project_rank-predictor.md → describes the new davya-crm module location

## Out of scope (for now)

- Public-facing student lookup (only admin in davya-crm for now). Future: re-enable rank-predictor public app pointing at `ipuc_rank` shared DB.
- Auto-fetch from `official_website`. Field exists; scraping cron job is a follow-up.
- 2025 IPU B.Tech data ingestion. Sumit will paste; reuse Phase 3 bulk-paste UI.
- Multi-tenant: each university has its own admin team. For now, Sumit alone.

## Risks / open questions

- **Hostinger DB host** — pending from Sumit. Phase 8 blocked until shared.
- **Seat data format** — Sumit will paste; format unconfirmed. Phase 3 seat-paste UI needs adjustment after first paste.
- **Filament Shield** — may need `php artisan shield:install` on first use if not yet installed in davya-crm. Check composer.lock.
