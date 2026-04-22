# Lead Access Rule — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lock the Lead Access Rule contract described in `docs/superpowers/specs/2026-04-22-lead-access-rule-design.md`: admin sees/deletes all, heads view+edit their team, counsellors view team but edit only own/referenced, `lead_source` routes to team regardless of owner.

**Architecture:** Surgical edit of two files (`app/Models/Student.php::scopeVisibleTo`, `app/Policies/StudentPolicy.php`). No migration, no schema change, no Filament changes. TDD: tests added to existing `tests/Feature/NikhilVisibilityTest.php` (scope) and `tests/Feature/StudentPolicyTest.php` (policy).

**Tech Stack:** Laravel 11, Filament 3, Spatie Permission, PHPUnit (not Pest), PHP 8.5 local / 8.4 prod.

**Branch:** `feature/lead-access-rule` (already created, contains only the spec commit `ac55a33`).

**Local test command:** `php artisan test --filter=<name>`

**Important gotcha:** on local PHP 8.5, every test run emits `PHP Deprecated: PDO::MYSQL_ATTR_SSL_CA is deprecated` lines before the results. **These DEPR lines are harmless** — the bottom line still says "OK" or "Tests: X passed". Treat DEPR as PASS. See memory `project_davya-crm_php85_deprecations.md`.

---

## Seed fixture reference

From `database/seeders/UsersSeeder.php`:

| User | Email | Roles | Team |
|---|---|---|---|
| Sumit | `sumit@davya.local` | `admin`, `head` | — |
| Nikhil | `nikhil@davya.local` | `head` | head of Nisha |
| Sonam | `sonam@davya.local` | `head` | head of Poonam + Neetu |
| Nisha | `nisha@davya.local` | `member` | Nikhil's team |
| Poonam | `poonam@davya.local` | `member` | Sonam's team |
| Neetu | `neetu@davya.local` | `member` | Sonam's team |
| Kapil | `kapil@davya.local` | `freelancer` | `team_head_id = sumit.id`, `is_freelancer = true` |

All tests below use `$this->seed()` in `setUp()` (already present in both test files).

---

## File structure

- **Modify** `app/Models/Student.php` (lines 26–63) — `scopeVisibleTo` method
- **Modify** `app/Policies/StudentPolicy.php` (entire file) — `view()`, `update()`, `delete()`
- **Modify** `tests/Feature/NikhilVisibilityTest.php` — append two scope tests
- **Modify** `tests/Feature/StudentPolicyTest.php` — append policy tests

No new files. No migration.

---

## Task 1: Widen `lead_source` routing (scope + policy view)

**Rationale:** Today, a lead whose `lead_source` names a team only routes to that team when `owner_id = admin AND referrer_id = admin`. New contract: any lead whose `lead_source` matches the team's name set routes to that team regardless of owner.

**Files:**
- Modify: `app/Models/Student.php:26-63`
- Modify: `app/Policies/StudentPolicy.php:10-55` (the `view` method)
- Test: `tests/Feature/NikhilVisibilityTest.php` (append)
- Test: `tests/Feature/StudentPolicyTest.php` (append)

### - [ ] Step 1: Write failing scope tests in `tests/Feature/NikhilVisibilityTest.php`

Append these two methods at the end of the `NikhilVisibilityTest` class (before the closing `}`):

```php
public function test_head_sees_lead_routed_by_lead_source_even_when_non_admin_owned(): void
{
    // Kapil (freelancer, team_head_id = Sumit) owns the lead, but lead_source = 'Sheet:Nikhil'
    // — meaning the lead came in via Nikhil's team. Nikhil must see it even though owner is not admin.
    $nikhil = $this->nikhil();
    $kapil = User::where('email', 'kapil@davya.local')->firstOrFail();

    $s = Student::create([
        'phone' => '7280000380',
        'course' => 'BCA',
        'owner_id' => $kapil->id,
        'referrer_id' => null,
        'lead_source' => 'Sheet:Nikhil',
    ]);

    $visible = Student::visibleTo($nikhil)->where('id', $s->id)->first();
    $this->assertNotNull($visible, 'Nikhil must see a non-admin-owned lead whose lead_source names his team');
}

public function test_member_sees_lead_routed_to_own_team_via_lead_source(): void
{
    // Admin-owned (not admin-referred-with-admin-owner, just owner=admin) lead with lead_source naming Nikhil's team.
    // The old gate required BOTH owner_id=admin AND referrer_id=admin; here referrer is null, so the
    // old rule would hide this from the Nikhil team. New rule: lead_source alone is enough.
    $nisha = User::where('email', 'nisha@davya.local')->firstOrFail();
    $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();

    $s = Student::create([
        'phone' => '7280000381',
        'course' => 'BCA',
        'owner_id' => $sumit->id,
        'referrer_id' => null,
        'lead_source' => 'Sheet:Nikhil',
    ]);

    $visible = Student::visibleTo($nisha)->where('id', $s->id)->first();
    $this->assertNotNull($visible, 'Nisha (member of Nikhil) must see a lead routed via lead_source');
}
```

### - [ ] Step 2: Run the new tests — expect FAIL

Run:
```bash
php artisan test --filter='test_head_sees_lead_routed_by_lead_source_even_when_non_admin_owned|test_member_sees_lead_routed_to_own_team_via_lead_source'
```

Expected: **2 failures**, both with `Failed asserting that null is not null.` (the `visibleTo` scope excludes the lead because the current admin-owned gate blocks it).

### - [ ] Step 3: Add failing policy test in `tests/Feature/StudentPolicyTest.php`

Append this method inside `StudentPolicyTest` (before the closing `}`):

```php
public function test_head_can_view_lead_routed_by_lead_source_even_when_non_admin_owned(): void
{
    // Mirror of the scope test, via $user->can('view', $student).
    $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
    $kapil = User::where('email', 'kapil@davya.local')->firstOrFail();

    $s = Student::create([
        'phone' => '9700000001',
        'name' => 'S',
        'owner_id' => $kapil->id,
        'referrer_id' => null,
        'lead_source' => 'Sheet:Nikhil',
    ]);

    $this->assertTrue($nikhil->can('view', $s), 'policy view must allow head when lead_source names his team');
}
```

### - [ ] Step 4: Run the policy test — expect FAIL

Run:
```bash
php artisan test --filter=test_head_can_view_lead_routed_by_lead_source_even_when_non_admin_owned
```

Expected: **1 failure** with `Failed asserting that false is true.`

### - [ ] Step 5: Edit `app/Models/Student.php` — generalise scope's `lead_source` branch

Replace the head/member branch (currently lines 37–57). The whole method `scopeVisibleTo` should end up looking like:

```php
public function scopeVisibleTo(Builder $query, ?User $user): Builder
{
    if ($user === null) {
        return $query->whereRaw('1 = 0');
    }
    if ($user->hasRole('admin')) {
        return $query;
    }

    // Heads and their team members share the same visibility — a team acts as one unit.
    // Freelancers are restricted to what they personally own or referred.
    if ($user->hasRole('head') || $user->hasRole('member')) {
        $headId = $user->hasRole('head') ? $user->id : ($user->team_head_id ?? $user->id);
        $teamIds = User::where('team_head_id', $headId)->pluck('id')->toArray();
        $teamIds[] = $headId;

        $teamNames = User::whereIn('id', $teamIds)->pluck('name')->toArray();
        $teamLeadSources = array_merge(
            $teamNames,
            array_map(fn ($n) => 'Sheet:'.$n, $teamNames),
        );

        return $query->where(fn ($q) => $q
            ->whereIn('owner_id', $teamIds)
            ->orWhereIn('referrer_id', $teamIds)
            ->orWhereIn('lead_source', $teamLeadSources));
    }

    // Freelancer — strictly own.
    return $query->where(fn ($q) => $q
        ->where('owner_id', $user->id)
        ->orWhere('referrer_id', $user->id));
}
```

Note: the `$adminId = User::role('admin')->value('id');` lookup and the admin-owned nested `orWhere` clause are removed.

### - [ ] Step 6: Edit `app/Policies/StudentPolicy.php` — generalise `view()`

Replace the entire `view` method with:

```php
public function view(User $user, Student $student): bool
{
    if ($user->hasRole('admin')) {
        return true;
    }
    if ($student->owner_id === $user->id || $student->referrer_id === $user->id) {
        return true;
    }

    // Heads and their team members share visibility — the team is one unit.
    if ($user->hasRole('head') || $user->hasRole('member')) {
        $headId = $user->hasRole('head') ? $user->id : ($user->team_head_id ?? $user->id);
        $teamIds = User::where('team_head_id', $headId)->pluck('id')->toArray();
        $teamIds[] = $headId;

        if (in_array($student->owner_id, $teamIds, true)
            || in_array($student->referrer_id, $teamIds, true)) {
            return true;
        }

        if ($student->lead_source !== null) {
            $teamNames = User::whereIn('id', $teamIds)->pluck('name')->toArray();
            $teamLeadSources = array_merge(
                $teamNames,
                array_map(fn ($n) => 'Sheet:'.$n, $teamNames),
            );
            if (in_array($student->lead_source, $teamLeadSources, true)) {
                return true;
            }
        }
        return false;
    }
    return false;
}
```

Note: the `$adminId = User::role('admin')->value('id');` lookup and the `owner_id === adminId && referrer_id === adminId` gate are removed.

### - [ ] Step 7: Run the new three tests — expect PASS

Run:
```bash
php artisan test --filter='test_head_sees_lead_routed_by_lead_source_even_when_non_admin_owned|test_member_sees_lead_routed_to_own_team_via_lead_source|test_head_can_view_lead_routed_by_lead_source_even_when_non_admin_owned'
```

Expected: **3 passed**.

### - [ ] Step 8: Run the full existing visibility + policy suites to confirm no regression

Run:
```bash
php artisan test --filter='NikhilVisibilityTest|StudentPolicyTest'
```

Expected: **all tests pass** (existing Nikhil suite from `b18855b` must still be green). DEPR lines are fine — check the final `Tests: X passed` line.

### - [ ] Step 9: Commit

```bash
git add app/Models/Student.php app/Policies/StudentPolicy.php \
        tests/Feature/NikhilVisibilityTest.php tests/Feature/StudentPolicyTest.php
git commit -m "$(cat <<'EOF'
feat(visibility): lead_source routes to team regardless of owner

Drops the admin-owned-AND-admin-referred gate on lead_source matching
in both Student::scopeVisibleTo and StudentPolicy::view. Any lead whose
lead_source names a team (by name or "Sheet:name" prefix) now routes to
that team's head + members.

Ref: docs/superpowers/specs/2026-04-22-lead-access-rule-design.md (gap #3)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Edit split — counsellors cannot edit teammate's leads

**Rationale:** Today `StudentPolicy::update = view`, so counsellors can edit anything they see. New contract: counsellors edit only their own or referenced leads; heads keep team-wide edit.

**Files:**
- Modify: `app/Policies/StudentPolicy.php` (the `update` method)
- Test: `tests/Feature/StudentPolicyTest.php` (append)

### - [ ] Step 1: Write failing tests in `tests/Feature/StudentPolicyTest.php`

Append these three methods:

```php
public function test_member_cannot_update_teammate_lead(): void
{
    // Poonam + Neetu both report to Sonam. Poonam sees Neetu's lead (team-wide view),
    // but must NOT be able to edit it under the new rule.
    $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();
    $neetu = User::where('email', 'neetu@davya.local')->firstOrFail();

    $s = Student::create([
        'phone' => '9800000001',
        'name' => 'S',
        'owner_id' => $neetu->id,
        'referrer_id' => $neetu->id,
        'lead_source' => 'Neetu',
    ]);

    $this->assertTrue($poonam->can('view', $s), 'team-wide view stays intact');
    $this->assertFalse($poonam->can('update', $s), 'counsellor must NOT edit teammate lead');
}

public function test_member_can_update_own_lead(): void
{
    $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();

    $s = Student::create([
        'phone' => '9800000002',
        'name' => 'S',
        'owner_id' => $poonam->id,
        'referrer_id' => $poonam->id,
        'lead_source' => 'Poonam',
    ]);

    $this->assertTrue($poonam->can('update', $s), 'counsellor edits own lead');
}

public function test_member_can_update_lead_where_they_are_referrer(): void
{
    $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();
    $neetu = User::where('email', 'neetu@davya.local')->firstOrFail();

    $s = Student::create([
        'phone' => '9800000003',
        'name' => 'S',
        'owner_id' => $neetu->id,
        'referrer_id' => $poonam->id,
        'lead_source' => 'Neetu',
    ]);

    $this->assertTrue($poonam->can('update', $s), 'counsellor edits lead where she is referrer');
}

public function test_head_can_update_teammate_lead(): void
{
    // Regression lock: heads keep team-wide edit.
    $sonam = User::where('email', 'sonam@davya.local')->firstOrFail();
    $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();

    $s = Student::create([
        'phone' => '9800000004',
        'name' => 'S',
        'owner_id' => $poonam->id,
        'referrer_id' => $poonam->id,
        'lead_source' => 'Poonam',
    ]);

    $this->assertTrue($sonam->can('update', $s), 'head edits any team lead');
}
```

### - [ ] Step 2: Run the four new tests — expect ONE FAIL

Run:
```bash
php artisan test --filter='test_member_cannot_update_teammate_lead|test_member_can_update_own_lead|test_member_can_update_lead_where_they_are_referrer|test_head_can_update_teammate_lead'
```

Expected: **1 failed, 3 passed**. The failing one is `test_member_cannot_update_teammate_lead` (currently `update = view` so Poonam can edit Neetu's lead).

### - [ ] Step 3: Edit `app/Policies/StudentPolicy.php` — split `update()`

Replace the current `update` method:

```php
public function update(User $user, Student $student): bool
{
    return $this->view($user, $student);
}
```

with:

```php
public function update(User $user, Student $student): bool
{
    if ($user->hasRole('admin')) {
        return true;
    }
    if ($student->owner_id === $user->id || $student->referrer_id === $user->id) {
        return true;
    }
    // Heads can edit anything visible to their team; counsellors can only edit own/referenced.
    if ($user->hasRole('head')) {
        return $this->view($user, $student);
    }
    return false;
}
```

### - [ ] Step 4: Run the same four tests — expect ALL PASS

Run:
```bash
php artisan test --filter='test_member_cannot_update_teammate_lead|test_member_can_update_own_lead|test_member_can_update_lead_where_they_are_referrer|test_head_can_update_teammate_lead'
```

Expected: **4 passed**.

### - [ ] Step 5: Run the full visibility + policy suites — expect no regression

Run:
```bash
php artisan test --filter='NikhilVisibilityTest|StudentPolicyTest'
```

Expected: **all pass**. Pay attention to `test_nikhil_sees_but_cannot_edit_lead_outside_team` and `test_nikhil_can_edit_leads_he_can_see` — both should stay green.

### - [ ] Step 6: Commit

```bash
git add app/Policies/StudentPolicy.php tests/Feature/StudentPolicyTest.php
git commit -m "$(cat <<'EOF'
feat(policy): split edit rights — counsellors cannot edit teammate leads

Today StudentPolicy::update delegates to view(), so counsellors can edit
anything they see. New rule: counsellors edit only own or referenced;
heads keep team-wide edit; admin keeps all.

Ref: docs/superpowers/specs/2026-04-22-lead-access-rule-design.md (gap #2)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Delete restriction — admin only

**Rationale:** Today heads can delete any team lead. New contract: delete is admin-only.

**Files:**
- Modify: `app/Policies/StudentPolicy.php` (the `delete` method)
- Test: `tests/Feature/StudentPolicyTest.php` (append)

### - [ ] Step 1: Write failing tests in `tests/Feature/StudentPolicyTest.php`

Append:

```php
public function test_admin_can_delete_student(): void
{
    $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
    $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
    $s = Student::create([
        'phone' => '9900000001',
        'name' => 'S',
        'owner_id' => $nikhil->id,
        'referrer_id' => $nikhil->id,
        'lead_source' => 'Nikhil',
    ]);
    $this->assertTrue($sumit->can('delete', $s), 'admin deletes any lead');
}

public function test_head_cannot_delete_team_lead(): void
{
    $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
    $s = Student::create([
        'phone' => '9900000002',
        'name' => 'S',
        'owner_id' => $nikhil->id,
        'referrer_id' => $nikhil->id,
        'lead_source' => 'Nikhil',
    ]);
    $this->assertFalse($nikhil->can('delete', $s), 'head must NOT delete team lead');
}

public function test_member_cannot_delete_own_lead(): void
{
    // Even on his own lead, a counsellor cannot delete.
    $nisha = User::where('email', 'nisha@davya.local')->firstOrFail();
    $s = Student::create([
        'phone' => '9900000003',
        'name' => 'S',
        'owner_id' => $nisha->id,
        'referrer_id' => $nisha->id,
        'lead_source' => 'Nisha',
    ]);
    $this->assertFalse($nisha->can('delete', $s), 'counsellor must NOT delete (even own)');
}
```

### - [ ] Step 2: Run the three new tests — expect TWO FAIL

Run:
```bash
php artisan test --filter='test_admin_can_delete_student|test_head_cannot_delete_team_lead|test_member_cannot_delete_own_lead'
```

Expected: **2 failed, 1 passed**. The passing one is `test_admin_can_delete_student`; the two failures are the head and member cases (current rule permits both when they can view).

### - [ ] Step 3: Edit `app/Policies/StudentPolicy.php` — restrict `delete()`

Replace the current `delete` method:

```php
public function delete(User $user, Student $student): bool
{
    return $user->hasRole('admin') || ($user->hasRole('head') && $this->view($user, $student));
}
```

with:

```php
public function delete(User $user, Student $student): bool
{
    return $user->hasRole('admin');
}
```

### - [ ] Step 4: Run the three tests — expect ALL PASS

Run:
```bash
php artisan test --filter='test_admin_can_delete_student|test_head_cannot_delete_team_lead|test_member_cannot_delete_own_lead'
```

Expected: **3 passed**.

### - [ ] Step 5: Run the full visibility + policy suites — expect no regression

Run:
```bash
php artisan test --filter='NikhilVisibilityTest|StudentPolicyTest'
```

Expected: **all pass**.

### - [ ] Step 6: Commit

```bash
git add app/Policies/StudentPolicy.php tests/Feature/StudentPolicyTest.php
git commit -m "$(cat <<'EOF'
feat(policy): delete is admin-only

Previously heads could delete any lead they could view. New rule: only
admin (Sumit) may delete. Filament row-level Delete buttons will
automatically disappear for heads via the policy gate.

Ref: docs/superpowers/specs/2026-04-22-lead-access-rule-design.md (gap #1)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Transfer regression lock

**Rationale:** The spec keeps `transfer()` unchanged. Lock the positive cases with tests so no one can accidentally tighten or loosen it later.

**Files:**
- Test: `tests/Feature/StudentPolicyTest.php` (append) — no production code change

### - [ ] Step 1: Write regression tests

Append to `StudentPolicyTest`:

```php
public function test_admin_can_transfer_student(): void
{
    $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
    $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
    $s = Student::create([
        'phone' => '9950000001',
        'name' => 'S',
        'owner_id' => $nikhil->id,
        'referrer_id' => $nikhil->id,
        'lead_source' => 'Nikhil',
    ]);
    $this->assertTrue($sumit->can('transfer', $s), 'admin transfers any lead');
}

public function test_head_can_transfer_team_lead(): void
{
    $sonam = User::where('email', 'sonam@davya.local')->firstOrFail();
    $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();
    $s = Student::create([
        'phone' => '9950000002',
        'name' => 'S',
        'owner_id' => $poonam->id,
        'referrer_id' => $poonam->id,
        'lead_source' => 'Poonam',
    ]);
    $this->assertTrue($sonam->can('transfer', $s), 'head transfers team lead');
}

public function test_head_cannot_transfer_other_team_lead(): void
{
    $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
    $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();
    $s = Student::create([
        'phone' => '9950000003',
        'name' => 'S',
        'owner_id' => $poonam->id,
        'referrer_id' => $poonam->id,
        'lead_source' => 'Poonam',
    ]);
    $this->assertFalse($nikhil->can('transfer', $s), 'head cannot transfer other team lead');
}
```

### - [ ] Step 2: Run the three tests — expect ALL PASS (no code change)

Run:
```bash
php artisan test --filter='test_admin_can_transfer_student|test_head_can_transfer_team_lead|test_head_cannot_transfer_other_team_lead'
```

Expected: **3 passed**. (The existing `test_member_cannot_transfer_ownership` covers the counsellor-denied case.)

### - [ ] Step 3: Commit

```bash
git add tests/Feature/StudentPolicyTest.php
git commit -m "$(cat <<'EOF'
test(policy): lock transfer contract (admin + head-in-team only)

No code change — pure regression lock in line with the Lead Access Rule
spec, which keeps transfer() unchanged.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Full-suite verification

**Rationale:** Before merging/deploying, confirm the whole test suite stays green. Catches any unrelated breakage from the policy/scope changes (e.g. widgets, kanban, list-students page, bulk actions).

**Files:** none — verification only.

### - [ ] Step 1: Run the full Pest-unaware PHPUnit suite

Run:
```bash
php artisan test
```

Expected: **all tests pass**. DEPR lines are acceptable; only the final `Tests: X passed (Y assertions)` line matters.

### - [ ] Step 2: If any non-visibility test fails, stop and investigate

If a widget/kanban/list test fails:
1. Read the failure.
2. Check whether it creates a student with `owner_id = admin AND referrer_id = admin` and a team-named `lead_source` and expects that lead to be **hidden** from a head. Under the new rule, such a lead is now visible to that head — the test assumption is stale. Update the test to reflect the new rule.
3. Any other failure is unexpected — investigate root cause; do NOT paper over.

### - [ ] Step 3: Spot-check a couple of end-to-end paths

Run:
```bash
php artisan test --filter='ListStudentsPageTest|StudentResourceQueryTest'
```

Expected: **all pass**. These exercise the Filament list page and the `StudentResource::getEloquentQuery()` scope integration.

### - [ ] Step 4: Confirm the branch is clean and ready to push

Run:
```bash
git status
git log --oneline feature/lead-access-rule ^main | cat
```

Expected: clean working tree; commit log shows (from top) — Task 4 transfer test lock, Task 3 delete admin-only, Task 2 edit split, Task 1 lead_source generality, Task 0 spec commit `ac55a33`.

### - [ ] Step 5: Announce completion

Report to user:
- All 4 implementation commits landed on `feature/lead-access-rule`.
- Full test suite green.
- Ready for prod smoke per spec §Rollout (Nikhil login → no Delete; counsellor login → cannot edit teammate's lead).
- No migration required; deploy is code-only.

---

## Manual prod smoke checklist (post-deploy — not automated)

After deploying `feature/lead-access-rule` and running `php artisan optimize:clear`:

1. **Log in as Sumit** (admin) → open any student → Delete button visible → can delete.
2. **Log in as Nikhil** (head) → open a team lead → no Delete button; Edit button works.
3. **Log in as Nisha** (counsellor) → open a Nikhil-owned lead → can view; Save/Edit fails with Unauthorized toast.
4. **Log in as Nisha** → open her own lead → can edit + save.
5. **Log in as Sonam** → confirm she does NOT see Nikhil-team leads (unless referenced).
6. **Seed a test row**: `owner_id = Kapil (freelancer), lead_source = 'Sheet:Nikhil'` → Nikhil must see it (new lead_source routing).

---

## Risk summary (from spec)

- Pure code change, no migration, fully revertible via `git revert`.
- Prod behavioural changes: Nikhil + Sonam lose Delete; counsellors lose teammate-edit. Communicate before deploy if this affects current workflows.
