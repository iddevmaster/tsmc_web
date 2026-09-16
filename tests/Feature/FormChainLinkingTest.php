<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormChainLink;
use App\Models\FormField;
use App\Models\FormSubmissions;
use App\Models\FormSubmissionValue;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PositionHasForm;
use App\Models\User;
use App\Models\User_detail;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormChainLinkingTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_chain_link_creation_rejects_a_form_from_another_org(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $formA = $this->makeForm($orgA);
        $formB = $this->makeForm($orgB);
        $admin = $this->makeOrgUser($orgA);

        $response = $this->actingAs($admin)->postJson(
            route('form.chain.links.store', $formA->form_id),
            ['next_form_id' => $formB->id]
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('form_chain_links', 0);
    }

    public function test_chain_link_creation_rejects_a_cycle(): void
    {
        $org = $this->makeOrg();
        $formA = $this->makeForm($org);
        $formB = $this->makeForm($org);
        $admin = $this->makeOrgUser($org);

        FormChainLink::create(['source_form_id' => $formA->id, 'next_form_id' => $formB->id]);

        $response = $this->actingAs($admin)->postJson(
            route('form.chain.links.store', $formB->form_id),
            ['next_form_id' => $formA->id]
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('form_chain_links', 1);
    }

    public function test_valid_from_submission_prefills_mapped_field_employee_and_vehicle(): void
    {
        $org = $this->makeOrg();
        $vehicle = Vehicle::create([
            'license_plate' => 'กข-1234',
            'brand' => 'Toyota',
            'org_id' => $org->id,
        ]);

        $sourceForm = $this->makeForm($org, ['select_user' => true, 'select_vehicle' => true]);
        $nextForm = $this->makeForm($org, ['select_user' => true, 'select_vehicle' => true]);

        $sourceField = $this->makeField($sourceForm, 'ชื่อคนขับ');
        $sourceDate = $this->makeField($sourceForm, 'วันที่', 'date', 1);
        $targetField = $this->makeField($nextForm, 'ชื่อคนขับ (ต่อเนื่อง)');
        $targetDate = $this->makeField($nextForm, 'วันที่ (ต่อเนื่อง)', 'date', 1);
        $unmappedTarget = $this->makeField($nextForm, 'ไม่ถูกจับคู่', 'text', 2);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $nextForm->id]);

        $link = FormChainLink::create([
            'source_form_id' => $sourceForm->id,
            'next_form_id' => $nextForm->id,
            'copy_selected_user' => true,
            'copy_selected_vehicle' => true,
        ]);
        $link->fieldMaps()->create(['target_field_id' => $targetField->id, 'source_field_id' => $sourceField->id]);
        $link->fieldMaps()->create(['target_field_id' => $targetDate->id, 'source_field_id' => $sourceDate->id]);

        $submission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);
        FormSubmissionValue::create(['submission_id' => $submission->id, 'field_id' => $sourceField->id, 'value' => 'สมชาย', 'submitted_by' => $user->id]);
        FormSubmissionValue::create(['submission_id' => $submission->id, 'field_id' => $sourceDate->id, 'value' => '2026-09-16', 'submitted_by' => $user->id]);

        $response = $this->actingAs($user)->get(
            route('document.fill-out', $nextForm->form_id) . '?from_submission=' . $submission->submission_id
        );

        $response->assertOk();
        $response->assertViewHas('prefilledValues', [
            $targetField->id => 'สมชาย',
            $targetDate->id => '2026-09-16',
        ]);
        $response->assertViewHas('prefilledUserId', $user->id);
        $response->assertViewHas('prefilledVehicleId', $vehicle->id);
        $response->assertViewHas('chainParentSubmission', $submission->submission_id);

        $viewPrefilled = $response->original->getData()['prefilledValues'];
        $this->assertArrayNotHasKey($unmappedTarget->id, $viewPrefilled);
    }

    public function test_from_submission_belonging_to_another_org_is_ignored_on_get(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');

        $sourceForm = $this->makeForm($orgB);
        $nextForm = $this->makeForm($orgA);
        $sourceField = $this->makeField($sourceForm, 'ข้อมูลลับ');
        $targetField = $this->makeField($nextForm, 'ปลายทาง');

        $foreignUser = $this->makeOrgUser($orgB, [$sourceForm->id]);
        $link = FormChainLink::create(['source_form_id' => $sourceForm->id, 'next_form_id' => $nextForm->id]);
        $link->fieldMaps()->create(['target_field_id' => $targetField->id, 'source_field_id' => $sourceField->id]);

        $foreignSubmission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $foreignUser->id,
            'org' => (string) $orgB->id,
        ]);
        FormSubmissionValue::create(['submission_id' => $foreignSubmission->id, 'field_id' => $sourceField->id, 'value' => 'ต้องไม่เห็นค่านี้', 'submitted_by' => $foreignUser->id]);

        $orgAUser = $this->makeOrgUser($orgA, [$nextForm->id]);

        $response = $this->actingAs($orgAUser)->get(
            route('document.fill-out', $nextForm->form_id) . '?from_submission=' . $foreignSubmission->submission_id
        );

        $response->assertOk();
        $response->assertViewHas('prefilledValues', []);
        $response->assertViewHas('chainParentSubmission', null);
    }

    public function test_opening_a_form_outside_the_users_org_is_rejected(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $foreignForm = $this->makeForm($orgB);
        // Simulates stale/forged PositionHasForm data pointing at a form outside the position's own org.
        $user = $this->makeOrgUser($orgA, [$foreignForm->id]);

        $response = $this->actingAs($user)->get(route('document.fill-out', $foreignForm->form_id));

        $response->assertNotFound();
    }

    public function test_forged_chain_parent_submission_is_rejected_on_submit(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $sourceForm = $this->makeForm($orgB);
        $nextForm = $this->makeForm($orgA);
        $targetField = $this->makeField($nextForm, 'ฟิลด์');

        $foreignUser = $this->makeOrgUser($orgB, [$sourceForm->id]);
        $foreignSubmission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $foreignUser->id,
            'org' => (string) $orgB->id,
        ]);

        $user = $this->makeOrgUser($orgA, [$nextForm->id]);

        $response = $this->actingAs($user)->postJson(route('document.submit', $nextForm->form_id), [
            'fieldsAns' => [['field_id' => $targetField->id, 'answer' => 'ค่า']],
            'chain_parent_submission' => $foreignSubmission->submission_id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('form_submissions', 1);
    }

    public function test_duplicate_child_submission_is_prevented(): void
    {
        $org = $this->makeOrg();
        $sourceForm = $this->makeForm($org);
        $nextForm = $this->makeForm($org);
        $targetField = $this->makeField($nextForm, 'ฟิลด์');
        FormChainLink::create(['source_form_id' => $sourceForm->id, 'next_form_id' => $nextForm->id]);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $nextForm->id]);
        $parentSubmission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $payload = [
            'fieldsAns' => [['field_id' => $targetField->id, 'answer' => 'ค่า']],
            'chain_parent_submission' => $parentSubmission->submission_id,
        ];

        $this->actingAs($user)->postJson(route('document.submit', $nextForm->form_id), $payload)
            ->assertOk();

        $response = $this->actingAs($user)->postJson(route('document.submit', $nextForm->form_id), $payload);

        $response->assertJson(['errors' => 'มีแบบฟอร์มต่อเนื่องนี้แล้ว']);
        $this->assertDatabaseCount('form_submissions', 2);
        $this->assertEquals(
            1,
            FormSubmissions::where('parent_submission_id', $parentSubmission->id)->where('form_id', $nextForm->id)->count()
        );
    }

    public function test_detail_action_hidden_without_permission_and_switches_to_view_after_child_exists(): void
    {
        $org = $this->makeOrg();
        $sourceForm = $this->makeForm($org);
        $allowedNextForm = $this->makeForm($org);
        $disabledNextForm = $this->makeForm($org, ['status' => false]);
        $noPermissionNextForm = $this->makeForm($org);

        FormChainLink::create(['source_form_id' => $sourceForm->id, 'next_form_id' => $allowedNextForm->id]);
        FormChainLink::create(['source_form_id' => $sourceForm->id, 'next_form_id' => $disabledNextForm->id]);
        FormChainLink::create(['source_form_id' => $sourceForm->id, 'next_form_id' => $noPermissionNextForm->id]);

        $user = $this->makeOrgUser($org, [$sourceForm->id, $allowedNextForm->id, $disabledNextForm->id]);

        $submission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $sourceForm->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $response = $this->actingAs($user)->get(route('document.submission.show', $submission->submission_id));
        $response->assertOk();

        $actions = collect($response->original->getData()['chainActions']);
        $this->assertCount(1, $actions);
        $this->assertSame($allowedNextForm->title, $actions->first()['title']);
        $this->assertNull($actions->first()['submission_id']);

        FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $allowedNextForm->id,
            'parent_submission_id' => $submission->id,
            'submitted_by' => $user->id,
            'org' => (string) $org->id,
        ]);

        $response = $this->actingAs($user)->get(route('document.submission.show', $submission->submission_id));
        $actions = collect($response->original->getData()['chainActions']);
        $this->assertNotNull($actions->first()['submission_id']);
    }
}
