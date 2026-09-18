# Future Ideas (not yet designed/approved)

Ideas parked for a later dedicated brainstorming session — not specs, not committed to.

## 2026-09-18: Job/Task entity to wrap forms

Raised while designing cross-form data import (customer-feedback item 5, see
[docs/superpowers/specs/2026-09-18-form-cross-form-import-design.md](superpowers/specs/2026-09-18-form-cross-form-import-design.md)).

**Idea:** instead of solving data-sharing between forms per-pair (admin-configured
[chain links](superpowers/specs/2026-09-16-form-chain-linking-design.md)) or ad-hoc per-click
(cross-form import), introduce a **Job** entity that wraps a set of forms:

- A job is created with details and a list of which forms must be filled for it.
- The job is assigned to one or more employees.
- When an employee opens their assigned job, they see exactly which forms are still outstanding.
- Any form submitted under a job can pull data from sibling submissions under the *same* job trivially
  (no per-pair config, no label/type matching needed — they're already grouped).

**Why it's appealing:** addresses the root cause instead of the symptom — both existing/planned
form-to-form data-sharing features exist because there's currently no grouping concept above individual
form submissions (`WorkRecord` is the closest existing thing today, but it's just a clock-in/clock-out
session with no link to `Form`/`FormSubmissions` at all). A Job layer would likely make chain-linking and
cross-form-import largely redundant, and adds assignment/checklist visibility as a side benefit.

**Why it's parked, not started:** this is a much larger scope change than either of the two features
above — a new core entity touching assignment, notifications, permissions, and probably every place
`DocumentController`/`FormController` currently scope submissions by org/user. It also risks disrupting
the rollcall chain-linking flow that just shipped and passed its test suite. Needs its own dedicated
brainstorming session (data model, assignment UX, how/whether it replaces chain-linking, migration path
for existing forms) before any implementation.
