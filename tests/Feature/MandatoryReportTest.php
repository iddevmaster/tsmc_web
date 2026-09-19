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
        FormReportRule::create(['form_id' => $form->id, 'item_code' => 'crew_duty_assignment', 'distinct_by' => 'user']);

        $userA = $this->makeOrgUser($org, [$form->id]);
        $userB = $this->makeOrgUser($org, [$form->id]);
        $this->makeSubmission($form, $org, ['user_id' => $userA->id]);
        $this->makeSubmission($form, $org, ['user_id' => $userA->id]);
        $this->makeSubmission($form, $org, ['user_id' => $userB->id]);

        $item = $this->service()->build((string) $org->id, now()->quarter, now()->year)['crew']['items']['crew_duty_assignment'];

        $this->assertSame(3, $item['times']);
        $this->assertSame(2, $item['units']);
        $this->assertSame([$form->title], $item['sources']);
    }

    public function test_condition_field_only_counts_submissions_with_matching_value(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $field = $this->makeField($form, 'การใช้เครื่องตรวจวัดแอลกอฮอล์', 'select');
        $this->addFieldOption($field, 'ตรวจ');
        $this->addFieldOption($field, 'ไม่ได้ตรวจ');
        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'crew_alcohol_test',
            'condition_field_id' => $field->id,
            'condition_values' => ['ตรวจ'],
            'distinct_by' => 'none',
        ]);

        $tested = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($tested, $field, 'ตรวจ');
        $untested = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($untested, $field, 'ไม่ได้ตรวจ');

        $item = $this->service()->build((string) $org->id, now()->quarter, now()->year)['crew']['items']['crew_alcohol_test'];

        $this->assertSame(1, $item['times']);
        $this->assertNull($item['units']);
    }

    public function test_condition_field_with_no_values_requires_a_non_empty_answer(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $field = $this->makeField($form, 'บันทึกเพิ่มเติม');
        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'operation_transport_data_record',
            'condition_field_id' => $field->id,
            'distinct_by' => 'none',
        ]);

        $answered = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($answered, $field, 'มีบันทึก');
        $blank = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($blank, $field, '');

        $item = $this->service()->build((string) $org->id, now()->quarter, now()->year)['operation']['items']['operation_transport_data_record'];

        $this->assertSame(1, $item['times']);
    }

    public function test_two_forms_combine_times_and_dedupe_units(): void
    {
        $org = $this->makeOrg();
        $first = $this->makeForm($org, ['title' => 'ตรวจความพร้อมรถโดยสาร']);
        $second = $this->makeForm($org, ['title' => 'ตรวจความพร้อมรถบรรทุก']);
        FormReportRule::create(['form_id' => $first->id, 'item_code' => 'vehicle_readiness_check', 'distinct_by' => 'user']);
        FormReportRule::create(['form_id' => $second->id, 'item_code' => 'vehicle_readiness_check', 'distinct_by' => 'user']);

        $user = $this->makeOrgUser($org, [$first->id, $second->id]);
        $this->makeSubmission($first, $org, ['user_id' => $user->id]);
        $this->makeSubmission($second, $org, ['user_id' => $user->id]);

        $item = $this->service()->build((string) $org->id, now()->quarter, now()->year)['vehicle']['items']['vehicle_readiness_check'];

        $this->assertSame(2, $item['times']);
        $this->assertSame(1, $item['units']);
        $this->assertEqualsCanonicalizing([$first->title, $second->title], $item['sources']);
    }

    public function test_submissions_outside_quarter_or_other_org_are_excluded(): void
    {
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $form = $this->makeForm($orgA);
        FormReportRule::create(['form_id' => $form->id, 'item_code' => 'crew_duty_assignment', 'distinct_by' => 'none']);

        $this->makeSubmission($form, $orgA, ['created_at' => now()->startOfYear()->subDay()]);
        $this->makeSubmission($form, $orgB);
        $this->makeSubmission($form, $orgA);

        $item = $this->service()->build((string) $orgA->id, now()->quarter, now()->year)['crew']['items']['crew_duty_assignment'];

        $this->assertSame(1, $item['times']);
    }

    public function test_distinct_by_field_counts_trimmed_unique_values(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $field = $this->makeField($form, 'เส้นทาง');
        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'operation_trip_plan',
            'distinct_by' => 'field',
            'distinct_field_id' => $field->id,
        ]);

        $one = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($one, $field, 'กรุงเทพ-เชียงใหม่');
        $two = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($two, $field, ' กรุงเทพ-เชียงใหม่ ');
        $three = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($three, $field, 'กรุงเทพ-ขอนแก่น');

        $item = $this->service()->build((string) $org->id, now()->quarter, now()->year)['operation']['items']['operation_trip_plan'];

        $this->assertSame(3, $item['times']);
        $this->assertSame(2, $item['units']);
    }

    public function test_deleted_condition_field_contributes_zero(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $field = $this->makeField($form, 'ตรวจสารเสพติด', 'select');
        $this->addFieldOption($field, 'ตรวจ');
        FormReportRule::create([
            'form_id' => $form->id,
            'item_code' => 'crew_drug_test',
            'condition_field_id' => $field->id,
            'condition_values' => ['ตรวจ'],
            'distinct_by' => 'none',
        ]);
        $submission = $this->makeSubmission($form, $org);
        $this->setSubmissionValue($submission, $field, 'ตรวจ');
        $field->delete();

        $item = $this->service()->build((string) $org->id, now()->quarter, now()->year)['crew']['items']['crew_drug_test'];

        $this->assertSame(0, $item['times']);
    }

    public function test_item_with_no_rule_reports_null(): void
    {
        $org = $this->makeOrg();
        $item = $this->service()->build((string) $org->id, now()->quarter, now()->year)['crew']['items']['crew_duty_assignment'];

        $this->assertNull($item['times']);
        $this->assertNull($item['units']);
        $this->assertSame([], $item['sources']);
    }

    public function test_report_page_renders_for_the_selected_quarter(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeOrgUser($org);
        $response = $this->actingAs($user)->get(route('mandatory.report', ['quarter' => now()->quarter]));

        $response->assertOk();
        $response->assertViewIs('exportDocument.mandatoryReport');
        $response->assertSee('การกำหนดหน้าที่และความรับผิดชอบของผู้ประจำรถ', false);
        $response->assertSee('รายงานภาคบังคับ', false);
    }
}
