# Form Builder Small Features Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship three small, independent form-builder improvements requested by customers: (1) a "Clone form" button, (2) an auto-generated `Year-Seq` job-number field type, and (3) an "autocomplete" (free text + pick-from-list) field type.

**Architecture:** All three features are additive to the existing dynamic form builder (`Form` / `FormField` / `FieldOption`) and require **no migrations** — job number and autocomplete values are stored as plain text in `FormSubmissionValue.value`, exactly like the existing `text` type. Each feature follows patterns already established in the codebase: bolt-on routes next to the existing `form.*` routes, `fetch()` + SweetAlert2 for AJAX actions (as in `createForm.blade.php`/`editForm.blade.php`), and a duplicated Alpine.js `fieldTypes` list kept in sync across `createForm.blade.php` and `editForm.blade.php`.

**No automated tests exist for this feature area today** (`tests/` is still Laravel's example scaffolding — see `CLAUDE.md`), and there are no model factories for `Form`/`FormField`/`Position`/`Org`. Building that scaffolding from scratch is out of proportion for three small features, so each task below ends with a concrete **manual verification checklist** (exact clicks/URLs/expected results) instead of a PHPUnit test. This was called out to the user during planning.

**Tech Stack:** Laravel 11 (PHP 8.2+), Blade + Alpine.js, Bootstrap 5, SweetAlert2. No new dependencies.

---

## File Structure

| File | Change |
|---|---|
| `routes/web.php` | Add `form.duplicate` route |
| `app/Http/Controllers/FormController.php` | Add `duplicate()`; extend `type` validator `in:` lists (Tasks 2 & 3) |
| `app/Models/FormField.php` | Add `job_number_default` accessor (Task 2); extend `options` accessor to cover `autocomplete` (Task 3) |
| `resources/views/form/formTable.blade.php` | Add Clone button + inline JS (Task 1) |
| `resources/views/form/createForm.blade.php` | Add `job_number`/`autocomplete` to `fieldTypes`; extend options-editor condition (Task 3) |
| `resources/views/form/editForm.blade.php` | Add `job_number`/`autocomplete` `<option>`s (+ missing `date` option found in passing); extend options-editor condition (Task 3) |
| `resources/views/form/checking/fillOutForm.blade.php` | Render `job_number` (pre-filled) and `autocomplete` (datalist) inputs |
| `resources/views/form/checking/continueDocument.blade.php` | Render `job_number` and `autocomplete` inputs when editing an existing submission |

No files are split — all touched files are already small and single-purpose.

---

## Task 1: Clone แบบฟอร์ม (Clone form button)

**Files:**
- Modify: `routes/web.php:126` (insert after the `form.delete` line)
- Modify: `app/Http/Controllers/FormController.php` (add `duplicate()` method after `destroy()`, currently ending at line 279)
- Modify: `resources/views/form/formTable.blade.php`

**Behavior:** Clicking "Clone" on a form row creates a new `Form` with the same title (+" (Copy)"), fields, field options, and position permissions as the original, but scoped to the current user's org/admin status (matching how `store()` already resolves `org`/`is_default`) and forced to `status = false` (ปิดใช้งาน) so it can't go live by accident. The user is redirected to the edit page of the new form.

- [ ] **Step 1: Add the route**

In `routes/web.php`, right after line 126 (`Route::delete('/forms/{form_category}/form/{id}', ...)->name('form.delete');`), add:

```php
    Route::post('/forms/{form_category}/duplicate/{id}', [FormController::class, 'duplicate'])->name('form.duplicate');
```

- [ ] **Step 2: Add `FormController::duplicate()`**

In `app/Http/Controllers/FormController.php`, insert this new method directly after `destroy()` (after the closing `}` currently at line 279, before `formPerm()`):

```php
    public function duplicate(string $form_category, string $id)
    {
        try {
            $originalForm = Form::with('formFields')->findOrFail($id);
            $org_id = Auth()->user()->is_tsm ? session('connected_org') : Auth()->user()->userDetail->org;

            $newForm = Form::create([
                'form_id' => Str::uuid(),
                'title' => $originalForm->title . ' (Copy)',
                'category' => $originalForm->category,
                'select_user' => $originalForm->select_user,
                'select_vehicle' => $originalForm->select_vehicle,
                'has_approve' => $originalForm->has_approve,
                'org' => $org_id,
                'created_by' => Auth::user()->id,
                'status' => false,
                'is_sub_form' => $originalForm->is_sub_form,
                'is_default' => Auth::user()->username === 'tsmcadmin' ? true : false,
            ]);

            foreach ($originalForm->formFields as $field) {
                $newField = FormField::create([
                    'form_id' => $newForm->id,
                    'label' => $field->label,
                    'type' => $field->type,
                    'subform_id' => $field->subform_id,
                    'required' => $field->required,
                    'order_number' => $field->order_number,
                    'is_default' => $newForm->is_default,
                ]);

                foreach ($field->options as $option) {
                    FieldOption::create([
                        'field_id' => $newField->id,
                        'value' => $option->value,
                    ]);
                }
            }

            foreach ($originalForm->hasPosition as $positionLink) {
                PositionHasForm::create([
                    'position_id' => $positionLink->position_id,
                    'form_id' => $newForm->id,
                ]);
            }

            return response()->json([
                'success' => 'คัดลอกแบบฟอร์มสำเร็จ',
                'form_id' => $newForm->form_id,
            ]);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['errors' => 'คัดลอกแบบฟอร์มไม่สำเร็จ'], 500);
        }
    }
```

No new imports are needed — `Form`, `FormField`, `FieldOption`, `PositionHasForm`, `Auth`, and `Str` are already imported at the top of `FormController.php`.

*Why `org`/`is_default` are recomputed instead of copied verbatim:* copying them as-is would let a normal org user clone a global default form (`org = null`, `is_default = true`) and end up with another global default form, effectively injecting into every org's default form set. Recomputing from the current user (same logic `store()` already uses) keeps the clone scoped to whoever created it.

- [ ] **Step 3: Add the Clone button and JS to the forms table**

In `resources/views/form/formTable.blade.php`, add a Clone button next to the permission button (after the closing `</a>` of the "กำหนดสิทธิ์" link, i.e. after line 58, still inside the same `<td>`):

```blade
                                                <button type="button" class="btn btn-info btn-sm clone-form-btn" data-form-id="{{ $form->id }}" data-form-category="{{ $category_name }}" data-bs-toggle="tooltip" data-bs-title="คัดลอก">
                                                    <i class="bi bi-files"></i>
                                                </button>
```

Then, right before `@endsection` at the bottom of the same file, add:

```blade
    <script>
        document.querySelectorAll('.clone-form-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const formId = btn.getAttribute('data-form-id');
                const formCategory = btn.getAttribute('data-form-category');

                Swal.fire({
                    title: 'คัดลอกแบบฟอร์มนี้?',
                    text: 'ระบบจะสร้างแบบฟอร์มใหม่ที่มีรายการเหมือนกันทุกประการ (ปิดใช้งานไว้ก่อน แก้ไขได้ทันที)',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'คัดลอก',
                    cancelButtonText: 'ยกเลิก',
                }).then((result) => {
                    if (!result.isConfirmed) return;

                    fetch(`/forms/${formCategory}/duplicate/${formId}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            }
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.errors) {
                                Swal.fire('เกิดข้อผิดพลาด', data.errors, 'error');
                            } else {
                                Swal.fire(data.success, '', 'success').then(() => {
                                    window.location.href = `/forms/${formCategory}/edit/${data.form_id}`;
                                });
                            }
                        })
                        .catch(() => {
                            Swal.fire('เกิดข้อผิดพลาด', 'กรุณาลองใหม่อีกครั้ง', 'error');
                        });
                });
            });
        });
    </script>
```

- [ ] **Step 4: Manual verification**

Run the dev server (`php artisan serve` + `npm run dev`), then:

1. Log in, go to `จัดการแบบฟอร์ม` → pick any category → the forms table.
2. Click the new "คัดลอก" (files icon) button on any existing form → confirm the SweetAlert prompt.
3. Expect a success toast, then a redirect to the edit page of a **new** form titled `<original title> (Copy)`.
4. On that edit page, confirm every field (label, type, required options) matches the original, including `select`-type options.
5. Go back to the forms table for the same category → confirm the new "(Copy)" row shows badge "ปิดใช้งาน" (red), not "เปิดใช้งาน".
6. Open `กำหนดสิทธิ์` (permission) on the original form, note which positions are checked; open it on the new clone → confirm the same positions are checked.
7. Try filling out the cloned form via `document/fill-out` while it's still "ปิดใช้งาน" (if the app blocks inactive forms from the fill-out list, confirm it does not appear there) — this is existing `status` behavior, not new code, just confirm nothing regressed.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/FormController.php resources/views/form/formTable.blade.php
git commit -m "$(cat <<'EOF'
Add clone-form button to duplicate a form with its fields and permissions

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Field type "เลขที่งาน (Auto)" — `job_number` (Year-Seq auto-generate)

**Files:**
- Modify: `app/Http/Controllers/FormController.php:94,103,187,196` (validator `in:` lists + error messages, `store()` and `update()`)
- Modify: `app/Models/FormField.php` (new `job_number_default` accessor)
- Modify: `resources/views/form/createForm.blade.php` (fieldTypes array)
- Modify: `resources/views/form/editForm.blade.php` (`<option>` list)
- Modify: `resources/views/form/checking/fillOutForm.blade.php` (pre-fill + render input)
- Modify: `resources/views/form/checking/continueDocument.blade.php` (render input when editing)

**Behavior:** A form builder can add a field of type "เลขที่งาน (Auto)". When a user opens a **blank** form to fill out, that field is pre-filled with `<current year>-<4-digit sequence>` (e.g. `2026-0001`), computed per-form (each form's counter is independent) by looking at the highest previously-generated value for that exact field this year. The value is a normal editable text input — the user can change it before submitting. Editing an **existing** submission never regenerates the value; it just shows what was saved.

*Known limitation (accepted, not handled):* if two people submit the same form in the same second, they could both see the same suggested number before either saves — this is a display-time suggestion, not a locked/reserved allocation. Out of scope per YAGNI for this feature request.

- [ ] **Step 1: Allow the new type in `FormController::store()`**

In `app/Http/Controllers/FormController.php`, change line 94:

```php
            'fields.*.type' => 'required|string|in:text,number,date,select,subform',
```
to:
```php
            'fields.*.type' => 'required|string|in:text,number,date,select,subform,job_number',
```

And update the Thai error message on line 103 from:
```php
            'fields.*.type.in' => 'ประเภทของรายการต้องเป็น ข้อความ, ตัวเลข, วันที่, แบบฟอร์มย่อย หรือ ตัวเลือก เท่านั้น',
```
to:
```php
            'fields.*.type.in' => 'ประเภทของรายการต้องเป็น ข้อความ, ตัวเลข, วันที่, แบบฟอร์มย่อย, ตัวเลือก หรือ เลขที่งาน (Auto) เท่านั้น',
```

- [ ] **Step 2: Allow the new type in `FormController::update()`**

Same file, change line 187:
```php
            'fields.*.type' => 'required|string|in:text,number,select,subform,date',
```
to:
```php
            'fields.*.type' => 'required|string|in:text,number,select,subform,date,job_number',
```

And update the matching error message on line 196 the same way as Step 1.

- [ ] **Step 3: Add the auto-number accessor to `FormField`**

In `app/Models/FormField.php`, add the import at the top:

```php
use App\Models\FormSubmissionValue;
```

Then add `'job_number_default'` to `$appends` and a new accessor method:

```php
    protected $appends = ['options', 'subform', 'job_number_default'];

    public function getJobNumberDefaultAttribute()
    {
        if ($this->type !== 'job_number') {
            return null;
        }

        $prefix = now()->year . '-';

        $lastValue = FormSubmissionValue::where('field_id', $this->id)
            ->where('value', 'like', $prefix . '%')
            ->orderByDesc('value')
            ->value('value');

        $lastSeq = $lastValue ? (int) substr($lastValue, strlen($prefix)) : 0;

        return $prefix . str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);
    }
```

- [ ] **Step 4: Add the type option to the form builder (create)**

In `resources/views/form/createForm.blade.php`, in the `fieldTypes` array (currently lines 139-159), add an entry so it reads:

```js
                fieldTypes: [{
                        value: 'text',
                        label: 'ข้อความ'
                    },
                    {
                        value: 'number',
                        label: 'ตัวเลข'
                    },
                    {
                        value: 'select',
                        label: 'ตัวเลือก'
                    },
                    {
                        value: 'date',
                        label: 'วันที่'
                    },
                    {
                        value: 'job_number',
                        label: 'เลขที่งาน (Auto)'
                    },
                    {
                        value: 'subform',
                        label: 'แบบฟอร์มย่อย'
                    }
                ],
```

- [ ] **Step 5: Add the type option to the form builder (edit)**

In `resources/views/form/editForm.blade.php`, the `<select>` at lines 63-69 hardcodes `<option>` tags directly (it does **not** use the `fieldTypes` array below it — that array is dead code left over from copying `createForm.blade.php`). Replace lines 64-67:

```blade
                                    <option value="text" :selected="field.type === 'text'">ข้อความ</option>
                                    <option value="number" :selected="field.type === 'number'">ตัวเลข</option>
                                    <option value="select" :selected="field.type === 'select'">ตัวเลือก</option>
                                    <option value="subform" :selected="field.type === 'subform'">แบบฟอร์มย่อย</option>
```
with:
```blade
                                    <option value="text" :selected="field.type === 'text'">ข้อความ</option>
                                    <option value="number" :selected="field.type === 'number'">ตัวเลข</option>
                                    <option value="select" :selected="field.type === 'select'">ตัวเลือก</option>
                                    <option value="date" :selected="field.type === 'date'">วันที่</option>
                                    <option value="job_number" :selected="field.type === 'job_number'">เลขที่งาน (Auto)</option>
                                    <option value="subform" :selected="field.type === 'subform'">แบบฟอร์มย่อย</option>
```

(`date` was silently missing from this dropdown before — added here in passing since we're already touching this exact list; without it, editing a form containing a date field showed no matching option.)

Also update the (currently unused, but kept for consistency) `fieldTypes` array further down in the same file so future authors don't recreate the same select/date gap — insert the same two entries used in Step 4 between `select` and `subform`.

- [ ] **Step 6: Pre-fill the value when filling out a new form**

In `resources/views/form/checking/fillOutForm.blade.php`, in the `formFillOut()` function, change the top-level field mapping's `answer:` line (currently line 195):

```js
                    answer: ''
```
to:
```js
                    answer: field.type === 'job_number' ? (field.job_number_default || '') : ''
```

Leave the `subfields` mapping (line 193, inside the subform branch) untouched — auto-numbering inside a sub-form is out of scope for this request.

- [ ] **Step 7: Render the input when filling out a new form**

In the same file, add a new template block right after the "Input Type: Text" block (after line 140):

```blade
                                            <!-- Input Type: Job Number (Auto) -->
                                            <template x-if="field.type === 'job_number'">
                                                <input type="text" class="form-control ms-2" x-model="field.answer" placeholder="ระบบสร้างเลขที่งานให้อัตโนมัติ แก้ไขได้">
                                            </template>
```

- [ ] **Step 8: Render the input when editing an existing submission**

In `resources/views/form/checking/continueDocument.blade.php`, the editable branch (inside `@else`, currently line 132) renders `text` fields with:

```blade
                                            <template x-if="field.type === 'text'">
                                                <input type="text" class="form-control ms-2" x-model="field.answer">
                                            </template>
```

Change the condition to also match `job_number` (an existing submission's job-number value is just plain saved text — same widget as `text`, no regeneration):

```blade
                                            <template x-if="field.type === 'text' || field.type === 'job_number'">
                                                <input type="text" class="form-control ms-2" x-model="field.answer">
                                            </template>
```

- [ ] **Step 9: Manual verification**

1. Open any existing form in edit mode (or create a new one) → add a field, set ประเภทคำตอบ to "เลขที่งาน (Auto)" → save.
2. Go fill out that form (`document/fill-out` flow) → confirm the "เลขที่งาน" field is pre-filled with `<current year>-0001` (or the next sequence number if you've submitted this form before) and is editable.
3. Change the value manually to something else, submit the form → confirm it saves the edited value (open the submission via "show"/print view and check).
4. Fill out the same form again (a second, separate submission) → confirm the new pre-filled suggestion is one higher than the **highest previously generated value** for this field (not just "count + 1" — test this by manually overwriting a value to a jump like `2026-0050` on one submission, then confirm the next pre-fill suggests `2026-0051`).
5. Open an existing submission of this form in edit mode (`document/{id}/edit`) → confirm the job-number field shows the value that was actually saved, not a freshly generated one.
6. Confirm a *different* form's job-number field (if you set one up) has its own counter starting from `2026-0001`, independent of the first form's counter.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/FormController.php app/Models/FormField.php resources/views/form/createForm.blade.php resources/views/form/editForm.blade.php resources/views/form/checking/fillOutForm.blade.php resources/views/form/checking/continueDocument.blade.php
git commit -m "$(cat <<'EOF'
Add auto-generated Year-Seq job number field type to the form builder

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Field type "ข้อความ + ตัวเลือก (Autocomplete)" — `autocomplete`

**Files:**
- Modify: `app/Http/Controllers/FormController.php` (validator `in:` lists, error messages, options-required rule, options-creation loop — `store()` and `update()`)
- Modify: `app/Models/FormField.php` (`options` accessor)
- Modify: `resources/views/form/createForm.blade.php` (fieldTypes array + options-editor condition)
- Modify: `resources/views/form/editForm.blade.php` (`<option>` list + options-editor condition)
- Modify: `resources/views/form/checking/fillOutForm.blade.php` (render `<input list>` + `<datalist>`)
- Modify: `resources/views/form/checking/continueDocument.blade.php` (same, edit mode)

**Behavior:** A new field type where the form builder defines a list of suggested options (identical UI to `select`), but at fill-out time the answer is a free-text `<input>` with an HTML5 `<datalist>` of those options — the user can type anything or pick a suggestion. Requires no new JS library (the project has none of select2/choices.js/awesomplete installed).

- [ ] **Step 1: Allow the new type + its options rule in `FormController::store()`**

In `app/Http/Controllers/FormController.php`, change (this is the same line touched in Task 2 Step 1 — apply on top of that change):
```php
            'fields.*.type' => 'required|string|in:text,number,date,select,subform,job_number',
```
to:
```php
            'fields.*.type' => 'required|string|in:text,number,date,select,subform,job_number,autocomplete',
```

Change:
```php
            'fields.*.options' => 'array|required_if:fields.*.type,select',
```
to:
```php
            'fields.*.options' => 'array|required_if:fields.*.type,select,autocomplete',
```

Update the error message (again building on Task 2's edit):
```php
            'fields.*.type.in' => 'ประเภทของรายการต้องเป็น ข้อความ, ตัวเลข, วันที่, แบบฟอร์มย่อย, ตัวเลือก, เลขที่งาน (Auto) หรือ ข้อความ+ตัวเลือก เท่านั้น',
```

Change the field-creation loop's options condition:
```php
                if ($field['type'] === 'select') {
```
to:
```php
                if (in_array($field['type'], ['select', 'autocomplete'])) {
```

- [ ] **Step 2: Same changes in `FormController::update()`**

Apply the equivalent four edits in `update()`: the `type` `in:` list (built on Task 2 Step 2's edit, now add `,autocomplete`), the `options` `required_if` rule, the error message, and the options-update loop's condition (`if ($field['type'] === 'select')` → `if (in_array($field['type'], ['select', 'autocomplete']))`).

- [ ] **Step 3: Extend the options accessor**

In `app/Models/FormField.php`, change:
```php
    public function getOptionsAttribute()
    {
        if ($this->type == 'select') {
            return FieldOption::where('field_id', $this->id)->get();
        }
        return [];
    }
```
to:
```php
    public function getOptionsAttribute()
    {
        if (in_array($this->type, ['select', 'autocomplete'])) {
            return FieldOption::where('field_id', $this->id)->get();
        }
        return [];
    }
```

- [ ] **Step 4: Add the type + reuse the options editor (create)**

In `resources/views/form/createForm.blade.php`, add to the `fieldTypes` array (building on Task 2 Step 4) an `autocomplete` entry, e.g. placed right after `select`:

```js
                    {
                        value: 'select',
                        label: 'ตัวเลือก'
                    },
                    {
                        value: 'autocomplete',
                        label: 'ข้อความ + ตัวเลือก (Autocomplete)'
                    },
```

Then change the options-editor block's condition (currently `<template x-if="field.type === 'select'">`, around line 68) to:

```blade
                            <template x-if="field.type === 'select' || field.type === 'autocomplete'">
```

- [ ] **Step 5: Add the type + reuse the options editor (edit)**

In `resources/views/form/editForm.blade.php`, add the `<option value="autocomplete" ...>` tag next to `select` (building on Task 2 Step 5's replacement block):

```blade
                                    <option value="select" :selected="field.type === 'select'">ตัวเลือก</option>
                                    <option value="autocomplete" :selected="field.type === 'autocomplete'">ข้อความ + ตัวเลือก (Autocomplete)</option>
```

And change its options-editor condition (currently `<template x-if="field.type === 'select'">`, around line 72) to:

```blade
                            <template x-if="field.type === 'select' || field.type === 'autocomplete'">
```

Also add the `autocomplete` entry to that file's `fieldTypes` array next to `select`, same as Step 4.

- [ ] **Step 6: Render the datalist input when filling out a new form**

In `resources/views/form/checking/fillOutForm.blade.php`, add a new template block after the "Select Dropdown" block (after line 160):

```blade
                                            <!-- Input Type: Autocomplete (Text + Options) -->
                                            <template x-if="field.type === 'autocomplete'">
                                                <div>
                                                    <input type="text" class="form-control ms-2" x-model="field.answer" :list="'dl-' + field.id" placeholder="กรอกหรือเลือกคำตอบ">
                                                    <datalist :id="'dl-' + field.id">
                                                        <template x-for="option in field.options" :key="option.value">
                                                            <option :value="option.value"></option>
                                                        </template>
                                                    </datalist>
                                                </div>
                                            </template>
```

- [ ] **Step 7: Render the datalist input when editing an existing submission**

In `resources/views/form/checking/continueDocument.blade.php`, add the same block (adjusted for this file's structure) right after the "Select Dropdown" block inside the `@else` branch (after line 149):

```blade
                                            <template x-if="field.type === 'autocomplete'">
                                                <div>
                                                    <input type="text" class="form-control ms-2" x-model="field.answer" :list="'dl-' + field.id">
                                                    <datalist :id="'dl-' + field.id">
                                                        <template x-for="option in field.options" :key="option.value">
                                                            <option :value="option.value"></option>
                                                        </template>
                                                    </datalist>
                                                </div>
                                            </template>
```

- [ ] **Step 8: Manual verification**

1. Create or edit a form → add a field, set ประเภทคำตอบ to "ข้อความ + ตัวเลือก (Autocomplete)" → confirm the options editor (+เพิ่มตัวเลือก) appears exactly like it does for "ตัวเลือก" (select) → add 2-3 option values → save.
2. Try saving with zero options → confirm you get the same Thai validation error as `select` ("กรุณาเพิ่มตัวเลือก...").
3. Go fill out the form → confirm the field renders as a text input; click into it and confirm the browser shows your options as suggestions (native datalist dropdown).
4. Type a value that is **not** in the options list → confirm it's accepted and submits successfully (no requirement to match a suggestion).
5. Submit, then open the submission in edit mode → confirm the saved free-text value shows in the input, and the datalist suggestions still work.
6. Open the submission in "show/print" mode → confirm the answer displays as plain text like any other field.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/FormController.php app/Models/FormField.php resources/views/form/createForm.blade.php resources/views/form/editForm.blade.php resources/views/form/checking/fillOutForm.blade.php resources/views/form/checking/continueDocument.blade.php
git commit -m "$(cat <<'EOF'
Add autocomplete field type (free text with suggested options) to the form builder

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Notes

- **Coverage:** Task 1 covers the "clone form" request in full (fields, options, and — per the user's confirmed decision — permissions). Task 2 covers auto-gen Year-Seq job numbers, per-form counters, still-editable, confirmed via the "highest previous value + 1" query. Task 3 covers the autocomplete request (free text or pick from list) using native `<datalist>`, matching the "no new JS library" constraint found during research.
- **Excel export/import:** Verified `ExcelController.php` and `ImportDataController.php` only special-case `field->type === 'subform'`; every other type (including the two new ones) falls through to the generic text-value path, so no changes are needed there.
- **Type consistency:** `job_number` and `autocomplete` are used identically (as plain strings) across `FormController` validators, `FormField` accessors, and all four Blade views touched — no naming drift between tasks.
- **Scope boundary (explicit):** the Clone button is added only to `resources/views/form/formTable.blade.php` (the regular, non-sub-form forms table). `resources/views/form/sub-form/formTable.blade.php` is intentionally left untouched — the original request didn't mention sub-forms, and adding it there would need its own review of `is_sub_form` implications. Flag to the user if sub-form cloning turns out to be wanted too.
