# Submission Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ให้ `tsmcadmin` เปิดดู submission ของทุกฟอร์มและทุกองค์กร แล้วลบ submission ที่ลูกค้ากรอกซ้ำได้ โดยการลบต้องไม่ทำลายข้อมูลในฐานข้อมูลถาวร และ submission ที่ลบแล้วต้องไม่ถูกนับหรือแสดงในรายงานเดิม

**Architecture:** เพิ่ม `deleted_at` ให้ `form_submissions` และใช้ Eloquent `SoftDeletes` กับ `FormSubmissions` เพื่อให้ global scope ของ Laravel ตัดรายการที่ถูกลบออกจาก query เดิมทั้งหมด เพิ่มหน้า admin สำหรับค้นหา/ดูรายการ submission และปุ่มลบผ่านฟอร์ม `DELETE` แบบมาตรฐาน พร้อมตรวจสิทธิ์ด้วย convention เดิม `Auth::user()->username === 'tsmcadmin'` ทั้งที่ controller และเมนู

**Tech Stack:** Laravel 11, Eloquent, Blade + Bootstrap 5, PHPUnit + SQLite in-memory (`RefreshDatabase`)

> **Implementation status (2026-09-23):** Implemented in the working tree. The focused suite passes (9 tests, 26 assertions), route registration/syntax/view-cache checks pass, and the full suite passes 56/57 tests (the remaining failure is the pre-existing unauthenticated `/` expectation in `Tests/Feature/ExampleTest.php`, which expects 200 while the route returns 302).

**Design context:** [docs/superpowers/specs/2026-09-23-submission-delete-design.md](../specs/2026-09-23-submission-delete-design.md)

## Scope decisions verified against the codebase

- `form_submissions.org` เก็บค่า `organizations.id` เป็น string ไม่ใช่ `organizations.org_id`; relation ต้องเป็น `belongsTo(Organization::class, 'org', 'id')`.
- `FormSubmissions` มี `getForm()`, `getUser()` และ `getVehicle()` อยู่แล้ว; เพิ่ม relation ใหม่สำหรับ `submitted_by` และ `org` โดยไม่ลบหรือเปลี่ยน relation เดิมที่หน้าปัจจุบันใช้.
- route ทั้งหมดอยู่ใน `Route::middleware(['auth'])->group(...)` ใน `routes/web.php`; เพิ่ม route ใหม่ในกลุ่มเดียวกัน.
- `DocumentController::destroy()` เป็น stub ที่บรรทัดปัจจุบันประมาณ 242; ใช้แทนสำหรับ soft delete.
- ไม่ใส่ลิงก์ไป `document.submission.show` ในหน้ารวมนี้ เพราะ `FormChainService::canAccessSubmission()` ต้องมีองค์กรปัจจุบัน และ `tsmcadmin` ตาม seeder ไม่มีองค์กร จึงทำให้ลิงก์ดังกล่าวได้ 403 แม้ผู้ใช้มีสิทธิ์ลบ.
- ใช้ redirect + flash message หลังลบ แทน AJAX เพื่อให้ตรงกับ design spec, ใช้ CSRF/HTTP method ของ Laravel ตามปกติ และไม่เพิ่ม JavaScript state ที่ต้อง sync กับ pagination.
- ไม่ลบ `form_submission_values` หรือ `form_submission_histories`; soft-deleted parent ยังเก็บข้อมูลไว้ และ child submissions ที่มี `parent_submission_id` ไม่ถูก cascade ใน flow นี้.
- `SoftDeletes` จะตัดรายการจาก query ผ่าน `FormSubmissions` โดยอัตโนมัติ ซึ่งครอบคลุม table/report/export/dashboard ที่ใช้อยู่; ต้องตรวจยืนยันว่าไม่มี raw `DB::table('form_submissions')` ที่หลุดจาก global scope.

## File structure

Create:

- `database/migrations/2026_09_23_000001_add_deleted_at_to_form_submissions_table.php`
- `tests/Feature/SubmissionDeleteTest.php`
- `resources/views/exportDocument/submissionsIndex.blade.php`

Modify:

- `app/Models/FormSubmissions.php`
- `tests/Concerns/BuildsFormTestData.php`
- `routes/web.php`
- `app/Http/Controllers/DocumentController.php`
- `resources/views/layouts/app.blade.php`
- `docs/customer-feedback.md` หลัง feature ผ่านการตรวจสอบครบ

---

## Task 1: Add the soft-delete column

**Files:**

- Create: `database/migrations/2026_09_23_000001_add_deleted_at_to_form_submissions_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
```

- [ ] **Step 2: Verify the migration**

Run:

```bash
php artisan migrate
php artisan tinker --execute="var_export(Schema::hasColumn('form_submissions', 'deleted_at'));"
```

Expected: migration succeeds and the second command prints `true`. Do not use `migrate:fresh` against a shared development database.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_09_23_000001_add_deleted_at_to_form_submissions_table.php
git commit -m "Add deleted_at to form_submissions"
```

---

## Task 2: Enable `SoftDeletes` and add display relations

**Files:**

- Modify: `app/Models/FormSubmissions.php`

- [ ] **Step 1: Add the trait**

Add:

```php
use Illuminate\Database\Eloquent\SoftDeletes;
```

and include `SoftDeletes` in the model traits:

```php
use HasFactory, SoftDeletes;
```

Keep the existing `$fillable` list and all existing relations unchanged.

- [ ] **Step 2: Add the relations used by the admin list**

Add after `getVehicle()`:

```php
public function submittedByUser()
{
    return $this->belongsTo(User::class, 'submitted_by', 'id');
}

public function organization()
{
    return $this->belongsTo(Organization::class, 'org', 'id');
}
```

These relations use the actual schema: `submitted_by` references `users.id`, while `org` contains `organizations.id` as a string.

- [ ] **Step 3: Verify the model boots**

Run:

```bash
php artisan tinker --execute="echo App\\Models\\FormSubmissions::query()->toSql();"
```

Expected: no fatal error, and the generated query contains a `deleted_at` null condition when executed.

- [ ] **Step 4: Commit**

```bash
git add app/Models/FormSubmissions.php
git commit -m "Enable soft deletes for form submissions"
```

---

## Task 3: Add a reusable `tsmcadmin` test fixture

**Files:**

- Modify: `tests/Concerns/BuildsFormTestData.php`

- [ ] **Step 1: Add `makeTsmcAdmin()` after `makeOrgUser()`**

```php
private function makeTsmcAdmin(): User
{
    $admin = User::create([
        'user_id' => Str::uuid(),
        'username' => 'tsmcadmin',
        'password' => bcrypt('password'),
        'is_tsm' => false,
    ]);

    User_detail::create([
        'user_id' => $admin->id,
        'fname' => 'admin',
        'lname' => 'tsmcadmin',
    ]);

    return $admin->fresh();
}
```

This mirrors `database/seeders/UserSeeder.php`: the authorization convention is the username, and the detail row prevents the shared app layout from dereferencing a missing `userDetail` relation. Do not assign an organization or position to this fixture; the feature must prove that the superadmin list is not organization-scoped.

- [ ] **Step 2: Run existing helper users of the trait**

Run:

```bash
php artisan test --filter='FormChainLinkingTest|FormImportTest|MandatoryReport'
```

Expected: existing tests remain green.

- [ ] **Step 3: Commit**

```bash
git add tests/Concerns/BuildsFormTestData.php
git commit -m "Add tsmcadmin fixture for submission tests"
```

---

## Task 4: Add routes and implement the submissions index

**Files:**

- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/DocumentController.php`
- Create: `tests/Feature/SubmissionDeleteTest.php`
- Create: `resources/views/exportDocument/submissionsIndex.blade.php` (minimal testable view first)

- [ ] **Step 1: Add the routes inside the existing auth group**

Place next to the other `document.*` routes:

```php
Route::get('/document/submissions', [DocumentController::class, 'submissionsIndex'])
    ->name('document.submissions.index');
Route::delete('/document/submissions/{submission_id}', [DocumentController::class, 'destroy'])
    ->name('document.submissions.destroy');
```

- [ ] **Step 2: Write the failing feature tests**

Create `tests/Feature/SubmissionDeleteTest.php` with `RefreshDatabase` and `BuildsFormTestData`. Start with these cases:

1. `tsmcadmin` sees submissions from two different organizations and two different forms.
2. A normal org user receives 403 from `document.submissions.index`.
3. `form_id` filter includes only submissions for that form.
4. `org` filter includes only submissions for that organization.
5. `date_from` and `date_to` filter by `created_at` inclusively.

Use `assertSee()`/`assertDontSee()` on unique form titles and organization names. Do not assert against a hidden URL attribute or assume the UUID is rendered in the table.

Run:

```bash
php artisan test --filter=SubmissionDeleteTest
```

Expected: fail because the controller methods/view are not implemented yet.

- [ ] **Step 3: Implement `submissionsIndex(Request $request)`**

Add `use App\Models\Organization;` to `DocumentController` and implement the method after `showDocTable()`:

```php
public function submissionsIndex(Request $request)
{
    abort_unless(Auth::user()->username === 'tsmcadmin', 403);

    $request->validate([
        'form_id' => ['nullable', 'integer', 'exists:forms,id'],
        'org' => ['nullable', 'integer', 'exists:organizations,id'],
        'date_from' => ['nullable', 'date'],
        'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
    ]);

    $submissions = FormSubmissions::with(['getForm', 'organization', 'submittedByUser'])
        ->when($request->filled('form_id'), fn ($query) => $query->where('form_id', $request->integer('form_id')))
        ->when($request->filled('org'), fn ($query) => $query->where('org', (string) $request->integer('org')))
        ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date_from))
        ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date_to))
        ->orderByDesc('created_at')
        ->paginate(50)
        ->appends($request->query());

    $forms = Form::orderBy('title')->get(['id', 'title']);
    $organizations = Organization::orderBy('name')->get(['id', 'name']);

    return view('exportDocument.submissionsIndex', compact('submissions', 'forms', 'organizations'));
}
```

If the project style requires date objects for `whereDate`, normalize the validated values before building the query; keep the inclusive boundary behavior.

- [ ] **Step 4: Add a minimal Blade view**

Create `resources/views/exportDocument/submissionsIndex.blade.php` extending `layouts.app`. It must render:

- a heading `รายการแบบฟอร์มที่ส่งทั้งหมด`;
- a table with form title, organization name, submitter full name, created date, and action columns;
- `-` fallback for missing related records;
- an empty state `ไม่พบข้อมูล`;
- `{{ $submissions->links() }}`;
- no detail link that calls `document.submission.show` (see the scope decision above).

Use `optional($submission->getForm)->title`, `optional($submission->organization)->name`, and `optional($submission->submittedByUser)->full_name` to tolerate legacy rows with missing relations.

- [ ] **Step 5: Verify the index**

Run:

```bash
php artisan route:list --name=document.submissions
php artisan test --filter=SubmissionDeleteTest
```

Expected: two routes are registered and the five index/filter tests pass.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/DocumentController.php tests/Feature/SubmissionDeleteTest.php resources/views/exportDocument/submissionsIndex.blade.php
git commit -m "Add tsmcadmin submission list"
```

---

## Task 5: Implement and test the soft-delete action

**Files:**

- Modify: `app/Http/Controllers/DocumentController.php`
- Modify: `tests/Feature/SubmissionDeleteTest.php`

- [ ] **Step 1: Add failing delete tests**

Add these cases to `SubmissionDeleteTest`:

1. `test_tsmcadmin_can_soft_delete_a_submission`
   - create a submission with a value and history row;
   - call `delete(route('document.submissions.destroy', $submission->submission_id))` as `tsmcadmin`;
   - assert redirect back and session flash `ลบแบบฟอร์มที่ส่งแล้วเรียบร้อย`;
   - assert `assertSoftDeleted('form_submissions', ['id' => $submission->id])`;
   - assert its `form_submission_values` and `form_submission_histories` rows remain.
2. `test_non_tsmcadmin_cannot_delete_a_submission`
   - assert 403 and `deleted_at` remains null.
3. `test_deleted_submission_is_hidden_from_the_default_list`
   - delete one row, reload the index, and assert its unique form/submission marker is absent while another row remains.
4. `test_soft_deleted_submission_is_excluded_from_quarterly_report_count`
   - attach the admin fixture to an organization for the duration of this test;
   - assert the existing `Form::countFromSubmissionByQuarter()` returns 1 before deletion and 0 after deletion.

Run:

```bash
php artisan test --filter=SubmissionDeleteTest
```

Expected: the new cases fail before the controller implementation.

- [ ] **Step 2: Replace the `destroy()` stub**

```php
public function destroy(string $submission_id)
{
    abort_unless(Auth::user()->username === 'tsmcadmin', 403);

    $submission = FormSubmissions::where('submission_id', $submission_id)->firstOrFail();
    $submission->delete();

    return back()->with('success', 'ลบแบบฟอร์มที่ส่งแล้วเรียบร้อย');
}
```

The default `FormSubmissions` scope means a previously soft-deleted UUID returns 404 on a repeated delete. Do not call `forceDelete()` and do not delete child value/history rows.

- [ ] **Step 3: Run focused and full tests**

```bash
php artisan test --filter=SubmissionDeleteTest
php artisan test
```

Expected: all submission-delete tests pass; the full suite has no regression caused by the new global scope. If an unrelated pre-existing test fails, record the exact test and failure in this plan rather than weakening the new assertions.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/DocumentController.php tests/Feature/SubmissionDeleteTest.php
git commit -m "Soft delete duplicate form submissions"
```

---

## Task 6: Complete the production view

**Files:**

- Modify: `resources/views/exportDocument/submissionsIndex.blade.php`

- [ ] **Step 1: Add filters**

Add a GET form with:

- `form_id` select populated from `$forms`;
- `org` select populated from `$organizations`;
- `date_from` and `date_to` date inputs;
- submit button `กรอง` and a clear link back to `document.submissions.index`.

Preserve selected values with `request('form_id')`, `request('org')`, `request('date_from')`, and `request('date_to')`. Pagination must preserve all filters through the controller's `appends($request->query())`.

- [ ] **Step 2: Add the delete action**

Each row must have a Bootstrap-styled danger button that opens a confirmation modal with the message `ยืนยันการลบแบบฟอร์มนี้? การลบนี้จะไม่แสดงในหน้ารายงานอีกต่อไป`. The modal contains one shared HTML form targeting `route('document.submissions.destroy', $submission->submission_id)` with `@csrf` and `@method('DELETE')`; set the form action from the clicked row before submit. Keep this as a normal form/redirect flow—do not introduce an AJAX endpoint or delete state that must be synchronized in JavaScript.

- [ ] **Step 3: Render flash feedback and dates**

Show `session('success')` as a Bootstrap success alert. Render `created_at` with the project's existing Carbon Thai date convention, for example:

```blade
{{ (new Carbon\Carbon($submission->created_at))->thaidate('j F Y \\เวลา H:i:s') }}
```

Use escaped Blade output for form, organization, and submitter values.

- [ ] **Step 4: Verify the rendered page**

Run:

```bash
php artisan test --filter=SubmissionDeleteTest
```

Then manually verify filter submission, pagination, empty state, confirmation, redirect, and flash message in a browser.

- [ ] **Step 5: Commit**

```bash
git add resources/views/exportDocument/submissionsIndex.blade.php
git commit -m "Add submission filters and delete controls"
```

---

## Task 7: Add admin-only menu links

**Files:**

- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1: Add the desktop link**

Immediately after the first `allLoginHistoryPage` block, inside its existing `Auth::user()->username === 'tsmcadmin'` guard, add:

```blade
<li class="sidebar-item" id="allSubmissionsPage">
    <a href="{{ route('document.submissions.index') }}" class="sidebar-link">
        <i class="bi bi-file-earmark-text"></i>
        รายการแบบฟอร์มที่ส่งทั้งหมด
    </a>
</li>
```

- [ ] **Step 2: Add the mobile/duplicate link**

Repeat the same guarded item after the second `allLoginHistoryPage` block in the mobile menu. Confirm the two IDs do not collide with another element.

- [ ] **Step 3: Verify authorization behavior**

Run the feature test and manually confirm:

- `tsmcadmin` sees both menu links and can open `/document/submissions`;
- a normal user sees neither link and receives 403 on a direct GET;
- a guest is handled by the existing auth middleware before the controller gate.

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "Add admin submission list menu links"
```

---

## Task 8: End-to-end verification and feedback close-out

- [ ] **Step 1: Verify all Eloquent consumers**

Run:

```bash
rg -n "DB::table\(['\"]form_submissions|from\(['\"]form_submissions|FormSubmissions::|hasMany\(FormSubmissions::class" app tests
```

Confirm every report/export/dashboard path that should hide deleted submissions uses the `FormSubmissions` model or a relationship based on it. Do not add `withTrashed()` to existing reporting paths.

- [ ] **Step 2: Verify manually with seeded data**

1. Run `php artisan migrate` and use the existing seeded `tsmcadmin` account (`tsmcadmin` / `iddrivesadmin`) in a local environment.
2. Create two submissions for the same form, or identify two existing submissions from different organizations.
3. Open `/document/submissions`; verify form, organization, submitter, date, filters, and pagination.
4. Delete one row, confirm the prompt, and verify redirect + success message.
5. Verify the deleted row is absent from `/document/submissions`, the form's `document.table`, `/submission-count`, and any applicable mandatory report output.
6. Verify the row and its values/history still exist with `FormSubmissions::withTrashed()`/database inspection.
7. Log in as a normal org user and verify direct list/delete requests are forbidden.

- [ ] **Step 3: Update customer feedback**

After the focused tests, full suite, and manual verification pass, change the `2026-09-23` checkbox in `docs/customer-feedback.md` from `[ ]` to `[x]` and retain the links to the design spec and this implementation plan.

- [ ] **Step 4: Final status**

Record the commands run and their results in the implementation PR/commit description. The feature is complete only when:

- migration and rollback are valid;
- only `tsmcadmin` can list/delete;
- filters and pagination work;
- delete sets `deleted_at` without deleting child data;
- deleted rows disappear from default reports through Eloquent global scope;
- focused and full tests pass;
- the menu is hidden from normal users;
- customer feedback is marked done.
