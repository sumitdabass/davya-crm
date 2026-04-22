# Today Tab — Brainstorm Session Prep

**For:** Sumit (facilitator)
**Session date:** 2026-04-22
**Duration target:** 60–75 min
**Source brief:** Today tab brainstorming brief (circulated separately)
**Companion:** `2026-04-22-today-tab-capture.md` — open as Google Doc at t=0

---

## 1. One-pager

### Core hypothesis (read aloud at t=0)
> davya-crm is organized around entities. Our day is organized around actions. The next upgrades reorganize the surface around actions without changing the schema underneath.

Team agrees or kills it. If killed, stop the session — the Today tab isn't the right next move.

### Room
- **Sumit** — facilitator, admin voice, tie-breaker if needed
- **Sonam** — head, Team 1 (Poonam, Neetu)
- **Nikhil** — head, Team 2 (Nisha, Kapil)
- **1 member from Sonam's team** — operational voice
- **1 member from Nikhil's team** — operational voice

### Five outputs to land (in order)
1. Meetings section — card contents + scheduling flow + window + rollover
2. Follow-ups section — columns + row actions + grouping
3. Reports section — metric definitions + date window defaults
4. Access matrix — 4 tiers + 5 edge cases
5. Drop list — 3+ rejected ideas with one-line reasons

### Hard constraints (do not re-open)
- Laravel 11 + Filament 3 + MySQL stack stays
- 10-stage pipeline + StageTransitionValidator stays
- Owner-driven Google Sheets stay read-only (Sonam / Nikhil rule)
- Dedup priority Sonam > Nikhil > Sumit stays
- Encrypted IPU password column with audited reveal stays

### Out of scope (do not re-litigate)
- WhatsApp / Slack / SMS integrations
- Multi-tenancy / B2B / SaaS
- Migrating off Filament 3
- Generic CRM modules (Companies / Products / Deals as separate from Students)
- AI summaries beyond existing finance Q&A

---

## 2. Option cards

Each card: the question, pre-thought options, your default, a kill criterion (signal to park for a 1:1 rather than force a group decision).

### Card 1 — Meetings section
**Question:** What goes on a meeting card, how does a meeting get scheduled, what window do we show, and what happens to meetings not marked done by EOD?

**Sub-decision 1A — Window**
- A. Today only
- B. Today + Tomorrow
- **C. Today + Tomorrow + Day after (3-day)** ← default from brief
- D. Today through Friday (5-day)

**Sub-decision 1B — Scheduling entry points**
- A. Student page only (clean, but extra clicks from Today tab)
- B. Today strip only (fast, but disconnected from student context)
- **C. Both** ← default; student-page flow for full context, strip "+Schedule" for repeat/follow-up

**Sub-decision 1C — Card fields**
Baseline: time, student name, course, owner, mode icon.
Team picks 0–2 additions:
- Phone number (visible without opening student)
- Source (sheet / walk-in / ref)
- Stage tag (color chip)
- Last note snippet (1 line, truncated)

**Sub-decision 1D — Rollover rule for meetings not marked done by EOD**
- A. Auto-no-show at midnight (harsh; forces hygiene)
- **B. Stay visible in "Today" until acted on, flagged overdue** ← default; matches how counsellors actually work
- C. Auto-rollover to tomorrow as unscheduled item (clutters tomorrow)

**Kill criterion:** if two or more sub-decisions deadlock, park Card 1 entirely. The Meetings feature has the most data-model implications of anything in this session (see appendix) — a clean 1:1 decision with one head beats a contested group guess.

---

### Card 2 — Follow-up section
**Question:** What columns show on the list, what one-click actions per row, how is it grouped, and what qualifies as "owed a follow-up"?

**Sub-decision 2A — Column set**
- A. Minimum 4: Student, Stage, Days waiting, Next action
- **B. Recommended 6: Student, Phone, Stage, Last contact, Days waiting, Owner (Owner column hidden for counsellors)** ← default
- C. Full 8: above + Source + Days-in-stage

**Sub-decision 2B — Row actions (one-click)**
Team picks 3–5 from:
- Mark contacted (logs note, advances next_action_at)
- Snooze N days (picker: 1 / 3 / 7)
- Advance stage (dropdown)
- Reassign (owner picker — admin/head only)
- Open WhatsApp (grey, ships later)
- Add note (inline popover)
- Mark dead

Sumit's starter pick: **Mark contacted + Snooze + Advance stage + Add note** (4 actions; Reassign is admin/head-only, shown as overflow menu item).

**Sub-decision 2C — Grouping**
- **A. By owner** ← default; matches how heads review
- B. By stage
- C. By days waiting (overdue-first)

**Sub-decision 2D — "Owed a follow-up" definition**
- A. `next_action_at <= today` (simple, current)
- B. A + "no contact in last N days" (N = 3 / 5 / 7)
- C. B + stage-aware (different N per stage)

Default: **A** for v1; revisit after 4 weeks of data.

**Kill criterion:** if row actions (2B) balloon beyond 5 picks, park and run a 1:1 with each head separately. Members will want more; heads will want fewer. Don't resolve in the room.

---

### Card 3 — Reports section
**Question:** Which metrics show, how are they calculated, and what date window is the default?

**Sub-decision 3A — Metric list**
- **A. Core 4: Meetings held, Follow-ups completed vs missed, Leads handled, Admissions closed** ← default for v1
- B. Core 4 + Conversion rate (Admissions / Leads handled)
- C. Extended 7: Core 4 + Conversion + Deal value closed + Avg days-in-stage

**Sub-decision 3B — Date window default**
- A. Today only (too narrow — noisy daily)
- **B. This week (Mon–today)** ← default
- C. Rolling 7-day
- D. This month

**Sub-decision 3C — "Meeting held" definition**
- A. Marked done in app (trust the data)
- **B. Marked done OR confirmed in owner sheet** ← default; matches how meetings actually get logged
- C. Has a note attached from that date

**Sub-decision 3D — Conversion denominator**
If conversion rate is adopted (3A option B/C):
- A. Leads handled in window / Admissions in window
- **B. Leads that entered pipeline in window / those same leads' admissions (cohort)** ← default; honest number, slower to stabilize

**Kill criterion:** if extended metrics (3A option C) get picked and any one metric can't be defined in a single line on the capture template, drop that metric and stay at Core 4 or 5.

---

### Card 4 — Access matrix (most contentious)
**Question:** Who sees what, write access aside — and how do the 5 edge cases resolve?

**Role tiers (decided pre-session, confirm in room):**

| Role | Data visibility |
|------|-----------------|
| Admin | Everyone's data, all teams |
| Team Head | Their team's data + their own |
| Counsellor | Their own data only; rolls up to a Team Head |
| Freelance | Their own data only; no Team Head above |

**Edge cases (decide in room — fill in capture template):**

| # | Question | Default to propose | Alternative |
|---|----------|-------------------|-------------|
| E1 | Can a Team Head see another head's team's meetings? | No | Yes for meetings only (transparency mode) |
| E2 | When a lead is reassigned across teams, does the original owner retain read-only visibility? | No — lose visibility on reassign | Yes — 30-day read-only grace window |
| E3 | Where does Freelance fit in dedup priority (Sonam > Nikhil > Sumit)? *(Sumit decides on the spot — not a group vote; listed here only to be captured)* | After Sumit (lowest) | Between Nikhil and Sumit |
| E4 | Can a Counsellor reschedule a teammate's meeting? | No — own meetings only | Yes, within same team, with audit log |
| E5 | Can Admin take an action as another owner (e.g. mark Priya contacted for Poonam)? | Yes, with "acting as" banner + audit entry | No — admin reassigns first, then acts |

**Kill criterion:** if E1 or E5 goes past 6 min, park it and decide 1:1 with Sonam + Nikhil. These two are where head identity politics live — a group decision under time pressure will produce a compromise nobody likes.

---

### Card 5 — Drop list
**Question:** What ideas did we consider for the Today tab and explicitly reject, with one-line reasons?

**Pre-written candidates** (team picks 3+ and writes the reason in the room):
1. WhatsApp inline send from meeting card — blocked on WhatsApp track
2. AI-generated meeting summary / next-best-action — premature per constraint list
3. Calendar sync (Google/Outlook) — separate integration, separate session
4. Kanban board on Today tab — duplicates Pipelines, not action-oriented
5. Per-member daily targets (e.g. "10 calls today") — targets are a heads conversation, not a tab feature
6. Dark mode — visual identity is its own theme (Area 5), not this tab
7. Commenting / @-mentions on follow-up rows — would need notifications, out of scope
8. Mobile-first rebuild of Today — slim mobile shell is Area 4, not this session

Team must write reasons **in their own words** on the capture template. Don't dictate them.

**Kill criterion:** if under 3 items get dropped, session fails its most important output. Reserve the last 10 min and protect them hard.

---

## 3. Facilitator script

### Global rules (tell the room at t=0)
> "Three rules. One — we're deciding the contents of five things, not whether to build any of them. Two — if a question hits 10 min without agreement, it goes to the parking lot and Sumit handles it in a 1:1. Three — members speak first on every card, heads second. This is to catch the operational gotchas before we default to 'heads know best'."

### Per-section scripts

**t=0, 5 min — Hypothesis + rules**
- Read core hypothesis verbatim
- Ask: "Agree, kill, or amend?" Show of hands.
- If killed: end session, reschedule around what the team thinks the real pain is.
- Read three rules above.

**t=5, 10 min — Card 1 (Meetings)**
- Opening: "What do you want on a meeting card you'd open tomorrow morning?"
- Members first. Timebox card fields (1C) to 3 min — it's the noisiest and least important.
- Stall rescue: *"Assume everything we discuss is v1. Ship-first, polish-second. Which of these would you miss on day one?"*
- Member amplifier: *"[Member name], you're opening this tab at 9 AM. What's the thing you wish was already there?"*
- Capture: fill Card 1 section of capture template live.

**t=15, 12 min — Card 2 (Follow-ups)**
- Opening: "This is the section you live in. Columns and row actions — members first."
- Row actions (2B) is the contested sub-decision. Expect members to want more, heads to want fewer.
- Stall rescue: *"What would remove a task from your sticky note? Only those go on the row."*
- Member amplifier: *"[Member name], if we cut this list to three actions, which three?"*
- Capture: fill Card 2 section.

**t=27, 10 min — Card 3 (Reports)**
- Opening: "Which numbers matter? Start with four, add more only if someone fights for them."
- Heads will have opinions on conversion definition (3D). Let them debate for 3 min max.
- Stall rescue: *"Pick the definition that gives you the less flattering number — that's the one that tells you something."*
- Capture: fill Card 3 section.

**t=37, 15 min — Card 4 (Access matrix)** — protected time
- Opening: "Four roles are decided. Five edge cases aren't. We go through them one at a time."
- Force a decision on each E1–E5 before moving to the next. 2–3 min each.
- Stall rescue for E1/E5: *"Park it. Sumit and heads decide after. Next edge case."*
- Capture: fill Card 4 table.

**t=52, 10 min — Card 5 (Drop list)** — protected time
- Opening: "Eight candidates on the board. Pick at least three. Write the reason in your own words."
- Member amplifier: *"[Member name], which one of these would you actually miss if we drop it? That's a signal we should reconsider."*
- Capture: fill Card 5.

**t=62, 3–5 min — Sign-off**
- Read the capture template aloud, section by section.
- Any corrections → edit live.
- Every attendee initials at the bottom.

### Stall/skip policy
- 10 min / card hard cap unless it's Card 4 or Card 5 (protected at 15 and 10 respectively)
- Anything unresolved goes to Parking Lot in capture template
- "Skip" is legal — if a card has no disagreement in 2 min, move on with the default

---

## 4. Divergence map

Anticipated pushback. These are hypotheses — adjust based on what actually lands in the room.

### Theme: Meetings (Card 1)
- **Sonam likely position:** wants tighter window (Today + Tomorrow), values focus over visibility
- **Nikhil likely position:** wants 3-day or 5-day, plans his week on Monday
- **Member likely position:** want Today only, overwhelmed by future visibility
- **Framing tactic:** open with "the window determines how much you see on a slow day" — reframe from "how much data" to "how much mental load"
- **Fallback:** if deadlock between Sonam and Nikhil on window, split — Today + Tomorrow for counsellors, 3-day for heads. Decompose on role, not on preference.

### Theme: Follow-ups (Card 2)
- **Sonam likely position:** wants reassign button visible to heads (controls team load)
- **Nikhil likely position:** wants fewer actions on screen, cleaner list
- **Member likely position:** want more inline actions (snooze, note, advance without leaving list)
- **Framing tactic:** separate row actions from overflow menu — "fast-four inline, everything else one click deeper"
- **Fallback:** if row action set explodes past 5, park and run 1:1 with each head

### Theme: Reports (Card 3)
- **Sonam likely position:** wants deal value included (closes are what she's measured on)
- **Nikhil likely position:** wants conversion rate but argues definition
- **Member likely position:** quiet on this; defer to heads
- **Framing tactic:** position Core 4 as "what we ship in v1" — all other metrics are "v2 once we have 4 weeks of data to calibrate"
- **Fallback:** if conversion definition (3D) deadlocks, show both for a month and pick after

### Theme: Access matrix (Card 4) — **deepest prep**
- **Sonam likely position:** wants head-to-head visibility (E1 = Yes for meetings). Reason: coordination. She'll frame it as "transparency", but the real driver is she wants to see if Nikhil's team is free when hers is overloaded.
- **Nikhil likely position:** E1 = No. Reason: doesn't want Sonam second-guessing his team's schedule. Will frame it as "boundaries" or "privacy."
- **Member likely position:** neutral on E1; strongly prefer E4 = No (don't let teammates reschedule their meetings — control over own calendar)
- **On E2 (reassign grace window):** both heads likely want Yes (grace window); members likely prefer No (clean break)
- **On E3 (Freelance in dedup):** Sumit decides — this is admin policy, not a vote
- **On E5 (Admin acts-as):** both heads likely uncomfortable with this unless there's an audit trail; members care less. Selling point: the audit log.

**Framing tactic for E1:** present default (No) with "Transparency Dashboard" as a v2 stretch — give Sonam something to ship toward without blocking today's decision.

**Decomposition fallback for access matrix:** if multiple edge cases deadlock, split into:
1. **Read access table** — who sees what (mostly what's above)
2. **Write access table** — who can take action on what (E4, E5 go here)

Resolving them as separate tables typically collapses 3 edge cases into 2 clear decisions.

### Theme: Drop list (Card 5)
- **Everyone's likely position:** reluctance to drop anything, especially ideas members brought up
- **Framing tactic:** "Dropping ≠ killing. Dropped items go on the 'maybe next quarter' list, not the graveyard. But they're off this tab for v1."
- **Fallback:** if under 3 items get dropped, Sumit picks the 3 weakest candidates and asks "one objection each — defend these or they drop." Forces a concrete defense.

---

## 5. Pre-session checklist (t=-1 hour)

- [ ] Open capture template in Google Doc, share edit access to all 5 attendees
- [ ] Confirm the two members attending (one per team) — send 15-min heads-up
- [ ] Print this prep doc OR keep on second monitor
- [ ] Whiteboard / Miro for Card 5 dot-voting (if in-person)
- [ ] Phone on silent — Sumit facilitates, doesn't take support pings

---

## Appendix — Schema heads-up (do NOT discuss in session)

- Meetings strip (Card 1) is the only section here that needs new data model work. Existing "Meeting Scheduled" stage has no datetime stored.
- Minimum implementation: `next_meeting_at` + `next_meeting_mode` columns on students
- Full implementation: separate `meetings` table (one-to-many with students)
- Spec this with Sumit + one head after the session; do not decide in room.
