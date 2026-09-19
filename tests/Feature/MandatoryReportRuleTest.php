<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormReportRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFormTestData;
use Tests\TestCase;

class MandatoryReportRuleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsFormTestData;

    public function test_admin_can_view_rule_settings_for_own_org_form(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $admin = $this->makeOrgUser($org);

        $response = $this->actingAs($admin)->get(route('form.report-rules.edit', $form->form_id));

        $response->assertOk()->assertViewIs('form.formReportRules');
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

    public function test_duplicate_item_code_is_rejected(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $admin = $this->makeOrgUser($org);
        FormReportRule::create(['form_id' => $form->id, 'item_code' => 'crew_duty_assignment', 'distinct_by' => 'none']);

        $response = $this->actingAs($admin)->postJson(route('form.report-rules.store', $form->form_id), [
            'item_code' => 'crew_duty_assignment', 'distinct_by' => 'none',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('form_report_rules', 1);
    }

    public function test_field_from_another_form_is_rejected(): void
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

    public function test_invalid_distinct_field_type_is_rejected(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $field = $this->makeField($form, 'ฟอร์มย่อย', 'subform');
        $admin = $this->makeOrgUser($org);

        $response = $this->actingAs($admin)->postJson(route('form.report-rules.store', $form->form_id), [
            'item_code' => 'crew_duty_assignment',
            'distinct_by' => 'field',
            'distinct_field_id' => $field->id,
        ]);

        $response->assertStatus(422);
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

    public function test_updating_a_rule_changes_its_distinct_strategy(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org, ['select_user' => true]);
        $admin = $this->makeOrgUser($org);
        $rule = FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'crew_duty_assignment',
            'distinct_by' => 'none',
        ]);

        $response = $this->actingAs($admin)->putJson(route('form.report-rules.update', [$form->form_id, $rule->id]), [
            'item_code' => 'crew_duty_assignment',
            'distinct_by' => 'user',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('form_report_rules', ['id' => $rule->id, 'distinct_by' => 'user']);
    }

    public function test_cannot_manage_rules_on_another_org_form(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $formB = $this->makeForm($orgB);
        $adminA = $this->makeOrgUser($orgA);

        $response = $this->actingAs($adminA)->get(route('form.report-rules.edit', $formB->form_id));

        $response->assertNotFound();
    }

    public function test_non_tsm_user_cannot_manage_default_form_rules(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org, ['is_default' => true]);
        $admin = $this->makeOrgUser($org);

        $this->actingAs($admin)->get(route('form.report-rules.edit', $form->form_id))->assertNotFound();
    }

    public function test_duplicating_a_form_copies_rules_with_remapped_field_ids(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org, ['select_user' => true]);
        $conditionField = $this->makeField($form, 'การใช้เครื่องตรวจวัดแอลกอฮอล์', 'select');
        $this->addFieldOption($conditionField, 'ตรวจ');
        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'crew_alcohol_test',
            'condition_field_id' => $conditionField->id,
            'condition_values' => ['ตรวจ'],
            'distinct_by' => 'user',
        ]);
        $admin = $this->makeOrgUser($org);

        $response = $this->actingAs($admin)->postJson(route('form.duplicate', ['general', $form->id]));
        $response->assertOk();

        $newForm = Form::where('form_id', $response->json('form_id'))->firstOrFail();
        $newRule = FormReportRule::where('form_id', $newForm->id)->firstOrFail();
        $newField = FormField::findOrFail($newRule->condition_field_id);

        $this->assertDatabaseCount('form_report_rules', 2);
        $this->assertSame(['ตรวจ'], $newRule->condition_values);
        $this->assertSame('user', $newRule->distinct_by);
        $this->assertSame($newForm->id, $newField->form_id);
        $this->assertNotSame($conditionField->id, $newField->id);
    }
}
