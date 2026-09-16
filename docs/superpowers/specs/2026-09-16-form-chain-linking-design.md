# Form Chain Linking — Design Spec

Addresses customer-feedback item 4 ([docs/customer-feedback.md](../../customer-feedback.md)):
"เชื่อมข้อมูล rollcall ก่อน/ระหว่าง/หลัง — แบบฟอร์ม rollcall ทั้ง 3 ช่วง (ก่อนงาน/ระหว่างงาน/หลังงาน)
ต้องเชื่อมข้อมูลกัน ไม่ต้องกรอกข้อมูลซ้ำ ให้กรอกต่อจากช่วงก่อนหน้าได้เลย"

Built as a generic form-builder feature (not rollcall-specific), so any form can declare one or more
"next forms" and prefill matching fields — the rollcall 3-stage case is just one configuration of it.

## Goal

Let an admin configure, per form, a set of "next forms" that a user can jump straight into after
filling out the current one, with selected answers carried over automatically so the user doesn't
re-type the same data (driver name, vehicle plate, job number, etc.) in every stage.

## Data model

- `forms` — no new column. Chaining is expressed via a new pivot table, not a single `next_form_id`,
  because one form can fan out to **multiple, independent next forms** (not a single linear chain).
- New table `form_chain_links`:
  - `id`
  - `source_form_id` (FK → `forms.id`, cascade) — the "main" form
  - `next_form_id` (FK → `forms.id`, cascade) — a form reachable from the main form
  - unique (`source_form_id`, `next_form_id`)
- New table `form_field_chain_maps`:
  - `id`
  - `chain_link_id` (FK → `form_chain_links.id`, cascade)
  - `target_field_id` (FK → `form_fields.id`, cascade) — field on the *next* form
  - `source_field_id` (FK → `form_fields.id`, nullable, nullOnDelete) — field on the *main* form to
    copy the answer from; `null` means "don't prefill this field"
  - unique (`chain_link_id`, `target_field_id`)
- `form_submissions` — add `parent_submission_id` (nullable, self-referential FK → `form_submissions.id`,
  nullOnDelete). Set when a submission was created by following a chain link from another submission.
  Which link was followed is derivable from the child's own `form_id` compared against
  `form_chain_links` rows for the parent's `form_id` — no extra column needed for that.

## Admin configuration flow

- New button next to the existing permission button in [formTable.blade.php](../../../resources/views/form/formTable.blade.php), linking to a new page/route `form.chain.edit`.
- New view (e.g. `formChain.blade.php`), same simple ajax-save pattern as
  [formPermission.blade.php](../../../resources/views/form/formPermission.blade.php) (no submit button, each
  change saves immediately):
  - "+ เพิ่มฟอร์มถัดไป" adds another next-form entry. Each entry can be removed independently.
  - The dropdown of candidate next forms excludes: the form itself, sub-forms (`is_sub_form`), and any
    form that is already an *ancestor* of the current form via `form_chain_links` (a shallow one-level
    check is enough for this scope — not a full cycle-detection graph walk).
  - Each next-form entry expands into a field-mapping table: one row per field on that next form, each
    row a dropdown of "ไม่ต้องดึงข้อมูล" plus every field on the main form. Selecting a source field
    saves that one mapping row immediately.
- Removing a next-form entry deletes its `form_chain_links` row (cascades its `form_field_chain_maps`
  rows).

## End-user flow

- **No change to `DocumentController::store()`** — submitting a form does not surface any "next form"
  UI. This keeps the fill-out screen unchanged.
- **Viewing a submission** (`DocumentController::show()` / the "continue document" edit view,
  [continueDocument.blade.php](../../../resources/views/form/checking/continueDocument.blade.php)): for
  every `form_chain_links` row where `source_form_id` = this submission's form:
  - Skip it if the next form is disabled (`status = false`).
  - Skip it if the current user's position doesn't have permission for the next form
    (`hasThisForm`) — hidden entirely, no error message.
  - If a child submission already exists (`FormSubmissions::where('parent_submission_id', $this->id)
    ->where('form_id', $nextForm->id)->exists()`), show a "ดูฟอร์มต่อเนื่อง: {title}" link to that
    existing submission instead of a new one.
  - Otherwise show a "ทำฟอร์มต่อเนื่อง: {title}" button linking to
    `route('document.fill-out', $nextForm->id) . '?from_submission=' . $submission->submission_id`.
- **Opening a form via `from_submission`** (`DocumentController::fillOutForm()`):
  - Resolve the parent `FormSubmissions` by `submission_id`, scoped to the same org/TSM-connected-org
    as the current user (same branching rule described in CLAUDE.md's multi-tenant section). If it
    doesn't resolve — wrong org, doesn't exist, or the requesting user otherwise can't see it — ignore
    the param entirely and render a normal, empty form. No error is shown to the user.
  - Otherwise look up the `form_chain_links` row for (parent's `form_id` → this form's id), then its
    `form_field_chain_maps`, and use each mapped `source_field_id`'s answer (from the parent's
    `FormSubmissionValue` rows) as the initial value of the corresponding `target_field_id` in the
    Alpine form-fill state. Unmapped fields start blank as usual.
  - Pass `parent_submission_id` through the form (hidden field) so `store()` saves it onto the new
    `FormSubmissions` row.

## Edge cases

- **Cycle prevention**: block selecting a next form that already has the current form as one of its own
  ancestors (one level back is sufficient for this feature's scope).
- **Next form disabled/deleted after linking**: hidden from the button list at render time by checking
  `status`; a soft-deleted next form is simply excluded by the normal `Form` query scope.
- **Field deleted after being mapped**: `form_field_chain_maps.target_field_id` and `.source_field_id`
  cascade/null-out via their FKs when the referenced `form_fields` row is removed, so no dangling
  mapping rows need manual cleanup.
- **Mismatched field types in a mapping** (e.g. select → text): the raw stored string value is copied
  as-is, unvalidated — it's the admin's responsibility to map compatible fields sensibly, and the user
  filling the next form can always edit a prefilled value before submitting.
- **`from_submission` points at a submission outside the current user's org/visibility**: treated the
  same as "not found" — ignored, form renders empty, no error surfaced.
- **Parent submission later deleted**: `parent_submission_id` is `nullOnDelete`, so the child submission
  survives and just loses the backlink.

## Testing

The project has no real test suite yet (`tests/` is Laravel's default scaffolding — see
[CLAUDE.md](../../../CLAUDE.md)). Add light Feature tests for the risk points introduced here rather
than a full suite:

- Configuring a chain link + field maps through the admin endpoints round-trips correctly.
- `fillOutForm` with a valid `from_submission` prefills fields exactly per the configured mapping.
- `fillOutForm` with a `from_submission` from a different org, or one the user can't see, renders a
  normal empty form (no prefill, no error).
- The submission view shows the correct button state per next-form: hidden when the user lacks
  permission, hidden when the next form is disabled, "ดู..." once a child submission exists, otherwise
  "ทำ...".

Manual browser verification during implementation: configure rollcall ก่อนงาน → {ระหว่างงาน, ...} as
next forms, submit ก่อนงาน, open its show page, confirm the button(s) appear, follow one into ระหว่างงาน
and confirm the mapped fields are prefilled, then return to ก่อนงาน's show page and confirm that
button now reads "ดูฟอร์มต่อเนื่อง".
