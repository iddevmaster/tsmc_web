# Cross-Form Data Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user filling out a new form pull in values from another form's submission made today, auto-matched by exact field label + type, with no admin configuration and no new database tables — per [docs/superpowers/specs/2026-09-18-form-cross-form-import-design.md](../specs/2026-09-18-form-cross-form-import-design.md).

**Architecture:** A new stateless `FormImportService` composes the existing `FormChainService` (reusing `currentOrgId()`, `canAccessSubmission()`, `answerableFields()`) to add two read-only methods: listing today's cross-form candidates, and computing matched field values for one chosen candidate. Two new `DocumentController` endpoints expose these as JSON; a button + Alpine.js flow in `fillOutForm.blade.php` drives them. Nothing is persisted — every check re-runs on each request.

**Tech Stack:** Laravel 11 (PHP 8.2+), Blade + Alpine.js, Bootstrap 5, SweetAlert2. PHPUnit + SQLite (`RefreshDatabase`) for backend tests, following the pattern already established by `tests/Feature/FormChainLinkingTest.php`.

---

## File Structure

| File | Change |
|---|---|
| `tests/Concerns/BuildsFormTestData.php` | New — test-data builder trait extracted from `FormChainLinkingTest` |
| `tests/Feature/FormChainLinkingTest.php` | Modify — use the extracted trait instead of its own private builder methods |
| `app/Services/FormImportService.php` | New — `candidatesForToday()`, `matchedValues()` |
| `app/Http/Controllers/DocumentController.php` | Modify — inject `FormImportService`; add `importCandidates()`, `importData()` |
| `routes/web.php` | Add `document.import.candidates`, `document.import.data` routes |
| `resources/views/form/checking/fillOutForm.blade.php` | Add import button + `openImportPicker()`/`applyImport()` Alpine methods |
| `tests/Feature/FormImportTest.php` | New — covers the service and both endpoints |
| `docs/customer-feedback.md` | Mark item 5 done once shipped |

No migrations. No changes to `continueDocument.blade.php` (edit flow is explicitly out of scope per the spec).

---

## Task 1: Extract shared test-data builder trait

**Files:**
- Create: `tests/Concerns/BuildsFormTestData.php`
- Modify: `tests/Feature/FormChainLinkingTest.php:1-88`

**Why first:** `FormImportTest.php` (Task 2+) needs the exact same org/user/form/field builders `FormChainLinkingTest.php` already has, plus one new helper (`grantCanSeeAllDocs`) that visibility tests in Task 2 need. Extracting now avoids duplicating this boilerplate across two test files.

- [ ] **Step 1: Create the trait**

```php
<?php

namespace Tests\Concerns;

use App\Models\Form;
use App\Models\FormField;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PositionHasForm;
use App\Models\Position_has_permission;
use App\Models\Position_permission;
use App\Models\User;
use App\Models\User_detail;
use Illuminate\Support\Str;

trait BuildsFormTestData
{
    private function makeOrg(string $name = 'Org'): Organization
    {
        return Organization::create([
            'org_id' => Str::uuid(),
            'name' => $name,
        ]);
    }

    private function makeOrgUser(Organization $org, array $formIds = []): User
    {
        $user = User::create([
            'user_id' => Str::uuid(),
            'username' => 'user_' . Str::random(10),
            'password' => bcrypt('password'),
            'is_tsm' => false,
        ]);

        $position = Position::create([
            'name' => 'Driver',
            'created_by' => (string) $user->id,
            'org' => (string) $org->id,
        ]);

        foreach ($formIds as $formId) {
            PositionHasForm::create([
                'position_id' => $position->id,
                'form_id' => $formId,
            ]);
        }

        User_detail::create([
            'user_id' => $user->id,
            'fname' => 'Test',
            'lname' => 'User',
            'org' => (string) $org->id,
            'position' => $position->id,
        ]);

        return $user->fresh();
    }

    private function makeForm(Organization $org, array $overrides = []): Form
    {
        return Form::create(array_merge([
            'form_id' => (string) Str::uuid(),
            'title' => 'Form ' . Str::random(4),
            'org' => (string) $org->id,
            'select_user' => true,
            'select_vehicle' => false,
            'status' => true,
            'is_sub_form' => false,
            'is_default' => false,
        ], $overrides));
    }

    private function makeField(Form $form, string $label, string $type = 'text', int $order = 0): FormField
    {
        return FormField::create([
            'form_id' => $form->id,
            'label' => $label,
            'type' => $type,
            'order_number' => $order,
        ]);
    }

    private function grantCanSeeAllDocs(User $user, Organization $org): void
    {
        $position = $user->userDetail->getPosition;

        $permission = Position_permission::create([
            'perm_name' => 'can_see_all_docs',
            'label' => 'ดูเอกสารทั้งหมด',
            'org' => (string) $org->id,
        ]);

        Position_has_permission::create([
            'position_id' => $position->id,
            'permission_id' => $permission->id,
            'user_id' => $user->id,
            'org' => $org->id,
            'status' => true,
        ]);
    }
}
```

- [ ] **Step 2: Use the trait in `FormChainLinkingTest` and remove the duplicated methods**

In `tests/Feature/FormChainLinkingTest.php`, replace lines 1-88 (everything from the opening `<?php` through the closing `}` of `makeField()`, i.e. the whole header block and all four private builder methods) with:

```php
<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormChainLink;
use App\Models\FormSubmissions;
use App\Models\FormSubmissionValue;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsFormTestData;
use Tests\TestCase;

class FormChainLinkingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsFormTestData;
```

(This drops the now-unused `FormField`, `Position`, `PositionHasForm`, `User_detail` imports — `BuildsFormTestData` imports what it needs itself — and removes the four private methods, since the trait now supplies them. Every test method below this point (`test_chain_link_creation_rejects_a_form_from_another_org` onward) stays exactly as-is.)

- [ ] **Step 3: Run the existing chain-linking tests to confirm nothing broke**

Run: `php artisan test --filter=FormChainLinkingTest`
Expected: `Tests: 8 passed (25 assertions)` — same result as before the refactor.

- [ ] **Step 4: Commit**

```bash
git add tests/Concerns/BuildsFormTestData.php tests/Feature/FormChainLinkingTest.php
git commit -m "$(cat <<'EOF'
Extract shared form-test-data builder trait from FormChainLinkingTest

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `FormImportService::candidatesForToday()`

**Files:**
- Create: `app/Services/FormImportService.php`
- Create: `tests/Feature/FormImportTest.php`

- [ ] **Step 1: Create the service with a stub method**

```php
<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmissions;
use App\Models\FormSubmissionValue;
use App\Models\User;
use Illuminate\Support\Collection;

class FormImportService
{
    public function __construct(private FormChainService $chainService)
    {
    }

    public function candidatesForToday(Form $targetForm): Collection
    {
        return collect();
    }

    private function fieldSignatures(Collection $fields): Collection
    {
        return $fields->map(fn (FormField $field) => trim($field->label) . '|' . $field->type)->unique()->values();
    }
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/FormImportTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormSubmissions;
use App\Models\FormSubmissionValue;
use App\Models\Vehicle;
use App\Services\FormImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsFormTestData;
use Tests\TestCase;

class FormImportTest extends TestCase
{
    use RefreshDatabase;
    use BuildsFormTestData;

    public function test_candidates_include_a_same_org_today_visible_cross_form_submission_with_a_matching_field(): void
    {
        $org = $this->makeOrg();
        $sourceForm = $this->makeForm($org, ['title' => 'ฟอร์มต้นทาง']);
        $targetForm = $this->makeForm($org, ['title' => 'ฟอร์มปลายทาง']);

        $this->makeField($sourceForm, 'ทะเบียนรถ', 'text', 0);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $targetForm->id]);

        $submission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $this->actingAs($user);
        $candidates = app(FormImportService::class)->candidatesForToday($targetForm);

        $this->assertCount(1, $candidates);
        $this->assertSame($submission->submission_id, $candidates->first()['submission_id']);
        $this->assertSame('ฟอร์มต้นทาง', $candidates->first()['form_title']);
    }

    public function test_candidates_exclude_a_submission_of_the_same_form_as_the_target(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);
        $user = $this->makeOrgUser($org, [$targetForm->id]);

        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $targetForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $this->actingAs($user);
        $candidates = app(FormImportService::class)->candidatesForToday($targetForm);

        $this->assertCount(0, $candidates);
    }

    public function test_candidates_exclude_a_submission_from_another_org(): void
    {
        $org = $this->makeOrg('Org A');
        $otherOrg = $this->makeOrg('Org B');
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $otherOrgForm = $this->makeForm($otherOrg);
        $this->makeField($otherOrgForm, 'ทะเบียนรถ', 'text', 0);
        $otherOrgUser = $this->makeOrgUser($otherOrg, [$otherOrgForm->id]);
        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $otherOrgForm->id,
            'submitted_by' => $otherOrgUser->id,
            'org' => (string) $otherOrg->id,
        ]);

        $user = $this->makeOrgUser($org, [$targetForm->id]);
        $this->actingAs($user);
        $candidates = app(FormImportService::class)->candidatesForToday($targetForm);

        $this->assertCount(0, $candidates);
    }

    public function test_candidates_exclude_a_submission_with_no_matching_field(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $noMatchForm = $this->makeForm($org);
        $this->makeField($noMatchForm, 'ไม่ตรงกันเลย', 'number', 0);

        $user = $this->makeOrgUser($org, [$targetForm->id, $noMatchForm->id]);
        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $noMatchForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $this->actingAs($user);
        $candidates = app(FormImportService::class)->candidatesForToday($targetForm);

        $this->assertCount(0, $candidates);
    }

    public function test_candidates_exclude_a_submission_the_user_cannot_see(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $sourceForm = $this->makeForm($org);
        $this->makeField($sourceForm, 'ทะเบียนรถ', 'text', 0);

        $submitter = $this->makeOrgUser($org, [$sourceForm->id]);
        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $submitter->id,
            'org' => (string) $org->id,
        ]);

        $viewer = $this->makeOrgUser($org, [$targetForm->id]);
        $this->actingAs($viewer);
        $candidates = app(FormImportService::class)->candidatesForToday($targetForm);

        $this->assertCount(0, $candidates);
    }

    public function test_candidates_include_a_submission_visible_via_can_see_all_docs(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $sourceForm = $this->makeForm($org);
        $this->makeField($sourceForm, 'ทะเบียนรถ', 'text', 0);

        $submitter = $this->makeOrgUser($org, [$sourceForm->id]);
        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $submitter->id,
            'org' => (string) $org->id,
        ]);

        $viewer = $this->makeOrgUser($org, [$targetForm->id]);
        $this->grantCanSeeAllDocs($viewer, $org);

        $this->actingAs($viewer);
        $candidates = app(FormImportService::class)->candidatesForToday($targetForm);

        $this->assertCount(1, $candidates);
    }

    public function test_candidates_exclude_a_submission_from_yesterday(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $sourceForm = $this->makeForm($org);
        $this->makeField($sourceForm, 'ทะเบียนรถ', 'text', 0);

        $user = $this->makeOrgUser($org, [$targetForm->id, $sourceForm->id]);
        $submission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);
        FormSubmissions::where('id', $submission->id)->update(['created_at' => now()->subDay()]);

        $this->actingAs($user);
        $candidates = app(FormImportService::class)->candidatesForToday($targetForm);

        $this->assertCount(0, $candidates);
    }
}
```

- [ ] **Step 3: Run the tests to confirm the expected failures**

Run: `php artisan test --filter=FormImportTest`
Expected: `test_candidates_include_a_same_org_today_visible_cross_form_submission_with_a_matching_field` and `test_candidates_include_a_submission_visible_via_can_see_all_docs` FAIL (stub always returns empty); the other five pass trivially since they all assert an empty result.

- [ ] **Step 4: Implement `candidatesForToday()`**

Replace the stub method body in `app/Services/FormImportService.php`:

```php
    public function candidatesForToday(Form $targetForm): Collection
    {
        $orgId = $this->chainService->currentOrgId();
        if (!$orgId) {
            return collect();
        }

        $targetSignatures = $this->fieldSignatures($this->chainService->answerableFields($targetForm));
        if ($targetSignatures->isEmpty()) {
            return collect();
        }

        $submissions = FormSubmissions::where('org', $orgId)
            ->where('form_id', '!=', $targetForm->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (FormSubmissions $submission) => $this->chainService->canAccessSubmission($submission));

        $formCache = [];
        $candidates = collect();

        foreach ($submissions as $submission) {
            if (!array_key_exists($submission->form_id, $formCache)) {
                $sourceForm = Form::find($submission->form_id);
                $formCache[$submission->form_id] = [
                    $sourceForm,
                    $sourceForm ? $this->fieldSignatures($this->chainService->answerableFields($sourceForm)) : collect(),
                ];
            }

            [$sourceForm, $sourceSignatures] = $formCache[$submission->form_id];
            if (!$sourceForm || $sourceSignatures->intersect($targetSignatures)->isEmpty()) {
                continue;
            }

            $submitter = User::find($submission->submitted_by);

            $candidates->push([
                'submission_id' => $submission->submission_id,
                'form_title' => $sourceForm->title,
                'submitted_at' => $submission->created_at->format('H:i'),
                'submitted_by_name' => $submitter?->full_name,
                'employee_name' => optional($submission->getUser)->full_name,
                'vehicle_label' => optional($submission->getVehicle)->license_plate,
            ]);
        }

        return $candidates->values();
    }
```

- [ ] **Step 5: Run the tests again to confirm they all pass**

Run: `php artisan test --filter=FormImportTest`
Expected: `Tests: 7 passed`

- [ ] **Step 6: Commit**

```bash
git add app/Services/FormImportService.php tests/Feature/FormImportTest.php
git commit -m "$(cat <<'EOF'
Add FormImportService::candidatesForToday for cross-form import

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `FormImportService::matchedValues()`

**Files:**
- Modify: `app/Services/FormImportService.php`
- Modify: `tests/Feature/FormImportTest.php`

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/FormImportTest.php` (inside the class, after the Task 2 tests):

```php
    public function test_matched_values_returns_values_for_matched_label_and_type_pairs(): void
    {
        $org = $this->makeOrg();
        $sourceForm = $this->makeForm($org, ['select_user' => false, 'select_vehicle' => false]);
        $targetForm = $this->makeForm($org, ['select_user' => false, 'select_vehicle' => false]);

        $sourceField = $this->makeField($sourceForm, 'ทะเบียนรถ', 'text', 0);
        $targetField = $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $targetForm->id]);
        $source = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);
        FormSubmissionValue::create([
            'submission_id' => $source->id,
            'field_id' => $sourceField->id,
            'value' => 'กข-1234',
            'submitted_by' => $user->id,
        ]);

        $this->actingAs($user);
        $result = app(FormImportService::class)->matchedValues($source, $targetForm);

        $this->assertSame('กข-1234', $result['values'][$targetField->id]);
    }

    public function test_matched_values_skips_a_field_whose_label_or_type_does_not_match(): void
    {
        $org = $this->makeOrg();
        $sourceForm = $this->makeForm($org, ['select_user' => false, 'select_vehicle' => false]);
        $targetForm = $this->makeForm($org, ['select_user' => false, 'select_vehicle' => false]);

        $sourceField = $this->makeField($sourceForm, 'จำนวน', 'number', 0);
        $sameLabelDifferentType = $this->makeField($targetForm, 'จำนวน', 'text', 0);
        $differentLabel = $this->makeField($targetForm, 'คนละชื่อ', 'number', 1);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $targetForm->id]);
        $source = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);
        FormSubmissionValue::create([
            'submission_id' => $source->id,
            'field_id' => $sourceField->id,
            'value' => '5',
            'submitted_by' => $user->id,
        ]);

        $this->actingAs($user);
        $result = app(FormImportService::class)->matchedValues($source, $targetForm);

        $this->assertArrayNotHasKey($sameLabelDifferentType->id, $result['values']);
        $this->assertArrayNotHasKey($differentLabel->id, $result['values']);
    }

    public function test_matched_values_includes_user_and_vehicle_when_both_forms_support_them(): void
    {
        $org = $this->makeOrg();
        $vehicle = Vehicle::create([
            'license_plate' => 'กข-1234',
            'brand' => 'Toyota',
            'org_id' => $org->id,
        ]);
        $sourceForm = $this->makeForm($org, ['select_user' => true, 'select_vehicle' => true]);
        $targetForm = $this->makeForm($org, ['select_user' => true, 'select_vehicle' => true]);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $targetForm->id]);
        $source = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $this->actingAs($user);
        $result = app(FormImportService::class)->matchedValues($source, $targetForm);

        $this->assertSame($user->id, $result['user_id']);
        $this->assertSame($vehicle->id, $result['vehicle_id']);
    }

    public function test_matched_values_excludes_user_and_vehicle_when_target_does_not_support_them(): void
    {
        $org = $this->makeOrg();
        $vehicle = Vehicle::create([
            'license_plate' => 'กข-1234',
            'brand' => 'Toyota',
            'org_id' => $org->id,
        ]);
        $sourceForm = $this->makeForm($org, ['select_user' => true, 'select_vehicle' => true]);
        $targetForm = $this->makeForm($org, ['select_user' => false, 'select_vehicle' => false]);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $targetForm->id]);
        $source = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $this->actingAs($user);
        $result = app(FormImportService::class)->matchedValues($source, $targetForm);

        $this->assertNull($result['user_id']);
        $this->assertNull($result['vehicle_id']);
    }

    public function test_matched_values_picks_the_lowest_order_number_when_source_has_duplicate_labels(): void
    {
        $org = $this->makeOrg();
        $sourceForm = $this->makeForm($org, ['select_user' => false, 'select_vehicle' => false]);
        $targetForm = $this->makeForm($org, ['select_user' => false, 'select_vehicle' => false]);

        $firstSourceField = $this->makeField($sourceForm, 'หมายเหตุ', 'text', 0);
        $this->makeField($sourceForm, 'หมายเหตุ', 'text', 1);
        $targetField = $this->makeField($targetForm, 'หมายเหตุ', 'text', 0);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $targetForm->id]);
        $source = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);
        FormSubmissionValue::create([
            'submission_id' => $source->id,
            'field_id' => $firstSourceField->id,
            'value' => 'ค่าจากช่องแรก',
            'submitted_by' => $user->id,
        ]);

        $this->actingAs($user);
        $result = app(FormImportService::class)->matchedValues($source, $targetForm);

        $this->assertSame('ค่าจากช่องแรก', $result['values'][$targetField->id]);
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test --filter=FormImportTest`
Expected: `Error: Call to undefined method App\Services\FormImportService::matchedValues()`

- [ ] **Step 3: Implement `matchedValues()`**

Add this method to `app/Services/FormImportService.php`, after `candidatesForToday()`:

```php
    public function matchedValues(FormSubmissions $source, Form $targetForm): array
    {
        $sourceForm = Form::find($source->form_id);
        if (!$sourceForm || $sourceForm->id === $targetForm->id) {
            return ['values' => [], 'user_id' => null, 'vehicle_id' => null];
        }

        $targetFields = $this->chainService->answerableFields($targetForm);
        $sourceFields = $this->chainService->answerableFields($sourceForm)
            ->sortBy([['order_number', 'asc'], ['id', 'asc']])
            ->values();

        $sourceValues = FormSubmissionValue::where('submission_id', $source->id)
            ->pluck('value', 'field_id');

        $values = [];
        foreach ($targetFields as $targetField) {
            $match = $sourceFields->first(fn (FormField $sourceField) => trim($sourceField->label) === trim($targetField->label)
                && $sourceField->type === $targetField->type);

            if (!$match) {
                continue;
            }

            $value = $sourceValues->get($match->id);
            if ($value !== null && $value !== '') {
                $values[$targetField->id] = $value;
            }
        }

        $userId = ($targetForm->select_user && $sourceForm->select_user && $source->user_id)
            ? $source->user_id
            : null;
        $vehicleId = ($targetForm->select_vehicle && $sourceForm->select_vehicle && $source->vehicle_id)
            ? $source->vehicle_id
            : null;

        return ['values' => $values, 'user_id' => $userId, 'vehicle_id' => $vehicleId];
    }
```

- [ ] **Step 4: Run the tests again to confirm they pass**

Run: `php artisan test --filter=FormImportTest`
Expected: `Tests: 12 passed`

- [ ] **Step 5: Commit**

```bash
git add app/Services/FormImportService.php tests/Feature/FormImportTest.php
git commit -m "$(cat <<'EOF'
Add FormImportService::matchedValues for cross-form import

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Routes + `DocumentController` endpoints

**Files:**
- Modify: `routes/web.php:143` (insert after)
- Modify: `app/Http/Controllers/DocumentController.php:1-24` (imports + constructor), and insert new methods after `filterDocument()` (currently ending at line 276)
- Modify: `tests/Feature/FormImportTest.php`

- [ ] **Step 1: Write the failing HTTP tests**

Add these methods to `tests/Feature/FormImportTest.php` (after the Task 3 tests):

```php
    public function test_import_candidates_endpoint_returns_todays_cross_form_candidates(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org, ['title' => 'ฟอร์มปลายทาง']);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $sourceForm = $this->makeForm($org, ['title' => 'ฟอร์มต้นทาง']);
        $this->makeField($sourceForm, 'ทะเบียนรถ', 'text', 0);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $targetForm->id]);
        $submission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $response = $this->actingAs($user)->getJson(route('document.import.candidates', $targetForm->form_id));

        $response->assertOk();
        $response->assertJsonCount(1, 'candidates');
        $response->assertJsonPath('candidates.0.submission_id', $submission->submission_id);
    }

    public function test_import_data_endpoint_returns_matched_values_for_a_valid_source(): void
    {
        $org = $this->makeOrg();
        $sourceForm = $this->makeForm($org, ['select_user' => false, 'select_vehicle' => false]);
        $targetForm = $this->makeForm($org, ['select_user' => false, 'select_vehicle' => false]);

        $sourceField = $this->makeField($sourceForm, 'ทะเบียนรถ', 'text', 0);
        $targetField = $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $targetForm->id]);
        $source = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);
        FormSubmissionValue::create([
            'submission_id' => $source->id,
            'field_id' => $sourceField->id,
            'value' => 'กข-1234',
            'submitted_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson(
            route('document.import.data', $source->submission_id) . '?target_form_id=' . $targetForm->form_id
        );

        $response->assertOk();
        $response->assertJsonPath("values.{$targetField->id}", 'กข-1234');
    }

    public function test_import_data_endpoint_rejects_a_source_from_another_org(): void
    {
        $org = $this->makeOrg('Org A');
        $otherOrg = $this->makeOrg('Org B');
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $otherOrgForm = $this->makeForm($otherOrg);
        $this->makeField($otherOrgForm, 'ทะเบียนรถ', 'text', 0);
        $otherOrgUser = $this->makeOrgUser($otherOrg, [$otherOrgForm->id]);
        $foreignSource = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $otherOrgForm->id,
            'submitted_by' => $otherOrgUser->id,
            'org' => (string) $otherOrg->id,
        ]);

        $user = $this->makeOrgUser($org, [$targetForm->id]);
        $response = $this->actingAs($user)->getJson(
            route('document.import.data', $foreignSource->submission_id) . '?target_form_id=' . $targetForm->form_id
        );

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('values', $response->json());
    }

    public function test_import_data_endpoint_rejects_a_source_of_the_same_form_as_target(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $user = $this->makeOrgUser($org, [$targetForm->id]);
        $source = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $targetForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $response = $this->actingAs($user)->getJson(
            route('document.import.data', $source->submission_id) . '?target_form_id=' . $targetForm->form_id
        );

        $response->assertStatus(422);
    }

    public function test_import_data_endpoint_rejects_a_nonexistent_source(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $user = $this->makeOrgUser($org, [$targetForm->id]);

        $response = $this->actingAs($user)->getJson(
            route('document.import.data', (string) Str::uuid()) . '?target_form_id=' . $targetForm->form_id
        );

        $response->assertStatus(422);
    }

    public function test_import_data_endpoint_rejects_a_source_the_user_cannot_access(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text', 0);

        $sourceForm = $this->makeForm($org);
        $this->makeField($sourceForm, 'ทะเบียนรถ', 'text', 0);
        $submitter = $this->makeOrgUser($org, [$sourceForm->id]);
        $source = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $submitter->id,
            'org' => (string) $org->id,
        ]);

        $viewer = $this->makeOrgUser($org, [$targetForm->id]);
        $response = $this->actingAs($viewer)->getJson(
            route('document.import.data', $source->submission_id) . '?target_form_id=' . $targetForm->form_id
        );

        $response->assertStatus(422);
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test --filter=FormImportTest`
Expected: failures with `Route [document.import.candidates] not defined` / `Route [document.import.data] not defined`.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, right after line 143 (`Route::get('/document/submission/{submission_id}/detail', ...)->name('document.submission.show');`), add:

```php
    Route::get('/document/{form_id}/import-candidates', [DocumentController::class, 'importCandidates'])->name('document.import.candidates');
    Route::get('/document/import-data/{submission_id}', [DocumentController::class, 'importData'])->name('document.import.data');
```

- [ ] **Step 4: Inject `FormImportService` into `DocumentController`**

In `app/Http/Controllers/DocumentController.php`, add the import next to the existing `FormChainService` import (around line 13):

```php
use App\Services\FormChainService;
use App\Services\FormImportService;
```

Change the constructor (currently lines 22-24):

```php
    public function __construct(private FormChainService $chainService)
    {
    }
```

to:

```php
    public function __construct(
        private FormChainService $chainService,
        private FormImportService $importService,
    ) {
    }
```

- [ ] **Step 5: Add the two controller actions**

In `app/Http/Controllers/DocumentController.php`, insert these two public methods right after `filterDocument()` (currently ending at line 276, right before the `private function chainActionsFor` method):

```php
    public function importCandidates(string $form_id)
    {
        $org_id = $this->chainService->currentOrgId() ?? '';

        $form = Form::where('form_id', $form_id)
            ->where(function ($query) use ($org_id) {
                $query->where('org', $org_id)->orWhere('is_default', true);
            })
            ->firstOrFail();
        abort_unless($this->chainService->canFillForm($form), 403);

        return response()->json(['candidates' => $this->importService->candidatesForToday($form)]);
    }

    public function importData(Request $request, string $submission_id)
    {
        $org_id = $this->chainService->currentOrgId() ?? '';

        $targetForm = Form::where('form_id', $request->query('target_form_id'))
            ->where(function ($query) use ($org_id) {
                $query->where('org', $org_id)->orWhere('is_default', true);
            })
            ->firstOrFail();
        abort_unless($this->chainService->canFillForm($targetForm), 403);

        $source = FormSubmissions::where('submission_id', $submission_id)->first();

        $isValidSource = $source
            && $this->chainService->canAccessSubmission($source)
            && $source->form_id !== $targetForm->id
            && $source->created_at >= now()->startOfDay();

        if (!$isValidSource) {
            return response()->json(['errors' => 'ไม่สามารถนำเข้าข้อมูลจากใบนี้ได้'], 422);
        }

        return response()->json($this->importService->matchedValues($source, $targetForm));
    }
```

- [ ] **Step 6: Run the tests again to confirm they pass**

Run: `php artisan test --filter=FormImportTest`
Expected: `Tests: 18 passed`

- [ ] **Step 7: Run the full suite to confirm no regression**

Run: `php artisan test`
Expected: all tests pass, including the 8 `FormChainLinkingTest` cases from Task 1.

- [ ] **Step 8: Commit**

```bash
git add routes/web.php app/Http/Controllers/DocumentController.php tests/Feature/FormImportTest.php
git commit -m "$(cat <<'EOF'
Add import-candidates and import-data endpoints for cross-form import

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Fill-out screen UI

**Files:**
- Modify: `resources/views/form/checking/fillOutForm.blade.php`

**No PHPUnit coverage for this task** — it is pure Blade/Alpine.js UI, consistent with how the existing clone-form button and other client-side flows in this codebase are verified (manual checklist, no JS test runner is set up in this project). Verify manually per Step 3 below.

- [ ] **Step 1: Add the import button**

In `resources/views/form/checking/fillOutForm.blade.php`, replace:

```blade
                        <p class="text-center fs-5 fw-bold">{{ $form_data->title }}</p>
                        <form @submit.prevent="handleSubmit">
```

with:

```blade
                        <p class="text-center fs-5 fw-bold">{{ $form_data->title }}</p>
                        <div class="text-center mb-3">
                            <button type="button" class="btn btn-outline-primary btn-sm" @click="openImportPicker()">
                                <i class="bi bi-download"></i> นำเข้าข้อมูลจากฟอร์มอื่น (วันนี้)
                            </button>
                        </div>
                        <form @submit.prevent="handleSubmit">
```

- [ ] **Step 2: Add the Alpine methods**

In the same file's `<script>` block, replace:

```js
                updateUser() {
                    let selectedVehicle = vehicles.find(v => v.id == this.selectVehicleId);
                    this.selectUserId = selectedVehicle ? selectedVehicle.driver_id : '';
                    this.updateError();
                },

                // validateForm() {
```

with:

```js
                updateUser() {
                    let selectedVehicle = vehicles.find(v => v.id == this.selectVehicleId);
                    this.selectUserId = selectedVehicle ? selectedVehicle.driver_id : '';
                    this.updateError();
                },

                async openImportPicker() {
                    const response = await fetch(`/document/{{ $form_data->form_id }}/import-candidates`, {
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                    });
                    const data = await response.json();
                    const candidates = data.candidates || [];

                    if (candidates.length === 0) {
                        Swal.fire({ icon: 'info', title: 'ไม่มีใบที่นำเข้าได้วันนี้', confirmButtonText: 'ตกลง' });
                        return;
                    }

                    const options = candidates.map((c) => {
                        const who = c.employee_name || c.submitted_by_name || '';
                        const vehicle = c.vehicle_label ? ` - ${c.vehicle_label}` : '';
                        return `<option value="${c.submission_id}">${c.form_title} (${c.submitted_at})${who ? ' - ' + who : ''}${vehicle}</option>`;
                    }).join('');

                    const { value: submissionId } = await Swal.fire({
                        title: 'เลือกใบที่จะนำเข้าข้อมูล',
                        html: `<select id="import-source-select" class="form-select">${options}</select>`,
                        showCancelButton: true,
                        confirmButtonText: 'นำเข้า',
                        cancelButtonText: 'ยกเลิก',
                        preConfirm: () => document.getElementById('import-source-select').value,
                    });

                    if (!submissionId) return;

                    await this.applyImport(submissionId);
                },

                async applyImport(submissionId) {
                    const response = await fetch(`/document/import-data/${submissionId}?target_form_id={{ $form_data->form_id }}`, {
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                    });
                    const data = await response.json();

                    if (data.errors) {
                        Swal.fire({ icon: 'error', title: data.errors, confirmButtonText: 'ตกลง' });
                        return;
                    }

                    const values = data.values || {};
                    let filledCount = 0;

                    this.formFieldsAnswer = this.formFieldsAnswer.map((field) => {
                        if (field.type === 'subform') {
                            const subfields = field.subfields.map((sf) => {
                                if (!sf.answer && Object.prototype.hasOwnProperty.call(values, sf.id)) {
                                    filledCount++;
                                    return { ...sf, answer: values[sf.id] };
                                }
                                return sf;
                            });
                            return { ...field, subfields };
                        }

                        if (!field.answer && Object.prototype.hasOwnProperty.call(values, field.id)) {
                            filledCount++;
                            return { ...field, answer: values[field.id] };
                        }
                        return field;
                    });

                    if (!this.selectUserId && data.user_id) {
                        this.selectUserId = data.user_id;
                        filledCount++;
                    }
                    if (!this.selectVehicleId && data.vehicle_id) {
                        this.selectVehicleId = data.vehicle_id;
                        filledCount++;
                    }

                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: filledCount > 0 ? 'success' : 'info',
                        title: filledCount > 0 ? `นำเข้าข้อมูลสำเร็จ (เติม ${filledCount} ช่อง)` : 'ไม่มีช่องว่างให้เติมจากใบนี้',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                    });
                },

                // validateForm() {
```

- [ ] **Step 3: Manual verification**

Run the dev server (`php artisan serve` + `npm run dev`), then:

1. Fill out and submit some form A today with a few answerable fields.
2. Open a blank fill-out page for a different form B that shares at least one field label+type with form A, click "นำเข้าข้อมูลจากฟอร์มอื่น (วันนี้)" → confirm form A's submission from today appears in the picker with a sensible label (form title, time, employee/vehicle if set).
3. Pick it → confirm only matching fields get filled, non-matching fields stay blank, and the toast reports the correct count.
4. Manually type into one still-empty field, then import again from a second source submission that also matches that field → confirm the manually-typed value is untouched.
5. Confirm form B does not appear as a candidate when filling out form B itself.
6. Confirm a submission from yesterday does not appear as a candidate today.
7. Confirm the button on `continueDocument.blade.php` (editing an existing submission) was **not** added there — out of scope per the spec.

- [ ] **Step 4: Commit**

```bash
git add resources/views/form/checking/fillOutForm.blade.php
git commit -m "$(cat <<'EOF'
Add cross-form import button and Alpine flow to the fill-out screen

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Mark the feedback item done

**Files:**
- Modify: `docs/customer-feedback.md`

- [ ] **Step 1: Check off item 5**

In `docs/customer-feedback.md`, replace:

```markdown
- [ ] **Import ข้อมูลข้ามฟอร์ม** — ตอนกรอกแบบฟอร์ม อยากให้ import ข้อมูลจากแบบฟอร์มอื่นได้ โดยระบบ fill
      เฉพาะช่อง (field) ที่ตรงกันระหว่างสองฟอร์มให้อัตโนมัติ (อยู่ระหว่างออกแบบ — ดู
      `docs/superpowers/specs/2026-09-18-form-cross-form-import-design.md`)
```

with:

```markdown
- [x] **Import ข้อมูลข้ามฟอร์ม** — ตอนกรอกแบบฟอร์ม อยากให้ import ข้อมูลจากแบบฟอร์มอื่นได้ โดยระบบ fill
      เฉพาะช่อง (field) ที่ตรงกันระหว่างสองฟอร์มให้อัตโนมัติ (ดึงเฉพาะใบที่กรอกวันนี้ในองค์กรเดียวกัน
      จับคู่ label+type อัตโนมัติ เติมเฉพาะช่องว่าง ไม่มี admin config — ดู
      `docs/superpowers/specs/2026-09-18-form-cross-form-import-design.md`)
```

- [ ] **Step 2: Commit**

```bash
git add docs/customer-feedback.md
git commit -m "$(cat <<'EOF'
Mark cross-form import feedback item done

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Notes

- **Spec coverage:** Task 2 covers `candidatesForToday()` (org scope, today boundary, same-form exclusion, field-match filter, visibility including `can_see_all_docs`). Task 3 covers `matchedValues()` (label+type matching, duplicate-label tie-break, user/vehicle carry gated by both forms' flags). Task 4 covers the two endpoints and their independent server-side re-validation (org, same-form, existence, visibility) so a client can't forge access. Task 5 covers the UI: button, candidate picker, fill-only-empty-fields behavior (including subform fields and user/vehicle selects), and the summary toast. Task 6 closes the loop on `customer-feedback.md`, matching how item 4 (chain-linking) was marked done.
- **Type consistency:** `FormImportService` method names (`candidatesForToday`, `matchedValues`) and the JSON keys they return (`candidates`, `values`, `user_id`, `vehicle_id`) are used identically in the controller (Task 4) and the Blade/Alpine code (Task 5) — verified no drift between tasks.
- **Scope boundary (explicit):** `continueDocument.blade.php` (editing an already-submitted document) intentionally receives no changes — Task 5's manual checklist step 7 calls this out so it isn't accidentally added later.
