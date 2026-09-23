<?php

namespace Tests\Feature;

use App\Models\FormSubmissionHistory;
use App\Models\User_detail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFormTestData;
use Tests\TestCase;

class SubmissionDeleteTest extends TestCase
{
    use BuildsFormTestData;
    use RefreshDatabase;

    public function test_tsmcadmin_can_view_all_submissions_across_orgs(): void
    {
        $admin = $this->makeTsmcAdmin();
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $formA = $this->makeForm($orgA, ['title' => 'Form A']);
        $formB = $this->makeForm($orgB, ['title' => 'Form B']);
        $this->makeSubmission($formA, $orgA);
        $this->makeSubmission($formB, $orgB);

        $response = $this->actingAs($admin)->get(route('document.submissions.index'));

        $response->assertOk();
        $response->assertSee('Form A');
        $response->assertSee('Form B');
        $response->assertSee('Org A');
        $response->assertSee('Org B');
    }

    public function test_non_tsmcadmin_cannot_view_submissions_list(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeOrgUser($org);

        $response = $this->actingAs($user)->get(route('document.submissions.index'));

        $response->assertForbidden();
    }

    public function test_submissions_list_filters_by_form(): void
    {
        $admin = $this->makeTsmcAdmin();
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $formA = $this->makeForm($orgA, ['title' => 'Form A']);
        $formB = $this->makeForm($orgB, ['title' => 'Form B']);
        $this->makeSubmission($formA, $orgA);
        $this->makeSubmission($formB, $orgB);

        $response = $this->actingAs($admin)->get(route('document.submissions.index', [
            'form_id' => $formA->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('submissions', function ($submissions) use ($formA) {
            return $submissions->total() === 1
                && $submissions->first()->form_id === $formA->id;
        });
    }

    public function test_submissions_list_filters_by_org(): void
    {
        $admin = $this->makeTsmcAdmin();
        $orgA = $this->makeOrg('Org A');
        $orgB = $this->makeOrg('Org B');
        $formA = $this->makeForm($orgA, ['title' => 'Form A']);
        $formB = $this->makeForm($orgB, ['title' => 'Form B']);
        $this->makeSubmission($formA, $orgA);
        $this->makeSubmission($formB, $orgB);

        $response = $this->actingAs($admin)->get(route('document.submissions.index', [
            'org' => $orgB->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('submissions', function ($submissions) use ($orgB) {
            return $submissions->total() === 1
                && $submissions->first()->org === (string) $orgB->id;
        });
    }

    public function test_submissions_list_filters_by_created_date_inclusively(): void
    {
        $admin = $this->makeTsmcAdmin();
        $org = $this->makeOrg();
        $oldForm = $this->makeForm($org, ['title' => 'Old Form']);
        $newForm = $this->makeForm($org, ['title' => 'New Form']);
        $oldSubmission = $this->makeSubmission($oldForm, $org);
        $this->makeSubmission($newForm, $org);
        $oldSubmission->update(['created_at' => now()->subDays(10)]);

        $response = $this->actingAs($admin)->get(route('document.submissions.index', [
            'date_from' => now()->subDays(5)->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('submissions', function ($submissions) use ($newForm) {
            return $submissions->total() === 1
                && $submissions->first()->form_id === $newForm->id;
        });
    }

    public function test_tsmcadmin_can_soft_delete_a_submission_and_keep_child_data(): void
    {
        $admin = $this->makeTsmcAdmin();
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        $field = $this->makeField($form, 'ฟิลด์');
        $submission = $this->makeSubmission($form, $org, ['submitted_by' => $admin->id]);
        $this->setSubmissionValue($submission, $field, 'ค่า');
        FormSubmissionHistory::create([
            'submission_id' => $submission->id,
            'user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('document.submissions.index'))
            ->delete(route('document.submissions.destroy', $submission->submission_id));

        $response->assertRedirect(route('document.submissions.index'));
        $response->assertSessionHas('success', 'ลบแบบฟอร์มที่ส่งแล้วเรียบร้อย');
        $this->assertSoftDeleted('form_submissions', ['id' => $submission->id]);
        $this->assertDatabaseHas('form_submission_values', ['submission_id' => $submission->id]);
        $this->assertDatabaseHas('form_submission_histories', ['submission_id' => $submission->id]);
    }

    public function test_non_tsmcadmin_cannot_delete_a_submission(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeOrgUser($org);
        $form = $this->makeForm($org);
        $submission = $this->makeSubmission($form, $org);

        $response = $this->actingAs($user)
            ->delete(route('document.submissions.destroy', $submission->submission_id));

        $response->assertForbidden();
        $this->assertDatabaseHas('form_submissions', [
            'id' => $submission->id,
            'deleted_at' => null,
        ]);
    }

    public function test_deleted_submission_is_hidden_from_the_default_list(): void
    {
        $admin = $this->makeTsmcAdmin();
        $org = $this->makeOrg();
        $deletedForm = $this->makeForm($org, ['title' => 'Deleted Form']);
        $keptForm = $this->makeForm($org, ['title' => 'Kept Form']);
        $deletedSubmission = $this->makeSubmission($deletedForm, $org);
        $keptSubmission = $this->makeSubmission($keptForm, $org);

        $this->actingAs($admin)
            ->from(route('document.submissions.index'))
            ->delete(route('document.submissions.destroy', $deletedSubmission->submission_id))
            ->assertRedirect(route('document.submissions.index'));

        $response = $this->actingAs($admin)->get(route('document.submissions.index'));

        $response->assertOk();
        $response->assertViewHas('submissions', function ($submissions) use ($keptSubmission) {
            return $submissions->total() === 1
                && $submissions->first()->id === $keptSubmission->id;
        });
    }

    public function test_soft_deleted_submission_is_excluded_from_quarterly_report_count(): void
    {
        $admin = $this->makeTsmcAdmin();
        $org = $this->makeOrg();
        $form = $this->makeForm($org);
        User_detail::where('user_id', $admin->id)->update(['org' => (string) $org->id]);
        $submission = $this->makeSubmission($form, $org);
        $quarter = (int) ceil(now()->month / 3);

        $this->actingAs($admin);
        $this->assertSame(1, $form->countFromSubmissionByQuarter($quarter, $form->id));

        $submission->delete();

        $this->assertSame(0, $form->countFromSubmissionByQuarter($quarter, $form->id));
    }
}
