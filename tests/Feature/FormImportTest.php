<?php

namespace Tests\Feature;

use App\Models\FormSubmissions;
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
}
