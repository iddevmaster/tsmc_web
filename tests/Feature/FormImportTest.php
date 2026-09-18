<?php

namespace Tests\Feature;

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
        $this->makeField($sourceForm, 'ทะเบียนรถ', 'text');
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text');

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
        $this->assertSame((string) $submission->submission_id, $candidates->first()['submission_id']);
        $this->assertSame('ฟอร์มต้นทาง', $candidates->first()['form_title']);
    }

    public function test_candidates_exclude_a_submission_of_the_same_form_as_the_target(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ');
        $user = $this->makeOrgUser($org, [$targetForm->id]);

        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $targetForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $this->actingAs($user);
        $this->assertCount(0, app(FormImportService::class)->candidatesForToday($targetForm));
    }

    public function test_candidates_exclude_a_submission_from_another_org(): void
    {
        $org = $this->makeOrg('Org A');
        $otherOrg = $this->makeOrg('Org B');
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ');
        $sourceForm = $this->makeForm($otherOrg);
        $this->makeField($sourceForm, 'ทะเบียนรถ');
        $sourceUser = $this->makeOrgUser($otherOrg, [$sourceForm->id]);

        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $sourceUser->id,
            'org' => (string) $otherOrg->id,
        ]);

        $user = $this->makeOrgUser($org, [$targetForm->id]);
        $this->actingAs($user);
        $this->assertCount(0, app(FormImportService::class)->candidatesForToday($targetForm));
    }

    public function test_candidates_exclude_a_submission_with_no_matching_field(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ', 'text');
        $sourceForm = $this->makeForm($org);
        $this->makeField($sourceForm, 'ไม่ตรงกันเลย', 'number');
        $user = $this->makeOrgUser($org, [$targetForm->id, $sourceForm->id]);

        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $this->actingAs($user);
        $this->assertCount(0, app(FormImportService::class)->candidatesForToday($targetForm));
    }

    public function test_candidates_exclude_a_submission_the_user_cannot_see(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ');
        $sourceForm = $this->makeForm($org);
        $this->makeField($sourceForm, 'ทะเบียนรถ');
        $submitter = $this->makeOrgUser($org, [$sourceForm->id]);

        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $submitter->id,
            'org' => (string) $org->id,
        ]);

        $viewer = $this->makeOrgUser($org, [$targetForm->id]);
        $this->actingAs($viewer);
        $this->assertCount(0, app(FormImportService::class)->candidatesForToday($targetForm));
    }

    public function test_candidates_include_a_submission_visible_via_can_see_all_docs(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ');
        $sourceForm = $this->makeForm($org);
        $this->makeField($sourceForm, 'ทะเบียนรถ');
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

        $this->assertCount(1, app(FormImportService::class)->candidatesForToday($targetForm));
    }

    public function test_candidates_exclude_a_submission_from_yesterday(): void
    {
        $org = $this->makeOrg();
        $targetForm = $this->makeForm($org);
        $this->makeField($targetForm, 'ทะเบียนรถ');
        $sourceForm = $this->makeForm($org);
        $this->makeField($sourceForm, 'ทะเบียนรถ');
        $user = $this->makeOrgUser($org, [$targetForm->id, $sourceForm->id]);
        $submission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);
        $submission->update(['created_at' => now()->subDay()]);

        $this->actingAs($user);
        $this->assertCount(0, app(FormImportService::class)->candidatesForToday($targetForm));
    }

    public function test_matched_values_returns_values_for_matched_label_and_type_pairs(): void
    {
        $org = $this->makeOrg();
        $sourceForm = $this->makeForm($org, ['select_user' => false]);
        $targetForm = $this->makeForm($org, ['select_user' => false]);
        $sourceField = $this->makeField($sourceForm, 'ทะเบียนรถ');
        $targetField = $this->makeField($targetForm, 'ทะเบียนรถ');
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
        $sourceForm = $this->makeForm($org, ['select_user' => false]);
        $targetForm = $this->makeForm($org, ['select_user' => false]);
        $sourceField = $this->makeField($sourceForm, 'จำนวน', 'number');
        $sameLabelDifferentType = $this->makeField($targetForm, 'จำนวน', 'text');
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

    public function test_matched_values_skips_an_empty_source_value(): void
    {
        $org = $this->makeOrg();
        $sourceForm = $this->makeForm($org, ['select_user' => false]);
        $targetForm = $this->makeForm($org, ['select_user' => false]);
        $sourceField = $this->makeField($sourceForm, 'หมายเหตุ');
        $targetField = $this->makeField($targetForm, 'หมายเหตุ');
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
            'value' => '',
            'submitted_by' => $user->id,
        ]);

        $this->actingAs($user);
        $result = app(FormImportService::class)->matchedValues($source, $targetForm);

        $this->assertArrayNotHasKey($targetField->id, $result['values']);
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
        $sourceForm = $this->makeForm($org, ['select_user' => false]);
        $targetForm = $this->makeForm($org, ['select_user' => false]);
        $firstSourceField = $this->makeField($sourceForm, 'หมายเหตุ', 'text', 0);
        $secondSourceField = $this->makeField($sourceForm, 'หมายเหตุ', 'text', 1);
        $targetField = $this->makeField($targetForm, 'หมายเหตุ');
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
        FormSubmissionValue::create([
            'submission_id' => $source->id,
            'field_id' => $secondSourceField->id,
            'value' => 'ค่าจากช่องที่สอง',
            'submitted_by' => $user->id,
        ]);

        $this->actingAs($user);
        $result = app(FormImportService::class)->matchedValues($source, $targetForm);

        $this->assertSame('ค่าจากช่องแรก', $result['values'][$targetField->id]);
    }
}
