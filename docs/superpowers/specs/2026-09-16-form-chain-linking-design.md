# Form Chain Linking — Design Spec

Addresses customer-feedback item 4 in [customer-feedback.md](../../customer-feedback.md): the three
rollcall stages (before, during, and after work) must be able to continue from one another without
re-entering shared information.

This is a generic form-builder feature. Rollcall is configured as, for example,
`ก่อนงาน → ระหว่างงาน` and `ก่อนงาน → หลังงาน`; neither the schema nor UI contains rollcall-specific
logic.

## Goal and boundaries

An administrator can configure one or more next forms for a form, map answerable fields between them,
and optionally carry the selected employee and vehicle. When a user opens a submission, they can start
an allowed next form with those values prefilled. The user may always edit prefilled values before saving.

The feature deliberately does not:

- show a next-form prompt immediately after submit; the normal submit success flow stays unchanged;
- infer mappings from equal field labels; every mapping is explicit;
- copy attached files, approval state, submission history, or arbitrary submission data; or
- make a chained submission update automatically when its parent is edited later.

## Terminology

- **Source form/submission**: the completed form and submission from which the user continues.
- **Next form**: a form directly reachable from a source form.
- **Chain link**: the configured directed edge from one form to one next form.
- **Answerable field**: a root-form field other than `subform`, plus each field inside a subform embedded
  by that root form. The `subform` heading itself is not answerable and must never be mapped.

## Data model

Add these migrations and Eloquent relationships. IDs below are internal numeric IDs, not public UUIDs.

### `form_chain_links`

| Column | Rules | Meaning |
| --- | --- | --- |
| `id` | primary key | Chain-link ID. |
| `source_form_id` | FK → `forms.id`, `cascadeOnDelete` | Source form. |
| `next_form_id` | FK → `forms.id`, `cascadeOnDelete` | Direct next form. |
| `copy_selected_user` | boolean, default `false` | Copy `FormSubmissions.user_id` when both forms select an employee. |
| `copy_selected_vehicle` | boolean, default `false` | Copy `FormSubmissions.vehicle_id` when both forms select a vehicle. |
| timestamps | | Audit/configuration ordering. |

Add a unique index on (`source_form_id`, `next_form_id`). Reject a self-link and all cyclic links in
application code; a database foreign key cannot enforce either cross-row rule.

### `form_field_chain_maps`

Store only selected mappings. An absent row means “do not prefill”; this is less ambiguous than keeping
nullable rows for every target field.

| Column | Rules | Meaning |
| --- | --- | --- |
| `id` | primary key | Map ID. |
| `chain_link_id` | FK → `form_chain_links.id`, `cascadeOnDelete` | Owning link. |
| `target_field_id` | FK → `form_fields.id`, `cascadeOnDelete` | Answerable field in the next form. |
| `source_field_id` | FK → `form_fields.id`, `cascadeOnDelete` | Answerable field in the source form. |
| timestamps | | Configuration audit. |

Add a unique index on (`chain_link_id`, `target_field_id`). The database cannot prove that each field is
part of the right root form, so every create/update must validate that `source_field_id` belongs to the
source form's answerable-field set and `target_field_id` belongs to the next form's answerable-field set.

### `form_submissions`

Add nullable `parent_submission_id`, a self-referential FK to `form_submissions.id` with
`nullOnDelete`, and a unique index on (`parent_submission_id`, `form_id`). The unique index permits many
top-level rows because MySQL treats `NULL` values as distinct, while preventing two child submissions of
the same next form from the same parent. Add `parent_submission_id` to `FormSubmissions::$fillable`,
with `parentSubmission()` and `childSubmissions()` relationships.

The child form ID plus its parent form ID identify the chain link, so no redundant chain-link ID is kept
on the submission. `store()` must still re-check that link before persisting the parent ID.

### Soft-delete behaviour

`Form` and `FormField` currently use `SoftDeletes`. Therefore their foreign-key actions run only on a
physical `forceDelete`, not on the normal delete actions in `FormController`. Normal queries must exclude
soft-deleted forms/fields; dormant links and maps can remain and work again if an item is restored.
Physical deletion cascades as defined above. A physically deleted parent submission leaves its child in
place with `parent_submission_id = NULL`.

## Relationships and reusable helpers

Add `nextChainLinks()` / `previousChainLinks()` to `Form`, `fieldMaps()` plus `sourceForm()` /
`nextForm()` to `FormChainLink`, and the corresponding `chainLink()`, `sourceField()`, and
`targetField()` relationships to `FormFieldChainMap`.

Keep the following logic in small private methods or a dedicated `FormChainService`; do not duplicate it
between controller actions:

- `currentOrgId()`: the project-standard branch: `session('connected_org')` for TSM users, otherwise
  `Auth::user()->userDetail->org`.
- `answerableFields(Form $rootForm)`: non-`subform` fields on the root plus active fields of each
  directly embedded subform. It must return IDs and models, not rely on a field label.
- `canAccessSubmission(FormSubmissions $submission)`: require the current org first. For a normal user,
  apply the existing document-list visibility rule: allow when they have `can_see_all_docs`, are
  `submitted_by`, or are the selected `user_id`. A TSM acting in the submission's connected org may
  access it. This helper is required when resolving a parent and a child chain submission.
- `canFillForm(Form $form)`: use the same rule as
  [selectForm.blade.php](../../../resources/views/form/checking/selectForm.blade.php): a TSM may fill,
  otherwise the current user's position must have the form via `hasThisForm`.
- `wouldCreateCycle($sourceId, $nextId)`: traverse outgoing `form_chain_links` from the proposed next
  form and reject when the source is reachable. This is a full graph walk over active rows, not a
  one-level check; it prevents `A → B → C → A` as well as direct two-form loops.

## Administration

### Access and candidate scope

Add a chain-settings button beside the permission button in
[formTable.blade.php](../../../resources/views/form/formTable.blade.php), pointing to
`form.chain.edit`. Chain configuration is restricted to non-sub-forms that are usable in the current
tenant context. A source form in the active org may link to active-org forms or global default forms; a
global default source may link only to global default forms. Do not expose forms owned by another org,
even if the current user happened to create them earlier.

All chain endpoints must load the source/link through this same scope. Never trust submitted form IDs,
link IDs, or field IDs. Disabled forms remain configurable but cannot be started by end users.

### Routes and controller actions

Add routes next to the existing `form.perm` routes:

| Route | Name | Action |
| --- | --- | --- |
| `GET /forms/{form_id}/chain` | `form.chain.edit` | Render chain settings. |
| `POST /forms/{form_id}/chain-links` | `form.chain.links.store` | Create a link for a validated `next_form_id`. |
| `DELETE /forms/{form_id}/chain-links/{chainLink}` | `form.chain.links.destroy` | Delete one link and its maps. |
| `PUT /forms/{form_id}/chain-links/{chainLink}/context` | `form.chain.context.update` | Set the employee/vehicle carry flags. |
| `PUT /forms/{form_id}/chain-links/{chainLink}/maps/{targetField}` | `form.chain.maps.update` | Upsert a validated source field, or delete the map when the value is empty. |

`FormController` may own these actions, but place the graph, scope, and mapping validation in the helper
described above. Every mutation returns the conventional Thai JSON success/error payload used by
`formSetPerm()`.

### Settings view

Create `resources/views/form/formChain.blade.php`, using the immediate AJAX-save and SweetAlert toast
pattern in [formPermission.blade.php](../../../resources/views/form/formPermission.blade.php).

- “+ เพิ่มฟอร์มถัดไป” presents only valid candidates after applying scope, sub-form exclusion, existing
  link exclusion, and the full cycle check. The server repeats all four checks.
- Each link has its own remove control. Removal deletes the link and cascades its maps.
- If both forms expose employee selection, show a “ดึงพนักงานที่เลือก” checkbox; do the equivalent for
  vehicle selection. Hide each checkbox when either form does not support that value.
- Render one row for every **current answerable target field**, ordered as it appears in the next form.
  The source dropdown begins with “ไม่ต้องดึงข้อมูล” and then the source form's answerable fields,
  labelled with their field type to distinguish duplicate labels. Selecting blank deletes a map; another
  selection upserts it immediately.
- Do not reject cross-type maps. Values are stored strings in `FormSubmissionValue`, and the user can
  edit a prefill. The field-type label is a warning aid, not an enforced compatibility rule.

## End-user flow

### Submission detail

`DocumentController::show()` loads usable outgoing links for the submission's form and passes display
data to [continueDocument.blade.php](../../../resources/views/form/checking/continueDocument.blade.php).
Render the chain area only on the read-only detail (`$is_show`), near the existing Print/Back controls.

For each link, in this order:

1. Ignore a soft-deleted or disabled next form.
2. Ignore it when `canFillForm($nextForm)` is false.
3. Look for a child with this parent ID and next form ID. If one exists and `canAccessSubmission()` is
   true, show `ดูฟอร์มต่อเนื่อง: {title}` to `document.submission.show`. If one exists but the viewer
   cannot access it, show nothing; do not reveal its existence or offer a duplicate that the database
   will reject.
4. Otherwise show `ทำฟอร์มต่อเนื่อง: {title}` to
   `route('document.fill-out', $nextForm->form_id) . '?from_submission=' . $submission->submission_id`.

The detail action itself should use `canAccessSubmission()` as part of this work, so the chain UI is not
attached to an otherwise cross-tenant submission page.

### Opening a chained form

`DocumentController::fillOutForm()` reads the optional public UUID `from_submission`.

1. Load the requested form in the current tenant context and check `canFillForm()`. A form that the user
   cannot fill receives the application's normal authorization response; only an invalid parent parameter
   is silently ignored.
2. Resolve the parent UUID, require `canAccessSubmission($parent)`, and find the exact link from the
   parent form to the requested form. If any step fails, ignore the parameter and render the ordinary
   empty form without an error.
3. Load valid maps and parent `FormSubmissionValue` rows, then build `prefilledValues` keyed by target
   field ID. Copy only non-null parent answers. When the link flags are enabled, also pass the parent
   selected user/vehicle IDs as initial selection values.
4. Pass the parent public UUID to Alpine as `chainParentSubmission`. On submit include it in the JSON
   payload; this application submits through `fetch()`, so a Blade hidden input alone would not be sent.

The Alpine initializer applies `prefilledValues` before rendering, including subform fields. When a valid
chain parent exists, it must **not restore** the target form's `localStorage` history: a stale draft must
not overwrite a mapped answer, a mapped date, or intentionally blank unmapped fields. Normal launches
retain today's history behaviour.

### Persisting the child safely

`DocumentController::store()` has no next-form success prompt to add, but it must be extended to handle
the chain payload securely:

1. When no chain parent is supplied, create an ordinary top-level submission.
2. When one is supplied, resolve its UUID again, run `canAccessSubmission()`, verify that the current user
   can fill the target form and that the parent → current-form link exists, then reject an invalid request
   with the normal JSON error response. Never persist a parent ID merely because it came from the browser.
3. Store the resolved internal parent ID together with the copied selections and field answers. Catch a
   duplicate-key failure from (`parent_submission_id`, `form_id`) and return a clear Thai error instead
   of creating a second child.

This revalidation prevents a user from forging the query string or JSON to attach an unrelated,
cross-tenant, or inaccessible submission as a parent.

## Edge cases

- A target form is disabled or soft-deleted after configuration: it is hidden at render time; the link
  configuration is preserved for a later restore/re-enable.
- A mapped source or target field is soft-deleted: default-scoped mapping queries ignore it. A physical
  deletion cascades its map row. No dangling value is copied.
- A mapping is configured after a parent submission already exists: opening the next form uses the
  parent values as stored now and the mapping as configured now.
- A parent is edited after a child exists: the child does not change; the detail shows the existing child
  rather than another start button.
- A user opens a valid chain URL twice: the first successful save wins; the unique child index causes the
  second save to return an error rather than duplicate data.
- A child whose selected employee or vehicle no longer exists receives `null` through the existing FK
  behavior; its field-answer mappings still work.

## Verification

`tests/Feature/FormChainLinkingTest.php` covers this feature with `RefreshDatabase` against an in-memory
SQLite connection (enabled for test runs only, via `phpunit.xml`'s `DB_CONNECTION`/`DB_DATABASE`
overrides — the app's own `.env` still points at MySQL). There were no factories for the form,
organization, position, or submission model graph beforehand, so the test builds that graph directly with
`Model::create()` rather than introducing new factory classes. It covers:

- link creation rejects a form from another org and rejects a cycle;
- a valid `from_submission` produces exactly the configured field/employee/vehicle prefills, and leaves
  an unmapped target field unset;
- a `from_submission` belonging to another org is ignored on GET (empty prefill, no chain parent);
- opening a form outside the user's own org (including via a stale/forged `PositionHasForm` row) is
  rejected with 404;
- a forged `chain_parent_submission` from another org is rejected on POST with no submission created;
- a duplicate child submission is rejected via the `(parent_submission_id, form_id)` unique constraint,
  with a clear Thai error, rather than creating a second row; and
- detail-page chain actions are hidden for a disabled or no-permission next form, and switch from
  "ทำ…" (no `submission_id`) to "ดู…" (`submission_id` present) once a child submission exists.

Run with `php artisan test --filter=FormChainLinkingTest`.

Manual browser verification for the initial rollcall rollout:

1. Configure ก่อนงาน → ระหว่างงาน and map a text field, a date, a subform field, the employee, and the
   vehicle.
2. Submit ก่อนงาน, open its detail page, and verify the ระหว่างงาน start button appears only for a
   position allowed to fill it.
3. Follow the button and verify mapped values are present, unmapped values are blank, and stale local
   history did not replace them. Edit one value and submit.
4. Return to ก่อนงาน detail and verify the button now reads “ดูฟอร์มต่อเนื่อง”; verify a second submit
   cannot create another ระหว่างงาน child.
5. Disable the target, attempt a forged `from_submission` URL and payload from another org, and verify
   that neither exposes nor links parent data.
