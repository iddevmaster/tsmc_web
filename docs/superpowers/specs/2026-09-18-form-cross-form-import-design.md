# Cross-Form Data Import — Design Spec

Addresses customer-feedback item 5 in [customer-feedback.md](../../customer-feedback.md): while filling
out a form, a user wants to pull in data from another form's submission, with the system auto-filling
only the fields that match between the two forms.

This is a separate feature from the existing [form chain linking](2026-09-16-form-chain-linking-design.md)
feature ("ฟอร์มต่อเนื่อง"). Chain linking is admin-configured, persistent, and continues from one
specific completed submission's detail page. This feature is ad-hoc, stateless, and triggered by the user
from inside the fill-out screen itself, with **no admin configuration** and **no new database tables**.

## Goal and boundaries

While filling out a **new, blank** submission, a user can open a list of other forms' submissions made
**today** that they have visibility into, pick one, and have the system copy over values for any fields
that share the same label and type between the two forms. Only currently-empty fields are filled; the
user may repeat this from a different source submission to fill remaining gaps, and may always edit any
imported value before submitting.

The feature deliberately does not:

- apply to editing an already-submitted document (`continueDocument.blade.php`) — fill-out of a new
  submission only;
- persist any relationship between the source and the new submission — this is a one-time copy, not a
  link;
- require or use any admin-configured mapping — matching is fully automatic, computed at request time;
- overwrite a field the user (or restored draft history) has already filled;
- offer submissions of the same form as the one being filled — this is cross-form by definition;
- fuzzy-match labels, match across differing field types, or infer semantic equivalence — label and type
  must match exactly.

## Terminology

- **Target form/submission**: the form currently being filled out (not yet submitted).
- **Source submission**: a candidate submission of a *different* form, made today, that the user may
  import values from.
- **Answerable field**: same definition as in the chain-linking spec — a root-form field other than
  `subform`, plus each field inside a subform directly embedded by that root form.
- **Matched field pair**: a target answerable field and a source answerable field whose `trim(label)` and
  `type` are both exactly equal.

## Service: `FormImportService`

New class in `app/Services/FormImportService.php`, constructor-injected with `FormChainService` to reuse
its existing helpers rather than duplicating them:

- `currentOrgId()` — org scoping (TSM's `connected_org` vs normal user's `userDetail->org`).
- `canAccessSubmission(FormSubmissions $submission)` — visibility rule (`can_see_all_docs`,
  `submitted_by`, `user_id`).
- `answerableFields(Form $form)` — the field set eligible for matching.

New methods on `FormImportService`:

### `candidatesForToday(Form $targetForm): Collection`

1. Resolve `currentOrgId()`; if none, return empty.
2. Query `FormSubmissions` where `org = currentOrgId()`, `created_at` between `now()->startOfDay()` and
   `now()`, and `form_id != $targetForm->id`.
3. Filter to submissions where `canAccessSubmission($submission)` is true.
4. Group remaining submissions by `form_id` and compute each distinct source form's answerable-field
   signature set once (`label|type` strings), to avoid recomputing per submission.
5. Keep only submissions whose form's signature set intersects the target form's signature set on at
   least one entry.
6. Return each candidate with: submission id (public UUID), source form title, submitted-at timestamp,
   and, if available, the submitted-by user's name and the selected employee/vehicle labels (for display
   only, not used in matching).

### `matchedValues(FormSubmissions $source, Form $targetForm): array`

1. Re-derive `$source->form` and assert it differs from `$targetForm` (defensive; also checked by the
   controller).
2. Build `target answerable fields` and `source answerable fields`. For each target field, find a source
   field with equal `trim(label)` and `type`. When more than one source field matches (duplicate labels
   within the source form), pick the one with the lowest `order_number`, then lowest `id`, deterministically.
3. Look up each matched source field's value via `FormSubmissionValue` on `$source`; skip a match whose
   source value is null/empty (nothing useful to copy).
4. If both `$targetForm` and `$source->form` have `select_user` enabled, include `$source->user_id`
   (when non-null) as `user_id` in the payload. Do the same for `select_vehicle` → `vehicle_id`.
5. Return `['values' => [target_field_id => value, ...], 'user_id' => ?, 'vehicle_id' => ?]`.

Both methods are pure reads — nothing is written to the database, and no new migration is needed.

## Routes and controller actions

Add to the existing authenticated group in `routes/web.php`, next to the `document.fill-out` routes:

| Route | Name | Action |
| --- | --- | --- |
| `GET /document/{form_id}/import-candidates` | `document.import.candidates` | List today's cross-form candidates for this target form. |
| `GET /document/import-data/{submission_id}` | `document.import.data` | Return matched values for one chosen source submission. Requires query param `target_form_id`. |

Both actions live on `DocumentController`, delegating all logic to `FormImportService`.

`import-candidates`:
1. Resolve the target form by its public UUID in the current org scope (same pattern as `fillOutForm()`);
   404 if not found or not fillable by the current user (`canFillForm()`).
2. Call `candidatesForToday($targetForm)` and return as JSON.

`import-data`:
1. Resolve the target form the same way as above (`target_form_id` query param, same org/fillable check).
2. Resolve the source submission by its public UUID; require `canAccessSubmission()`, require
   `created_at` is today, require `source.org === currentOrgId()`, require `source.form_id !==
   $targetForm->id`. Any failure returns a 404/JSON error — never a partial payload.
3. Call `matchedValues($source, $targetForm)` and return as JSON.

Every check in step 2 is re-run server-side regardless of what the client claims, exactly as
chain-linking's `from_submission` handling already does — this endpoint is a new attack surface for
cross-org data access if trusted blindly.

## End-user flow (UI)

`resources/views/form/checking/fillOutForm.blade.php` only (not `continueDocument.blade.php`).

Add a button "นำเข้าข้อมูลจากฟอร์มอื่น (วันนี้)" near the top of the form, above the field list.

1. On click, `fetch(document.import.candidates)`.
   - Empty result → `Swal.fire` info toast: "ไม่มีใบที่นำเข้าได้วันนี้".
   - Non-empty → `Swal.fire` with a `<select>`/list of candidates, each labeled with source form title,
     submitted time, and submitter/employee/vehicle when present.
2. On confirming a choice, `fetch(document.import.data)` with the chosen submission's UUID and the
   current form's UUID.
3. Apply the returned payload inside `formFillOut()`'s Alpine state:
   - For each top-level field in `formFieldsAnswer` (excluding `subform` fields themselves) whose
     `answer` is currently falsy/empty, and each `subfields[]` entry under a `subform` field whose
     `answer` is currently falsy/empty: if `values[field.id]` exists, set `field.answer = values[field.id]`.
   - If `selectUserId` is currently empty and `payload.user_id` is present, set it. Same for
     `selectVehicleId`/`payload.vehicle_id`.
   - Count how many fields were actually changed (skip ones left untouched because they already had a
     value) for the summary toast.
4. Show a toast: "นำเข้าข้อมูลสำเร็จ (เติม N ช่อง)". If N is 0 (everything already filled, or the source
   had nothing importable after all), show "ไม่มีช่องว่างให้เติมจากใบนี้" instead.
5. The button remains usable after one import — a user may import from a second source submission to
   fill any fields still empty. There is no limit and nothing is persisted about the import having
   happened.

This runs after Alpine's `init()`/`restoreHistory()` (which restores a `localStorage` draft, if any) has
already populated `formFieldsAnswer`, so "only fill empty fields" naturally also means imported values
never clobber a restored draft — no special-case handling needed beyond the existing empty-check.

## Edge cases

- **Duplicate labels within the source form**: resolved deterministically by `order_number` then `id`
  (see `matchedValues` step 2). Not surfaced to the user as a choice — YAGNI for a first version.
- **Imported value doesn't match any `<option>` on a target `select`/`autocomplete` field**: the value is
  still written into `field.answer` (stored as a plain string exactly like chain-linking does); the
  native `<select>` will simply show nothing selected until the user picks a valid option. Accepted, not
  handled — same posture as the chain-linking spec's cross-type-map decision.
- **Nested subforms**: not supported — `answerableFields()` only looks one level into a directly-embedded
  subform, matching the existing chain-linking limitation.
- **"Today" boundary**: uses `config('app.timezone')` via `now()->startOfDay()`, not UTC or the client's
  local time.
- **Source submission's form is later disabled/soft-deleted**: irrelevant here since nothing persists —
  each `import-candidates` call re-queries live state, so a disabled form's submissions simply stop
  appearing as candidates going forward (existing submissions today from a form disabled minutes ago
  would still show, since disabling a form doesn't retroactively hide submissions already made — consistent
  with how the document list works elsewhere).
- **Race condition between listing candidates and importing**: none of consequence — `import-data`
  re-validates everything itself; a source submission deleted between the two calls simply 404s.

## Testing

`tests/Feature/FormImportTest.php`, `RefreshDatabase` + SQLite, following `FormChainLinkingTest`'s pattern
of building the org/position/form graph directly with `Model::create()` (no factories exist for this
graph yet).

Cases to cover:

1. Candidates list includes a same-org, today, visible, cross-form submission with ≥1 matching field.
2. Candidates list excludes: a submission of the same form as the target; a submission from another org;
   a submission from a form with zero matching fields; a submission the user has no visibility into
   (not `can_see_all_docs`, not the submitter, not the selected employee); a submission from yesterday.
3. `import-data` returns correct `values` for matched label+type pairs, skips a field whose label or type
   doesn't match, and skips a matched field whose source value is empty.
4. `import-data` includes `user_id`/`vehicle_id` only when both forms support the corresponding
   selection, and only when the source submission has a value set.
5. `import-data` rejects (no payload leaked): source from another org; source from today but the same
   form as target; a forged submission id that doesn't exist; a submission the requester cannot access.
6. Duplicate-label case: two source fields share a label+type — the lower `order_number` one is chosen,
   verified deterministically across repeated calls.

Run with `php artisan test --filter=FormImportTest`.

Manual browser verification:

1. Fill out and submit Form A today with several answerable fields (some named identically in type
   to fields in Form B, some not).
2. Open a blank Form B fill-out page, click "นำเข้าข้อมูลจากฟอร์มอื่น (วันนี้)" → confirm Form A's
   submission from today appears in the candidate list, with a reasonable label.
3. Pick it → confirm only the fields with matching label+type get filled, non-matching fields stay
   blank, and the toast reports the correct count.
4. Manually type a value into one still-empty field, then import from a *second* source submission that
   also matches that field → confirm the manually-typed value is untouched.
5. Confirm Form B does not appear as a candidate when filling out Form B itself (self excluded).
6. Confirm a submission from yesterday does not appear as a candidate today.
