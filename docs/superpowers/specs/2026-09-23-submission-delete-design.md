# Submission Delete (ลบใบที่กรอกซ้ำ) — Design Spec

Addresses the 2026-09-23 customer-feedback item in [customer-feedback.md](../../customer-feedback.md):
a customer accidentally submitted a form twice (กรอกข้อมูลซ้ำ) and asked to have the duplicate removed.
There is currently no delete path anywhere for a `FormSubmissions` row — [DocumentController::destroy()](../../../app/Http/Controllers/DocumentController.php)
is an empty stub with no route — so a duplicate submission is permanent and keeps counting in every
report/export/dashboard that reads `form_submissions`.

## Goal and boundaries

A **submission list page** for `tsmcadmin` (the hardcoded superadmin account) listing every form
submission across every form and every org, with a delete action per row, so support can remove a
duplicate a customer reports.

The feature deliberately does not:

- give org users or org admins a delete button — only `tsmcadmin` (same ad hoc
  `Auth::user()->username === 'tsmcadmin'` check used elsewhere in this codebase, e.g.
  `HomeController.php:115`, `FormController.php:134`) can see this page or delete;
- keep a separate audit log of who deleted what — out of scope per explicit customer/dev decision;
- offer an in-app "restore" button — soft delete only protects against accidental permanent loss at the
  DB level (recoverable via `withTrashed()`/tinker if ever needed), the UI itself is delete-only;
- cascade-delete child sub-form submissions (`parent_submission_id`) — they are left as-is, same as the
  existing `nullOnDelete` FK behavior for a hard delete would leave them;
- scope the list to one form/org by default — it lists everything, with filters to narrow down.

## 1. Soft delete — migration + model

New migration: add nullable `deleted_at` timestamp to `form_submissions`.

`FormSubmissions` model gains `use SoftDeletes;` (Illuminate\Database\Eloquent\SoftDeletes). No other
model needs it — `form_submission_values` and `form_submission_histories` rows for a soft-deleted
submission are left untouched (they simply become unreachable through the parent's default-scoped
relations, and stay available if the row is ever restored).

Verified: every place that reads `form_submissions` in this codebase goes through the `FormSubmissions`
Eloquent model (`Form.php`'s `countFromSubmissionByQuarter`/etc., `MandatoryReportService.php`,
`ExcelController.php`, `DashboardController.php`, `ApiController.php`, `WorkRecordController.php`) — none
use `DB::table('form_submissions')` or `withTrashed()`/`onlyTrashed()`. Adding `SoftDeletes` therefore
excludes deleted rows from all reports, exports, and dashboards automatically, with zero changes to those
files.

## 2. Routes

Inside the existing `auth` middleware group in `routes/web.php`, next to the other `document.*` routes:

```php
Route::get('/document/submissions', [DocumentController::class, 'submissionsIndex'])->name('document.submissions.index');
Route::delete('/document/submissions/{submission_id}', [DocumentController::class, 'destroy'])->name('document.submissions.destroy');
```

Both actions abort with 403 unless `Auth::user()->username === 'tsmcadmin'`.

## 3. Controller — `DocumentController`

`submissionsIndex(Request $request)`:

- Base query: `FormSubmissions::with(['getForm', 'getUser', 'getVehicle'])`, joined/filterable by
  `form_id` and `org` (resolve org display name via `Organization::where('org_id', ...)`).
- Optional filters from query string: `form_id`, `org` (org_id), `date_from`, `date_to` (filter on
  `created_at`). Dropdowns for form and org are populated from `Form::all()` / `Organization::all()`.
- Paginate (`->orderByDesc('created_at')->paginate(50)`), preserving filters in pagination links.
- Returns a new view, `exportDocument.submissionsIndex` (grouped with the other admin-facing document
  views under `resources/views/exportDocument/`).

`destroy(string $submission_id)` (replaces the current empty stub at
[DocumentController.php:242-245](../../../app/Http/Controllers/DocumentController.php:242)):

- `$submission = FormSubmissions::where('submission_id', $submission_id)->firstOrFail();`
- `$submission->delete();` — soft delete (sets `deleted_at`); DB stays intact for values/history.
- Redirect back to `document.submissions.index` (preserving filters via `back()`) with a Thai flash
  message: `"ลบแบบฟอร์มที่ส่งแล้วเรียบร้อย"`.

## 4. View — `exportDocument/submissionsIndex.blade.php`

Same Blade + Bootstrap 5 style as `exportDocument/filterData.blade.php` / `submissionCount.blade.php`.

- Filter bar: form dropdown, org dropdown, date-from/date-to, "ค้นหา" button (GET, preserves query string).
- Table columns: ฟอร์ม (form title, links to `document.submission.show`), องค์กร, ผู้ส่ง (submitted-by
  name, falls back to "-" if null), วันที่ส่ง (`created_at`, Thai Buddhist date via `thaidate`). No status
  column — `form_submissions.status` (1/2/3) is set on create but never read or updated anywhere in this
  codebase today, so it carries no real meaning to show.
- Each row has a "ลบ" button that opens a confirm modal ("ยืนยันการลบแบบฟอร์มนี้? การลบนี้จะไม่แสดงในหน้า
  รายงานอีกต่อไป") before submitting a `DELETE` form to `document.submissions.destroy`.
- Pagination links at the bottom (`{{ $submissions->links() }}`).
- Menu entry "รายชื่อแบบฟอร์มที่ส่งทั้งหมด" (or similar) added to `layouts/app.blade.php`, visible only
  when `Auth::user()->username === 'tsmcadmin'`, next to other admin-only links.

## 5. Testing — `tests/Feature/SubmissionDeleteTest.php`

1. `tsmcadmin` can load the list page and see submissions across orgs/forms.
2. A non-`tsmcadmin` user gets 403 on both the list page and the delete route.
3. `tsmcadmin` deleting a submission sets `deleted_at` (soft delete), row no longer appears in the list
   by default, and `FormSubmissionValue`/`FormSubmissionHistory` rows for it still exist in the DB.
4. A soft-deleted submission is excluded from `Form::countFromSubmissionByQuarter()` (or an equivalent
   report query) — proves the global scope suppresses it from reporting.
5. Filtering the list by `form_id` and by `org` returns only matching rows.
