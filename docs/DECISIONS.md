# Architecture Decisions

## 2026-04-16 — Kanban plugin choice

**Status:** Shortlist locked; install + verification deferred to M6 task 6.1.

**Candidates:**

| Plugin | Repo | Criteria |
|---|---|---|
| `flowforge/filament-kanban` | github.com/flowforge-dev/flowforge | Established; customizable card content; drag-drop |
| `relaticle/filament-kanban-board` | github.com/Relaticle/filament-kanban-board | Active; good Filament v3 integration |
| `heloufir/filament-kanban-board` | github.com/heloufir/filament-kanban-board | Simpler API |
| **Fallback: custom Livewire + SortableJS** | — | Full control; ~4 extra hours |

**Decision procedure (to run at M6 start):**

1. Check each repo's last commit date on GitHub. Reject if >3 months stale.
2. Check Filament v3 compatibility in README.
3. Check customizable card content (we need: name, phone, owner, deal, pending, current_round).
4. `composer require <chosen>` in a throwaway branch `spike-kanban`, render empty kanban with 10 stages, test drag-drop callback.
5. If smoke test passes, merge; else try next candidate; else fall back to custom.

**Preferred starting candidate:** `relaticle/filament-kanban-board` (most actively maintained of the three as of 2026-04, best Filament v3 integration per README).

**Why decide now vs. at M6:** avoids the situation where M6 starts, plugin evaluation stalls, and the milestone timeline balloons. The shortlist + criteria is the cheap part; installation is the expensive part and belongs in M6 where we actually use the kanban.

**Fallback trigger:** if no shortlisted plugin supports showing custom card content (name / phone / owner / deal / pending / current_round) without hacks, go custom.

---

## 2026-04-16 — PHP 8.5 deprecation noise suppression

**Status:** Workaround in place; revisit when Laravel 11 patches the internal `PDO::MYSQL_ATTR_*` references.

Laravel 11's `config/database.php` references constants that PHP 8.5 deprecated. Suppressed via `error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)` in `bootstrap/app.php` and `phpunit.xml`. Tests still flag "deprecated" status (cosmetic — exit code 0, assertions pass).

**When to remove:** after `composer update laravel/framework` brings in a patch that fixes the PDO references, or when we upgrade to Laravel 12.
