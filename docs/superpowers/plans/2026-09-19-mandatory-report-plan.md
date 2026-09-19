# Mandatory Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the "รายงานภาคบังคับ" (Mandatory report) page that shows, per quarter, the tsmthai
item-list counts (times + distinct units) computed from form submissions, driven by admin-configured
report rules attached to forms that survive form cloning.

**Architecture:** A new `form_report_rules` table + `FormReportRule` model holds per-form counting rules.
A new `FormReportRuleController` provides CRUD for rules on a form's settings page (mirrors the existing
form-chain settings pattern). `FormController::duplicate` is extended to copy rules when a form is cloned,
remapping field ids. A new `MandatoryReportService` computes the report by combining the static item
catalog (`config/mandatory_report.php`) with rule-driven queries against `form_submissions` /
`form_submission_values`. A new `MandatoryReportController` + view renders the read-only report page.

**Tech Stack:** Laravel 11, Eloquent, Blade + Bootstrap 5, PHPUnit feature tests, MySQL.

Spec: [docs/superpowers/specs/2026-09-19-mandatory-report-design.md](../specs/2026-09-19-mandatory-report-design.md)

> **Implementation status (2026-09-19):** Complete in the working tree. The implementation includes the
> catalog, rule persistence and CRUD UI, report aggregation, clone propagation, report page, routes, menu
> links, and feature coverage. The focused suite passes; the full suite has one pre-existing failure in
> Tests/Feature/ExampleTest because the unauthenticated root route returns 302 instead of the test's expected
> 200.

---

## File Structure

New files:
- `config/mandatory_report.php` — static item catalog (sections/items/labels/units)
- `database/migrations/2026_09_19_000001_create_form_report_rules_table.php`
- `app/Models/FormReportRule.php`
- `app/Http/Controllers/FormReportRuleController.php` — CRUD for rules on one form
- `app/Services/MandatoryReportService.php` — counting logic
- `app/Http/Controllers/MandatoryReportController.php` — report page
- `resources/views/form/formReportRules.blade.php` — rule settings page
- `resources/views/exportDocument/mandatoryReport.blade.php` — report page
- `tests/Feature/MandatoryReportRuleTest.php` — rule CRUD + clone tests
- `tests/Feature/MandatoryReportTest.php` — counting tests

Modified files:
- `routes/web.php` — new routes for rules CRUD + report page
- `app/Models/Form.php` — add `reportRules()` relation
- `app/Models/FormField.php` — add `reportRules()` / `distinctReportRules()` relations (for cascade
  awareness in tests only; not strictly required by app code but documents the relationship)
- `app/Http/Controllers/FormController.php` — `duplicate()` copies report rules; new private helper for
  guarding rule access (`reportRuleForm()`), reusing the same org-visibility pattern as `chainForm()`
- `resources/views/form/formTable.blade.php` — add "รายงานภาคบังคับ" button next to the chain button
- `resources/views/layouts/app.blade.php` — add "รายงานภาคบังคับ" menu link (desktop + mobile)
- `docs/customer-feedback.md` — mark the item done once complete (final task)

---

## Task 1: Item catalog config

**Files:**
- Create: `config/mandatory_report.php`

- [ ] **Step 1: Write the config file**

```php
<?php

return [
    'sections' => [
        'vehicle' => [
            'label' => 'การจัดการตัวรถ',
            'items' => [
                'vehicle_maintenance_plan' => ['label' => '1. การจัดทำแผนบำรุงรักษารถ', 'unit' => 'คัน'],
                'vehicle_readiness_check' => ['label' => '2. การตรวจความพร้อมของรถและอุปกรณ์', 'unit' => 'คัน'],
                'vehicle_safety_equipment_check' => ['label' => '3. การตรวจอุปกรณ์และเครื่องมือเครื่องใช้ที่จำเป็นที่เกี่ยวข้องกับความปลอดภัย', 'unit' => 'คัน'],
            ],
        ],
        'crew' => [
            'label' => 'การจัดการ ผู้ประจำรถ',
            'items' => [
                'crew_duty_assignment' => ['label' => '1. การกำหนดหน้าที่และความรับผิดชอบของผู้ประจำรถ', 'unit' => 'คน'],
                'crew_driver_work_plan' => ['label' => '2. การจัดทำแผนการทำงานของผู้ขับรถ', 'unit' => 'คน'],
                'crew_training_plan' => ['label' => '3. การจัดทำแผนการอบรมผู้ประจำรถ', 'unit' => 'คน'],
                'crew_health_check_plan' => ['label' => '4. การจัดทำแผนการตรวจสุขภาพผู้ประจำรถ', 'unit' => 'คน'],
                'crew_alcohol_test' => ['label' => '5. การตรวจวัดระดับแอลกอฮอล์ของผู้ประจำรถ', 'unit' => 'คน'],
                'crew_drug_test' => ['label' => '6. การสุ่มตรวจสารเสพติดในร่างกายของผู้ประจำรถ', 'unit' => 'คน'],
                'crew_fitness_check' => ['label' => '7. การตรวจความพร้อมด้านร่างกายและจิตใจของผู้ขับรถก่อนออกเดินทาง', 'unit' => 'คน'],
            ],
        ],
        'operation' => [
            'label' => 'การจัดการ การเดินรถ',
            'items' => [
                'operation_trip_plan' => ['label' => '1. การจัดทำแผนการเดินทาง', 'unit' => 'เส้นทาง'],
                'operation_speed_control' => ['label' => '2. การตรวจสอบและจัดการการใช้ความเร็วของรถ', 'unit' => 'คัน'],
                'operation_route_situation_check' => ['label' => '3. การตรวจสอบสถานการณ์การเดินทาง', 'unit' => 'เส้นทาง'],
                'operation_transport_data_record' => ['label' => '4. การจัดเก็บข้อมูลการดำเนินการขนส่ง', 'unit' => ''],
            ],
        ],
        'loading' => [
            'label' => 'การจัดการการบรรทุก และการโดยสาร',
            'items' => [
                'loading_operation_manual' => ['label' => '1. การจัดทำคู่มือการปฏิบัติงาน', 'unit' => 'เล่ม'],
                'loading_safety_check' => ['label' => '2. การตรวจสอบความปลอดภัยในการบรรทุกคนโดยสาร และการบรรทุกสัตว์หรือสิ่งของ', 'unit' => 'คัน'],
            ],
        ],
        'control' => [
            'label' => 'การควบคุม กำกับดูแล',
            'items' => [
                'control_emergency_plan' => ['label' => '1. การจัดทำแผนรับมือกรณีเกิดอุบัติเหตุหรือเหตุฉุกเฉิน', 'unit' => 'เล่ม'],
                'control_emergency_coordination' => ['label' => '2. การบริหารจัดการและติดต่อประสานงานกรณีเกิดเหตุฉุกเฉิน', 'unit' => 'หน่วยงาน'],
                'control_accident_report' => ['label' => '3. การจัดทำรายงานอุบัติเหตุ วิเคราะห์ข้อมูลอุบัติเหตุ วิเคราะห์และประเมินผลการจัดการความปลอดภัยในการขนส่ง', 'unit' => 'เล่ม'],
            ],
        ],
    ],
];
```

- [ ] **Step 2: Verify it loads**

Run: `php artisan tinker --execute="echo count(config('mandatory_report.sections')).PHP_EOL; echo config('mandatory_report.sections.crew.items.crew_alcohol_test.label').PHP_EOL;"`

Expected: `5` then `5. การตรวจวัดระดับแอลกอฮอล์ของผู้ประจำรถ`

- [ ] **Step 3: Commit**

```bash
git add config/mandatory_report.php
git commit -m "Add mandatory report item catalog config

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: `form_report_rules` migration and model

**Files:**
- Create: `database/migrations/2026_09_19_000001_create_form_report_rules_table.php`
- Create: `app/Models/FormReportRule.php`
- Modify: `app/Models/Form.php`

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
        Schema::create('form_report_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->string('item_code', 64);
            $table->foreignId('condition_field_id')->nullable()->constrained('form_fields')->nullOnDelete();
            $table->json('condition_values')->nullable();
            $table->enum('distinct_by', ['user', 'vehicle', 'field', 'none'])->default('none');
            $table->foreignId('distinct_field_id')->nullable()->constrained('form_fields')->nullOnDelete();
            $table->timestamps();

            $table->unique(['form_id', 'item_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_report_rules');
    }
};
```

Note: `condition_field_id` / `distinct_field_id` use `nullOnDelete()` rather than `cascadeOnDelete()` —
deleting a single field should not delete the whole rule row (the settings page needs to detect and warn
about the dangling reference per the spec, not silently lose the rule).

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `Migrating: 2026_09_19_000001_create_form_report_rules_table` then `Migrated:` with no errors.

- [ ] **Step 3: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormReportRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'item_code',
        'condition_field_id',
        'condition_values',
        'distinct_by',
        'distinct_field_id',
    ];

    protected $casts = [
        'condition_values' => 'array',
    ];

    public function form()
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    public function conditionField()
    {
        return $this->belongsTo(FormField::class, 'condition_field_id')->withTrashed();
    }

    public function distinctField()
    {
        return $this->belongsTo(FormField::class, 'distinct_field_id')->withTrashed();
    }
}
```

- [ ] **Step 4: Add the relation on `Form`**

In `app/Models/Form.php`, add next to `hasPosition()`:

```php
    public function reportRules()
    {
        return $this->hasMany(FormReportRule::class, 'form_id');
    }
```

- [ ] **Step 5: Verify with tinker**

Run: `php artisan tinker --execute="echo App\Models\FormReportRule::query()->toSql().PHP_EOL;"`
Expected: no error, prints a `select * from ...` string.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_19_000001_create_form_report_rules_table.php app/Models/FormReportRule.php app/Models/Form.php
git commit -m "Add form_report_rules table and model

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 3: Test helper additions

**Files:**
- Modify: `tests/Concerns/BuildsFormTestData.php`

The existing `makeField()` helper doesn't support field options (needed to test select-field conditions).
Add a small helper for that, plus a submission+value builder to keep tests short.

- [ ] **Step 1: Add helpers to the trait**

Add these methods to `tests/Concerns/BuildsFormTestData.php` (inside the `BuildsFormTestData` trait, after
`makeField`):

```php
    private function addFieldOption(FormField $field, string $value): FieldOption
    {
        return \App\Models\FieldOption::create([
            'field_id' => $field->id,
            'value' => $value,
        ]);
    }

    private function makeSubmission(Form $form, Organization $org, array $overrides = []): \App\Models\FormSubmissions
    {
        return \App\Models\FormSubmissions::create(array_merge([
            'submission_id' => Str::uuid(),
            'form_id' => $form->id,
            'submitted_by' => 1,
            'org' => (string) $org->id,
        ], $overrides));
    }

    private function setSubmissionValue(\App\Models\FormSubmissions $submission, FormField $field, string $value): \App\Models\FormSubmissionValue
    {
        return \App\Models\FormSubmissionValue::create([
            'submission_id' => $submission->id,
            'field_id' => $field->id,
            'value' => $value,
            'submitted_by' => $submission->submitted_by,
        ]);
    }
```

`makeSubmission` defaults `submitted_by` to `1` because these counting tests don't exercise auth on the
submission row itself (unlike chain tests, which need a real acting user); pass `submitted_by` explicitly
via `$overrides` when a real user id matters (e.g. `user_id` for `distinct_by = user` tests).

- [ ] **Step 2: Verify the trait still parses**

Run: `php -l tests/Concerns/BuildsFormTestData.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add tests/Concerns/BuildsFormTestData.php
git commit -m "Add report-rule test helpers to BuildsFormTestData

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 4: `MandatoryReportService` — basic counting (no conditions)

**Files:**
- Create: `app/Services/MandatoryReportService.php`
- Test: `tests/Feature/MandatoryReportTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MandatoryReportTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FormReportRule;
use App\Services\MandatoryReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFormTestData;
use Tests\TestCase;

class MandatoryReportTest extends TestCase
{
    use RefreshDatabase;
    use BuildsFormTestData;

    private function service(): MandatoryReportService
    {
        return app(MandatoryReportService::class);
    }

    public function test_rule_with_no_condition_counts_every_submission_and_distinct_users(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org, ['select_user' => true]);

        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'crew_duty_assignment',
            'distinct_by' => 'user',
        ]);

        $userA = $this->makeOrgUser($org, [$form->id]);
        $userB = $this->makeOrgUser($org, [$form->id]);

        $this->makeSubmission($form, $org, ['user_id' => $userA->id]);
        $this->makeSubmission($form, $org, ['user_id' => $userA->id]);
        $this->makeSubmission($form, $org, ['user_id' => $userB->id]);

        $result = $this->service()->build((string) $org->id, now()->quarter, now()->year);

        $item = $result['crew']['items']['crew_duty_assignment'];
        $this->assertSame(3, $item['times']);
        $this->assertSame(2, $item['units']);
        $this->assertSame([$form->title], $item['sources']);
    }

    public function test_item_with_no_rule_reports_null(): void
    {
        $org = $this->makeOrg();

        $result = $this->service()->build((string) $org->id, now()->quarter, now()->year);

        $item = $result['crew']['items']['crew_duty_assignment'];
        $this->assertNull($item['times']);
        $this->assertNull($item['units']);
        $this->assertSame([], $item['sources']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MandatoryReportTest`
Expected: FAIL — `Class "App\Services\MandatoryReportService" not found`

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\FormReportRule;
use App\Models\FormSubmissions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MandatoryReportService
{
    public function build(?string $orgId, int $quarter, int $year): array
    {
        $catalog = config('mandatory_report.sections');
        $result = [];

        foreach ($catalog as $sectionKey => $section) {
            $items = [];
            foreach ($section['items'] as $itemCode => $item) {
                $items[$itemCode] = [
                    'label' => $item['label'],
                    'unit' => $item['unit'],
                    'times' => null,
                    'units' => null,
                    'sources' => [],
                ];
            }
            $result[$sectionKey] = ['label' => $section['label'], 'items' => $items];
        }

        $knownItemCodes = collect($catalog)->flatMap(fn ($section) => array_keys($section['items']));

        $rules = FormReportRule::whereIn('item_code', $knownItemCodes)
            ->whereHas('form', function ($query) use ($orgId) {
                $query->where('is_sub_form', false);
                $query->where(function ($orgQuery) use ($orgId) {
                    $orgQuery->where('is_default', true);
                    if ($orgId !== null) {
                        $orgQuery->orWhere('org', $orgId);
                    }
                });
            })
            ->with(['form', 'conditionField', 'distinctField'])
            ->get();

        [$start, $end] = $this->quarterBounds($quarter, $year);

        // itemCode => ['submissionIds' => Set<int>, 'unitKeys' => Set<string>, 'sources' => Set<string>]
        $aggregate = [];

        foreach ($rules as $rule) {
            $section = $this->findSectionKeyForItem($catalog, $rule->item_code);
            if ($section === null) {
                continue;
            }

            $matches = $this->matchingSubmissions($rule, $orgId, $start, $end);

            $aggregate[$rule->item_code] ??= ['submissionIds' => [], 'unitKeys' => [], 'sources' => []];
            $aggregate[$rule->item_code]['sources'][$rule->form->title] = true;

            foreach ($matches as $row) {
                $aggregate[$rule->item_code]['submissionIds'][$row->submission_id] = true;
                if ($row->unit_key !== null && $row->unit_key !== '') {
                    $aggregate[$rule->item_code]['unitKeys'][$row->unit_key] = true;
                }
            }
        }

        foreach ($aggregate as $itemCode => $data) {
            $sectionKey = $this->findSectionKeyForItem($catalog, $itemCode);
            $result[$sectionKey]['items'][$itemCode]['times'] = count($data['submissionIds']);
            $result[$sectionKey]['items'][$itemCode]['units'] = count($data['unitKeys']) > 0 || $this->anyRuleCountsUnits($rules, $itemCode)
                ? count($data['unitKeys'])
                : null;
            $result[$sectionKey]['items'][$itemCode]['sources'] = array_keys($data['sources']);
        }

        return $result;
    }

    private function anyRuleCountsUnits($rules, string $itemCode): bool
    {
        return $rules->where('item_code', $itemCode)->contains(fn ($rule) => $rule->distinct_by !== 'none');
    }

    private function matchingSubmissions(FormReportRule $rule, ?string $orgId, Carbon $start, Carbon $end)
    {
        $query = FormSubmissions::query()
            ->where('form_submissions.form_id', $rule->form_id)
            ->when($orgId !== null, fn ($q) => $q->where('form_submissions.org', $orgId))
            ->whereBetween('form_submissions.created_at', [$start, $end]);

        if ($rule->condition_field_id !== null) {
            if ($rule->conditionField === null) {
                // Condition field was deleted — rule contributes nothing.
                return collect();
            }

            $query->whereExists(function ($sub) use ($rule) {
                $sub->selectRaw('1')
                    ->from('form_submission_values')
                    ->whereColumn('form_submission_values.submission_id', 'form_submissions.id')
                    ->where('form_submission_values.field_id', $rule->condition_field_id)
                    ->where('form_submission_values.value', '!=', '')
                    ->whereNotNull('form_submission_values.value');

                if (!empty($rule->condition_values)) {
                    $sub->whereIn('form_submission_values.value', $rule->condition_values);
                }
            });
        }

        if ($rule->distinct_by === 'field') {
            if ($rule->distinctField === null) {
                // Distinct field deleted: still count times, but no unit key.
                return $query->get(['form_submissions.id as submission_id'])
                    ->map(fn ($row) => (object) ['submission_id' => $row->submission_id, 'unit_key' => null]);
            }

            $query->leftJoin('form_submission_values as distinct_values', function ($join) use ($rule) {
                $join->on('distinct_values.submission_id', '=', 'form_submissions.id')
                    ->where('distinct_values.field_id', '=', $rule->distinct_field_id);
            });

            return $query->get([
                'form_submissions.id as submission_id',
                DB::raw('TRIM(distinct_values.value) as unit_key'),
            ]);
        }

        if ($rule->distinct_by === 'user') {
            return $query->get(['form_submissions.id as submission_id', 'form_submissions.user_id as unit_key']);
        }

        if ($rule->distinct_by === 'vehicle') {
            return $query->get(['form_submissions.id as submission_id', 'form_submissions.vehicle_id as unit_key']);
        }

        return $query->get(['form_submissions.id as submission_id'])
            ->map(fn ($row) => (object) ['submission_id' => $row->submission_id, 'unit_key' => null]);
    }

    private function quarterBounds(int $quarter, int $year): array
    {
        $start = Carbon::create($year, 1, 1)->startOfYear()->addMonths(($quarter - 1) * 3)->startOfDay();
        $end = (clone $start)->addMonths(3)->subDay()->endOfDay();

        return [$start, $end];
    }

    private function findSectionKeyForItem(array $catalog, string $itemCode): ?string
    {
        foreach ($catalog as $sectionKey => $section) {
            if (array_key_exists($itemCode, $section['items'])) {
                return $sectionKey;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MandatoryReportTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/MandatoryReportService.php tests/Feature/MandatoryReportTest.php
git commit -m "Add MandatoryReportService with basic times/units counting

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 5: Service — condition field filtering (select-field "done" values)

**Files:**
- Modify: `tests/Feature/MandatoryReportTest.php`
- Modify: `app/Services/MandatoryReportService.php` (already supports this from Task 4 — this task is
  test-only, to lock in the behavior)

- [ ] **Step 1: Write the failing test**

Add to `MandatoryReportTest`:

```php
    public function test_condition_field_only_counts_submissions_with_matching_value(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org, ['select_user' => true]);
        $alcoholField = $this->makeField($form, 'การใช้เครื่องตรวจวัดแอลกอฮอล์', 'select');
        $this->addFieldOption($alcoholField, 'ตรวจ');
        $this->addFieldOption($alcoholField, 'ไม่ได้ตรวจ');

        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'crew_alcohol_test',
            'condition_field_id' => $alcoholField->id,
            'condition_values' => ['ตรวจ'],
            'distinct_by' => 'user',
        ]);

        $userA = $this->makeOrgUser($org, [$form->id]);
        $userB = $this->makeOrgUser($org, [$form->id]);

        $tested = $this->makeSubmission($form, $org, ['user_id' => $userA->id]);
        $this->setSubmissionValue($tested, $alcoholField, 'ตรวจ');

        $untested = $this->makeSubmission($form, $org, ['user_id' => $userB->id]);
        $this->setSubmissionValue($untested, $alcoholField, 'ไม่ได้ตรวจ');

        $result = $this->service()->build((string) $org->id, now()->quarter, now()->year);

        $item = $result['crew']['items']['crew_alcohol_test'];
        $this->assertSame(1, $item['times']);
        $this->assertSame(1, $item['units']);
    }

    public function test_condition_field_with_no_condition_values_counts_any_non_empty_answer(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $notesField = $this->makeField($form, 'บันทึกเพิ่มเติม', 'text');

        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'operation_transport_data_record',
            'condition_field_id' => $notesField->id,
            'distinct_by' => 'none',
        ]);

        $answered = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($answered, $notesField, 'มีบันทึก');

        $blank = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($blank, $notesField, '');

        $result = $this->service()->build((string) $org->id, now()->quarter, now()->year);

        $item = $result['operation']['items']['operation_transport_data_record'];
        $this->assertSame(1, $item['times']);
        $this->assertNull($item['units']);
    }
```

- [ ] **Step 2: Run test to verify it fails or passes**

Run: `php artisan test --filter=MandatoryReportTest`
Expected: The two new tests should already PASS given Task 4's implementation (the `whereExists` +
`condition_values` logic was written in Task 4). If either fails, fix `matchingSubmissions()` in
`app/Services/MandatoryReportService.php` so that:
- a non-empty `condition_values` array restricts to those exact values;
- an empty/null `condition_values` with a `condition_field_id` set only requires the value to be
  non-empty (already handled by the `whereNotNull` + `!= ''` clause).

- [ ] **Step 3: Run full test file to confirm no regressions**

Run: `php artisan test --filter=MandatoryReportTest`
Expected: PASS (4 tests)

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/MandatoryReportTest.php app/Services/MandatoryReportService.php
git commit -m "Test condition-field filtering in MandatoryReportService

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 6: Service — multiple forms feeding one item, org/quarter isolation, field-based distinct

**Files:**
- Modify: `tests/Feature/MandatoryReportTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `MandatoryReportTest`:

```php
    public function test_two_forms_feeding_the_same_item_combine_times_and_dedupe_units(): void
    {
        $org = $this->makeOrg();
        $busForm = $this->makeForm($org, ['select_user' => true, 'title' => 'ตรวจความพร้อมรถโดยสาร']);
        $truckForm = $this->makeForm($org, ['select_user' => true, 'title' => 'ตรวจความพร้อมรถบรรทุก']);

        FormReportRule::create(['form_id' => $busForm->id, 'item_code' => 'vehicle_readiness_check', 'distinct_by' => 'user']);
        FormReportRule::create(['form_id' => $truckForm->id, 'item_code' => 'vehicle_readiness_check', 'distinct_by' => 'user']);

        $sharedUser = $this->makeOrgUser($org, [$busForm->id, $truckForm->id]);
        $this->makeSubmission($busForm, $org, ['user_id' => $sharedUser->id]);
        $this->makeSubmission($truckForm, $org, ['user_id' => $sharedUser->id]);

        $result = $this->service()->build((string) $org->id, now()->quarter, now()->year);

        $item = $result['vehicle']['items']['vehicle_readiness_check'];
        $this->assertSame(2, $item['times']);
        $this->assertSame(1, $item['units']);
        $this->assertEqualsCanonicalizing(['ตรวจความพร้อมรถโดยสาร', 'ตรวจความพร้อมรถบรรทุก'], $item['sources']);
    }

    public function test_submissions_outside_quarter_or_other_org_are_excluded(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $form = $this->makeForm($orgA);

        FormReportRule::create(['form_id' => $form->id, 'item_code' => 'crew_duty_assignment', 'distinct_by' => 'none']);

        $this->makeSubmission($form, $orgA, ['created_at' => now()->startOfYear()->subDay()]);
        $this->makeSubmission($form, $orgB);
        $inQuarter = $this->makeSubmission($form, $orgA);

        $result = $this->service()->build((string) $orgA->id, now()->quarter, now()->year);

        $this->assertSame(1, $result['crew']['items']['crew_duty_assignment']['times']);
    }

    public function test_distinct_by_field_counts_trimmed_unique_text_values(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $routeField = $this->makeField($form, 'เส้นทาง', 'text');

        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'operation_trip_plan',
            'distinct_by' => 'field',
            'distinct_field_id' => $routeField->id,
        ]);

        $first = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($first, $routeField, 'กรุงเทพ-เชียงใหม่');

        $second = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($second, $routeField, ' กรุงเทพ-เชียงใหม่ ');

        $third = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($third, $routeField, 'กรุงเทพ-ขอนแก่น');

        $result = $this->service()->build((string) $org->id, now()->quarter, now()->year);

        $item = $result['operation']['items']['operation_trip_plan'];
        $this->assertSame(3, $item['times']);
        $this->assertSame(2, $item['units']);
    }
```

- [ ] **Step 2: Run tests to verify status**

Run: `php artisan test --filter=MandatoryReportTest`
Expected: These should PASS given Task 4's implementation. If `test_distinct_by_field_...` fails because
`TRIM()` isn't applied consistently (e.g. sqlite vs mysql testing driver differences), check the test DB
connection in `phpunit.xml` — if it's sqlite, `TRIM()` is supported natively, so this should work
unmodified. If a failure occurs, fix by wrapping the raw SQL `TRIM(...)` in `DB::raw` consistently (already
done in Task 4's implementation).

- [ ] **Step 3: Confirm all pass**

Run: `php artisan test --filter=MandatoryReportTest`
Expected: PASS (7 tests total)

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/MandatoryReportTest.php
git commit -m "Test multi-form aggregation, org/quarter isolation, and field-based distinct counting

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 7: Service — deleted condition field yields zero, not "count everything"

**Files:**
- Modify: `tests/Feature/MandatoryReportTest.php`

This is a correctness safeguard: `whereHas('form', ...)` in Task 4 doesn't filter out rules whose
condition field was soft-deleted — it just skips the `whereExists` requirement if care isn't taken. Verify
explicitly.

- [ ] **Step 1: Write the failing test**

Add to `MandatoryReportTest`:

```php
    public function test_rule_with_soft_deleted_condition_field_counts_zero(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $conditionField = $this->makeField($form, 'ตรวจสารเสพติด', 'select');
        $this->addFieldOption($conditionField, 'ตรวจ');

        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'crew_drug_test',
            'condition_field_id' => $conditionField->id,
            'condition_values' => ['ตรวจ'],
            'distinct_by' => 'none',
        ]);

        $submission = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($submission, $conditionField, 'ตรวจ');

        $conditionField->delete(); // soft delete

        $result = $this->service()->build((string) $org->id, now()->quarter, now()->year);

        $item = $result['crew']['items']['crew_drug_test'];
        $this->assertSame(0, $item['times']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_rule_with_soft_deleted_condition_field_counts_zero`
Expected: FAIL — because `FormReportRule::conditionField()` uses `withTrashed()`, so `$rule->conditionField`
is still populated even after soft delete, meaning the current `matchingSubmissions()` check
`if ($rule->conditionField === null)` never triggers and the rule falls through to normal counting
(counting 1, not 0).

- [ ] **Step 3: Fix the service**

In `app/Services/MandatoryReportService.php`, change the check inside `matchingSubmissions()` from:

```php
            if ($rule->conditionField === null) {
                // Condition field was deleted — rule contributes nothing.
                return collect();
            }
```

to:

```php
            if ($rule->conditionField === null || $rule->conditionField->trashed()) {
                // Condition field was deleted — rule contributes nothing.
                return collect();
            }
```

And similarly for the distinct-field-deleted branch, change:

```php
        if ($rule->distinct_by === 'field') {
            if ($rule->distinctField === null) {
```

to:

```php
        if ($rule->distinct_by === 'field') {
            if ($rule->distinctField === null || $rule->distinctField->trashed()) {
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MandatoryReportTest`
Expected: PASS (8 tests total)

- [ ] **Step 5: Commit**

```bash
git add app/Services/MandatoryReportService.php tests/Feature/MandatoryReportTest.php
git commit -m "Fix: soft-deleted condition field must zero out its rule, not count everything

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 8: Rule settings controller — list, create, delete

**Files:**
- Create: `app/Http/Controllers/FormReportRuleController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MandatoryReportRuleTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MandatoryReportRuleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FormReportRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFormTestData;
use Tests\TestCase;

class MandatoryReportRuleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsFormTestData;

    public function test_admin_can_view_rule_settings_page_for_own_org_form(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $admin = $this->makeOrgUser($org);

        $response = $this->actingAs($admin)->get(route('form.report-rules.edit', $form->form_id));

        $response->assertOk();
        $response->assertViewIs('form.formReportRules');
    }

    public function test_creating_a_rule_with_no_condition_field(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org, ['select_user' => true]);
        $admin = $this->makeOrgUser($org);

        $response = $this->actingAs($admin)->postJson(route('form.report-rules.store', $form->form_id), [
            'item_code' => 'crew_duty_assignment',
            'distinct_by' => 'user',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('form_report_rules', [
            'form_id' => $form->id,
            'item_code' => 'crew_duty_assignment',
            'distinct_by' => 'user',
        ]);
    }

    public function test_creating_a_duplicate_item_code_on_the_same_form_is_rejected(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $admin = $this->makeOrgUser($org);

        FormReportRule::create(['form_id' => $form->id, 'item_code' => 'crew_duty_assignment', 'distinct_by' => 'none']);

        $response = $this->actingAs($admin)->postJson(route('form.report-rules.store', $form->form_id), [
            'item_code' => 'crew_duty_assignment',
            'distinct_by' => 'none',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('form_report_rules', 1);
    }

    public function test_condition_field_from_another_form_is_rejected(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $otherForm = $this->makeForm($org);
        $foreignField = $this->makeField($otherForm, 'ช่องฟอร์มอื่น');
        $admin = $this->makeOrgUser($org);

        $response = $this->actingAs($admin)->postJson(route('form.report-rules.store', $form->form_id), [
            'item_code' => 'crew_duty_assignment',
            'condition_field_id' => $foreignField->id,
            'distinct_by' => 'none',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('form_report_rules', 0);
    }

    public function test_deleting_a_rule(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $admin = $this->makeOrgUser($org);
        $rule = FormReportRule::create(['form_id' => $form->id, 'item_code' => 'crew_duty_assignment', 'distinct_by' => 'none']);

        $response = $this->actingAs($admin)->deleteJson(route('form.report-rules.destroy', [$form->form_id, $rule->id]));

        $response->assertOk();
        $this->assertDatabaseCount('form_report_rules', 0);
    }

    public function test_cannot_manage_rules_on_a_form_belonging_to_another_org(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $formB = $this->makeForm($orgB);
        $adminA = $this->makeOrgUser($orgA);

        $response = $this->actingAs($adminA)->get(route('form.report-rules.edit', $formB->form_id));

        $response->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MandatoryReportRuleTest`
Expected: FAIL — route `form.report-rules.edit` not defined.

- [ ] **Step 3: Add routes**

In `routes/web.php`, right after the existing chain routes (after the
`form.chain.maps.update` line), add:

```php
    Route::get('/forms/{form_id}/report-rules', [FormReportRuleController::class, 'edit'])->name('form.report-rules.edit');
    Route::post('/forms/{form_id}/report-rules', [FormReportRuleController::class, 'store'])->name('form.report-rules.store');
    Route::delete('/forms/{form_id}/report-rules/{rule}', [FormReportRuleController::class, 'destroy'])->name('form.report-rules.destroy');
```

Add the `use` import near the top of `routes/web.php` alongside the other controller imports:

```php
use App\Http\Controllers\FormReportRuleController;
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormReportRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormReportRuleController extends Controller
{
    public function edit(string $form_id)
    {
        $form = $this->guardedForm($form_id);

        $rules = $form->reportRules()->with(['conditionField', 'distinctField'])->get();

        $catalog = config('mandatory_report.sections');
        $availableFields = $form->formFields()->whereIn('type', ['text', 'number', 'date', 'select', 'autocomplete'])->get();

        return view('form.formReportRules', compact('form', 'rules', 'catalog', 'availableFields'));
    }

    public function store(Request $request, string $form_id)
    {
        $form = $this->guardedForm($form_id);

        $validated = $this->validateRule($request, $form);

        try {
            FormReportRule::create(array_merge($validated, ['form_id' => $form->id]));

            return response()->json(['success' => 'เพิ่มรายการรายงานสำเร็จ']);
        } catch (\Throwable $th) {
            return response()->json(['errors' => 'เพิ่มรายการรายงานไม่สำเร็จ'], 422);
        }
    }

    public function destroy(string $form_id, int $rule)
    {
        $form = $this->guardedForm($form_id);
        $ruleModel = $form->reportRules()->findOrFail($rule);
        $ruleModel->delete();

        return response()->json(['success' => 'ลบรายการรายงานสำเร็จ']);
    }

    private function validateRule(Request $request, Form $form): array
    {
        $itemCodes = collect(config('mandatory_report.sections'))
            ->flatMap(fn ($section) => array_keys($section['items']))
            ->all();

        $data = $request->validate([
            'item_code' => [
                'required',
                Rule::in($itemCodes),
                Rule::unique('form_report_rules', 'item_code')->where('form_id', $form->id),
            ],
            'condition_field_id' => ['nullable', 'integer'],
            'condition_values' => ['nullable', 'array'],
            'condition_values.*' => ['string'],
            'distinct_by' => ['required', Rule::in(['user', 'vehicle', 'field', 'none'])],
            'distinct_field_id' => ['nullable', 'integer', 'required_if:distinct_by,field'],
        ], [
            'item_code.unique' => 'รายการนี้ถูกผูกกับฟอร์มนี้แล้ว',
            'distinct_field_id.required_if' => 'กรุณาเลือกช่องที่ใช้นับไม่ซ้ำ',
        ]);

        foreach (['condition_field_id', 'distinct_field_id'] as $fieldKey) {
            if (!empty($data[$fieldKey]) && !FormField::where('id', $data[$fieldKey])->where('form_id', $form->id)->exists()) {
                throw ValidationException::withMessages([$fieldKey => 'ช่องที่เลือกไม่ใช่ของฟอร์มนี้']);
            }
        }

        if ($data['distinct_by'] === 'user' && !$form->select_user) {
            throw ValidationException::withMessages(['distinct_by' => 'ฟอร์มนี้ไม่ได้เปิดใช้การเลือกผู้ใช้']);
        }

        if ($data['distinct_by'] === 'vehicle' && !$form->select_vehicle) {
            throw ValidationException::withMessages(['distinct_by' => 'ฟอร์มนี้ไม่ได้เปิดใช้การเลือกรถ']);
        }

        return $data;
    }

    private function guardedForm(string $formId): Form
    {
        $orgId = Auth::user()->is_tsm
            ? session('connected_org')
            : Auth::user()->userDetail?->org;

        return Form::where('form_id', $formId)
            ->where('is_sub_form', false)
            ->where(function ($query) use ($orgId) {
                $query->where('org', $orgId)->orWhere('is_default', true);
            })
            ->firstOrFail();
    }
}
```

- [ ] **Step 5: Create a minimal placeholder view (fleshed out fully in Task 9)**

Create `resources/views/form/formReportRules.blade.php`:

```blade
@extends('layouts.app')

@section('content')
    <div class="px-3 px-md-5">
        <div class="card">
            <div class="card-header">
                <p class="mb-0 fs-4">รายงานภาคบังคับ: {{ $form->title }}</p>
            </div>
            <div class="card-body">
                <p>Placeholder — full UI built in Task 9.</p>
            </div>
        </div>
    </div>
@endsection
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=MandatoryReportRuleTest`
Expected: PASS (6 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/FormReportRuleController.php routes/web.php resources/views/form/formReportRules.blade.php tests/Feature/MandatoryReportRuleTest.php
git commit -m "Add report rule CRUD controller and routes

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 9: Rule settings page — full UI

**Files:**
- Modify: `resources/views/form/formReportRules.blade.php`
- Modify: `resources/views/form/formTable.blade.php`

- [ ] **Step 1: Write the full view**

Replace the contents of `resources/views/form/formReportRules.blade.php`:

```blade
@extends('layouts.app')

@section('content')
    <div class="px-3 px-md-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <p class="mb-0 fs-4">รายงานภาคบังคับ: {{ $form->title }}</p>
                <a href="{{ route('form.table', $form->category) }}" class="btn btn-outline-secondary btn-sm">กลับ</a>
            </div>
            <div class="card-body">
                <div id="ruleAlert"></div>

                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>รายการ</th>
                            <th>นับเมื่อ</th>
                            <th>ค่าที่ถือว่าทำแล้ว</th>
                            <th>นับไม่ซ้ำตาม</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ruleRows">
                        @foreach ($rules as $rule)
                            <tr data-rule-id="{{ $rule->id }}">
                                <td>
                                    @foreach ($catalog as $section)
                                        @foreach ($section['items'] as $code => $item)
                                            @if ($code === $rule->item_code)
                                                {{ $section['label'] }} — {{ $item['label'] }}
                                            @endif
                                        @endforeach
                                    @endforeach
                                </td>
                                <td>
                                    {{ $rule->conditionField && !$rule->conditionField->trashed() ? $rule->conditionField->label : 'ทุกใบ' }}
                                    @if ($rule->condition_field_id && (!$rule->conditionField || $rule->conditionField->trashed()))
                                        <span class="badge bg-warning text-dark">ช่องที่ใช้ถูกลบแล้ว</span>
                                    @endif
                                </td>
                                <td>{{ !empty($rule->condition_values) ? implode(', ', $rule->condition_values) : '-' }}</td>
                                <td>
                                    @switch($rule->distinct_by)
                                        @case('user') คน @break
                                        @case('vehicle') รถ @break
                                        @case('field')
                                            {{ $rule->distinctField && !$rule->distinctField->trashed() ? $rule->distinctField->label : '' }}
                                            @if (!$rule->distinctField || $rule->distinctField->trashed())
                                                <span class="badge bg-warning text-dark">ช่องที่ใช้ถูกลบแล้ว</span>
                                            @endif
                                            @break
                                        @default ไม่นับ
                                    @endswitch
                                </td>
                                <td>
                                    <button type="button" class="btn btn-danger btn-sm delete-rule" data-rule-id="{{ $rule->id }}">ลบ</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <hr>

                <h5>เพิ่มรายการใหม่</h5>
                <form id="addRuleForm" class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">รายการของกรมฯ</label>
                        <select name="item_code" class="form-control" required>
                            <option value="">-- เลือกรายการ --</option>
                            @foreach ($catalog as $section)
                                <optgroup label="{{ $section['label'] }}">
                                    @foreach ($section['items'] as $code => $item)
                                        <option value="{{ $code }}">{{ $item['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">นับเมื่อ</label>
                        <select name="condition_field_id" id="conditionFieldSelect" class="form-control">
                            <option value="">ทุกใบ</option>
                            @foreach ($availableFields as $field)
                                <option value="{{ $field->id }}" data-type="{{ $field->type }}"
                                    data-options="{{ collect($field->options)->pluck('value')->implode('||') }}">
                                    {{ $field->label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3" id="conditionValuesWrap" style="display:none">
                        <label class="form-label">ค่าที่ถือว่าทำแล้ว</label>
                        <div id="conditionValuesOptions"></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">นับไม่ซ้ำตาม</label>
                        <select name="distinct_by" id="distinctBySelect" class="form-control" required>
                            <option value="none">ไม่นับ</option>
                            @if ($form->select_user)
                                <option value="user">คน</option>
                            @endif
                            @if ($form->select_vehicle)
                                <option value="vehicle">รถ</option>
                            @endif
                            <option value="field">ช่องในฟอร์ม</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="distinctFieldWrap" style="display:none">
                        <label class="form-label">ช่องที่ใช้นับไม่ซ้ำ</label>
                        <select name="distinct_field_id" class="form-control">
                            @foreach ($availableFields as $field)
                                <option value="{{ $field->id }}">{{ $field->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">เพิ่ม</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const conditionFieldSelect = document.getElementById('conditionFieldSelect');
    const conditionValuesWrap = document.getElementById('conditionValuesWrap');
    const conditionValuesOptions = document.getElementById('conditionValuesOptions');
    const distinctBySelect = document.getElementById('distinctBySelect');
    const distinctFieldWrap = document.getElementById('distinctFieldWrap');
    const alertBox = document.getElementById('ruleAlert');

    conditionFieldSelect.addEventListener('change', function () {
        const selected = conditionFieldSelect.options[conditionFieldSelect.selectedIndex];
        const type = selected.dataset.type;
        const optionsRaw = selected.dataset.options || '';

        if ((type === 'select' || type === 'autocomplete') && optionsRaw) {
            conditionValuesWrap.style.display = '';
            conditionValuesOptions.innerHTML = optionsRaw.split('||').filter(Boolean).map(function (value) {
                return '<div class="form-check"><input class="form-check-input" type="checkbox" name="condition_values[]" value="' + value + '" id="cv_' + value + '"><label class="form-check-label" for="cv_' + value + '">' + value + '</label></div>';
            }).join('');
        } else {
            conditionValuesWrap.style.display = 'none';
            conditionValuesOptions.innerHTML = '';
        }
    });

    distinctBySelect.addEventListener('change', function () {
        distinctFieldWrap.style.display = distinctBySelect.value === 'field' ? '' : 'none';
    });

    document.getElementById('addRuleForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(e.target);

        fetch('{{ route('form.report-rules.store', $form->form_id) }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
            body: formData,
        })
        .then(function (res) { return res.json().then(function (body) { return { status: res.status, body: body }; }); })
        .then(function (result) {
            if (result.status >= 400) {
                const message = result.body.errors || Object.values(result.body.errors || {}).join(' ') || 'เกิดข้อผิดพลาด';
                alertBox.innerHTML = '<div class="alert alert-danger">' + message + '</div>';
                return;
            }
            window.location.reload();
        });
    });

    document.querySelectorAll('.delete-rule').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!confirm('ยืนยันการลบรายการนี้?')) return;

            fetch('{{ url('/forms/'.$form->form_id.'/report-rules') }}/' + button.dataset.ruleId, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            }).then(function () { window.location.reload(); });
        });
    });
});
</script>
@endsection
```

- [ ] **Step 2: Add entry point button in form table**

In `resources/views/form/formTable.blade.php` (around line 59), find:

```blade
                                                <a href="{{ route('form.chain.edit', ['form_id' => $form->form_id]) }}" class="btn btn-secondary btn-sm" data-bs-toggle="tooltip" data-bs-title="จัดการฟอร์มต่อเนื่อง">
                                                    <i class="bi bi-diagram-3"></i>
                                                </a>
```

Replace it with (adds the new button right after, before the clone button):

```blade
                                                <a href="{{ route('form.chain.edit', ['form_id' => $form->form_id]) }}" class="btn btn-secondary btn-sm" data-bs-toggle="tooltip" data-bs-title="จัดการฟอร์มต่อเนื่อง">
                                                    <i class="bi bi-diagram-3"></i>
                                                </a>
                                                <a href="{{ route('form.report-rules.edit', ['form_id' => $form->form_id]) }}" class="btn btn-info btn-sm" data-bs-toggle="tooltip" data-bs-title="รายงานภาคบังคับ">
                                                    <i class="bi bi-clipboard-data"></i>
                                                </a>
```

- [ ] **Step 3: Manually verify in browser**

Run: `php artisan serve` (if not already running) and `npm run dev` in another terminal, then log in as an
org admin, go to form management, click the new button, and confirm the page loads with the add-rule form
and that adding + deleting a rule works without a page error.

- [ ] **Step 4: Run existing tests to confirm no regressions**

Run: `php artisan test --filter=MandatoryReportRuleTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add resources/views/form/formReportRules.blade.php resources/views/form/formTable.blade.php
git commit -m "Build full rule settings UI and add entry-point button

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 10: Clone carries report rules

**Files:**
- Modify: `app/Http/Controllers/FormController.php`
- Test: `tests/Feature/MandatoryReportRuleTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MandatoryReportRuleTest`:

```php
    public function test_duplicating_a_form_copies_its_report_rules_with_remapped_field_ids(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org, ['select_user' => true]);
        $conditionField = $this->makeField($form, 'การใช้เครื่องตรวจวัดแอลกอฮอล์', 'select', 0);
        $this->addFieldOption($conditionField, 'ตรวจ');

        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'crew_alcohol_test',
            'condition_field_id' => $conditionField->id,
            'condition_values' => ['ตรวจ'],
            'distinct_by' => 'user',
        ]);

        $admin = $this->makeOrgUser($org);

        $response = $this->actingAs($admin)->postJson(
            route('form.duplicate', [$form->category ?? 'general', $form->id])
        );

        $response->assertOk();

        $newFormId = $response->json('form_id');
        $newForm = \App\Models\Form::where('form_id', $newFormId)->firstOrFail();

        $this->assertDatabaseCount('form_report_rules', 2);
        $newRule = FormReportRule::where('form_id', $newForm->id)->firstOrFail();

        $this->assertSame('crew_alcohol_test', $newRule->item_code);
        $this->assertSame(['ตรวจ'], $newRule->condition_values);
        $this->assertSame('user', $newRule->distinct_by);

        $newConditionField = FormField::where('id', $newRule->condition_field_id)->firstOrFail();
        $this->assertSame($newForm->id, $newConditionField->form_id);
        $this->assertNotSame($conditionField->id, $newConditionField->id);
    }
```

Add `use App\Models\FormField;` to the top of the test file if not already present.

Note: `FormController::duplicate(string $form_category, string $id)` takes `$form_category` as a route
segment but never reads it in the method body (confirmed by reading `app/Http/Controllers/FormController.php`
lines 288-338) — only `$id` is used to look up `$originalForm`. So the literal string `'general'` in the
route call above is fine; it does not need to match a real `Form_category` row.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_duplicating_a_form_copies_its_report_rules_with_remapped_field_ids`
Expected: FAIL — `assertDatabaseCount('form_report_rules', 2)` fails because only 1 row exists (the clone
didn't copy rules yet).

- [ ] **Step 3: Modify `FormController::duplicate`**

Open `app/Http/Controllers/FormController.php` and locate the `duplicate()` method (around line 288). Add
field-id tracking and rule copying. Change:

```php
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
```

to:

```php
            $fieldIdMap = [];

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

                $fieldIdMap[$field->id] = $newField->id;

                foreach ($field->options as $option) {
                    FieldOption::create([
                        'field_id' => $newField->id,
                        'value' => $option->value,
                    ]);
                }
            }

            foreach ($originalForm->reportRules as $rule) {
                \App\Models\FormReportRule::create([
                    'form_id' => $newForm->id,
                    'item_code' => $rule->item_code,
                    'condition_field_id' => $rule->condition_field_id ? ($fieldIdMap[$rule->condition_field_id] ?? null) : null,
                    'condition_values' => $rule->condition_values,
                    'distinct_by' => $rule->distinct_by,
                    'distinct_field_id' => $rule->distinct_field_id ? ($fieldIdMap[$rule->distinct_field_id] ?? null) : null,
                ]);
            }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MandatoryReportRuleTest`
Expected: PASS (7 tests)

- [ ] **Step 5: Run full FormController-related test suite to confirm no regressions**

Run: `php artisan test --filter=FormChainLinkingTest`
Expected: PASS (unchanged — this task doesn't touch chain logic)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/FormController.php tests/Feature/MandatoryReportRuleTest.php
git commit -m "Copy report rules when a form is duplicated, remapping field ids

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 11: Report page — controller, view, route, menu

**Files:**
- Create: `app/Http/Controllers/MandatoryReportController.php`
- Create: `resources/views/exportDocument/mandatoryReport.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/MandatoryReportTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MandatoryReportTest`:

```php
    public function test_report_page_renders_for_current_quarter(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org, ['select_user' => true]);
        FormReportRule::create(['form_id' => $form->id, 'item_code' => 'crew_duty_assignment', 'distinct_by' => 'user']);

        $user = $this->makeOrgUser($org, [$form->id]);
        $this->makeSubmission($form, $org, ['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('mandatory.report', ['quarter' => now()->quarter]));

        $response->assertOk();
        $response->assertSee('การกำหนดหน้าที่และความรับผิดชอบของผู้ประจำรถ', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_report_page_renders_for_current_quarter`
Expected: FAIL — route `mandatory.report` not defined.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Services\MandatoryReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MandatoryReportController extends Controller
{
    public function __construct(private readonly MandatoryReportService $service)
    {
    }

    public function index(Request $request)
    {
        $request->validate(['quarter' => ['nullable', 'integer', 'between:1,4']]);

        $quarter = (int) ($request->quarter ?? now()->quarter);
        $year = now()->year;

        $orgId = Auth::user()->is_tsm
            ? session('connected_org')
            : Auth::user()->userDetail?->org;

        $report = $this->service->build($orgId, $quarter, $year);

        return view('exportDocument.mandatoryReport', compact('report', 'quarter'));
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add right after the `submission.count` route:

```php
    Route::get('/mandatory-report', [MandatoryReportController::class, 'index'])->name('mandatory.report');
```

Add `use App\Http\Controllers\MandatoryReportController;` near the top with the other controller imports.

- [ ] **Step 5: Write the view**

Create `resources/views/exportDocument/mandatoryReport.blade.php`, following the structure of
`submissionCount.blade.php`:

```blade
@extends('layouts.app')

@section('content')
    <div class="">
        <div class="row justify-content-center">
            <div class="px-3 px-md-5">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between">
                            <p class="mb-0 fs-4">{{ __('รายงานภาคบังคับ') }}</p>
                            <div class="d-flex align-items-center">
                                @php
                                    $currentdate = Carbon\Carbon::now();
                                @endphp
                                <p class="text-nowrap me-2 mb-0">เลือกไตรมาส</p>
                                <select name="quarter" class="form-control me-2 border-2 border-primary">
                                    <option value="1" {{ $quarter == 1 ? 'selected' : '' }}>ไตรมาสที่ 1 ปี
                                        {{ $currentdate->thaidate('Y') }}</option>
                                    <option value="2" {{ $quarter == 2 ? 'selected' : '' }}>ไตรมาสที่ 2 ปี
                                        {{ $currentdate->thaidate('Y') }}</option>
                                    <option value="3" {{ $quarter == 3 ? 'selected' : '' }}>ไตรมาสที่ 3 ปี
                                        {{ $currentdate->thaidate('Y') }}</option>
                                    <option value="4" {{ $quarter == 4 ? 'selected' : '' }}>ไตรมาสที่ 4 ปี
                                        {{ $currentdate->thaidate('Y') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="card-body overflow-auto">
                        <table class="table table-bordered border-secondary">
                            <thead class="text-center">
                                <tr>
                                    <th style="background-color: #E7D3EF">รายการ</th>
                                    <th style="background-color: #E7D3EF">จำนวน (ครั้ง)</th>
                                    <th style="background-color: #E7D3EF">หน่วย</th>
                                    <th style="background-color: #E7D3EF">จำนวน</th>
                                    <th style="background-color: #E7D3EF">หน่วย</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report as $section)
                                    <tr>
                                        <td colspan="5" style="background-color: #dbdbdb"><strong>{{ $section['label'] }}</strong></td>
                                    </tr>
                                    @foreach ($section['items'] as $item)
                                        <tr>
                                            <td>
                                                {{ $item['label'] }}
                                                @if (!empty($item['sources']))
                                                    <br><small class="text-muted">จาก: {{ implode(', ', $item['sources']) }}</small>
                                                @endif
                                            </td>
                                            <td class="text-center">{{ $item['times'] ?? '–' }}</td>
                                            <td class="text-center">ครั้ง</td>
                                            <td class="text-center">{{ $item['units'] ?? '–' }}</td>
                                            <td class="text-center">{{ $item['unit'] }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectQuarter = document.querySelector('select[name="quarter"]');
        selectQuarter.addEventListener('change', function () {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('quarter', selectQuarter.value);
            window.location.href = currentUrl.toString();
        });
    });
</script>
@endsection
```

- [ ] **Step 6: Add the menu link**

In `resources/views/layouts/app.blade.php`, at line ~147 (desktop menu) right after the `performanceReportPage`
`</li>` block, add:

```blade
                                    <li class="sidebar-item" id="mandatoryReportPage">
                                        <a href="{{ route('mandatory.report', ['quarter' => Carbon\Carbon::now()->quarterOfYear()]) }}"
                                            class="sidebar-link">
                                            รายงานภาคบังคับ
                                        </a>
                                    </li>
```

Repeat the same block at the second occurrence (~line 390, mobile menu), right after that duplicate
`performanceReportPage` `</li>` and before the commented-out `submission.count` block.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=MandatoryReportTest`
Expected: PASS (9 tests total)

- [ ] **Step 8: Manual verification in browser**

Navigate to `/mandatory-report` while logged in as a test org user with a form that has rules and
submissions; confirm the table renders all 5 sections and 19 items, with "–" for items with no rule.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/MandatoryReportController.php resources/views/exportDocument/mandatoryReport.blade.php routes/web.php resources/views/layouts/app.blade.php tests/Feature/MandatoryReportTest.php
git commit -m "Add mandatory report page, route, and menu link

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 12: Full regression pass and feedback doc update

**Files:**
- Modify: `docs/customer-feedback.md`

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: All tests PASS, including `MandatoryReportTest`, `MandatoryReportRuleTest`,
`FormChainLinkingTest`, and every pre-existing test file.

- [ ] **Step 2: Mark the feedback item done**

In `docs/customer-feedback.md`, change:

```markdown
- [ ] **รายงานภาคบังคับ (Mandatory report)** — หน้าแสดงสถิติการกรอกฟอร์มรายไตรมาส ตามรายการที่ต้องแจ้งในระบบ
      tsmthai ของกรมการขนส่งทางบก (จำนวนครั้ง + จำนวนคัน/คน/เส้นทาง ไม่ซ้ำ) ให้ลูกค้าดูตัวเลขไปกรอกเอง
      กฎการนับผูกกับฟอร์ม และติดไปกับการ clone ฟอร์มด้วย — ดู
      `docs/superpowers/specs/2026-09-19-mandatory-report-design.md`
```

to (mark done, add commit reference — fill in the actual short hash of Task 11's commit when executing):

```markdown
- [x] **รายงานภาคบังคับ (Mandatory report)** — หน้าแสดงสถิติการกรอกฟอร์มรายไตรมาส ตามรายการที่ต้องแจ้งในระบบ
      tsmthai ของกรมการขนส่งทางบก (จำนวนครั้ง + จำนวนคัน/คน/เส้นทาง ไม่ซ้ำ) ให้ลูกค้าดูตัวเลขไปกรอกเอง
      กฎการนับผูกกับฟอร์ม และติดไปกับการ clone ฟอร์มด้วย (คอมมิต `<hash>`) — ดู
      `docs/superpowers/specs/2026-09-19-mandatory-report-design.md`
```

- [ ] **Step 3: Commit**

```bash
git add docs/customer-feedback.md
git commit -m "Mark mandatory report feedback item done

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Self-Review Notes

**Implementation verification:** MandatoryReportTest and MandatoryReportRuleTest pass (19 tests, 41
assertions), Blade templates cache successfully, and all mandatory-report routes are registered.

- **Spec coverage:** Item catalog (Task 1), `form_report_rules` schema (Task 2), rule settings page
  (Tasks 8–9), clone propagation (Task 10), counting service incl. all 8 spec test scenarios (Tasks 4–7),
  report page + menu (Task 11), feedback doc update (Task 12). All spec sections are covered.
- **Known follow-up, not in scope:** the spec's item 9 test ("condition field validation rejects a field
  from another form") is covered in Task 8's `test_condition_field_from_another_form_is_rejected`.
- **Risk flagged inline:** Task 10's test depends on the exact `form.duplicate` route/category shape;
  the step explicitly tells the implementer to verify against real `Form_category` data rather than
  guessing, since this plan's author could not confirm the seeded category id at plan-writing time.
