<?php

namespace Tests\Concerns;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FieldOption;
use App\Models\FormSubmissionValue;
use App\Models\FormSubmissions;
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

    private function addFieldOption(FormField $field, string $value): FieldOption
    {
        return FieldOption::create([
            'field_id' => $field->id,
            'value' => $value,
        ]);
    }

    private function makeSubmission(Form $form, Organization $org, array $overrides = []): FormSubmissions
    {
        return FormSubmissions::create(array_merge([
            'submission_id' => Str::uuid(),
            'form_id' => $form->id,
            'submitted_by' => null,
            'org' => (string) $org->id,
        ], $overrides));
    }

    private function setSubmissionValue(FormSubmissions $submission, FormField $field, string $value): FormSubmissionValue
    {
        return FormSubmissionValue::create([
            'submission_id' => $submission->id,
            'field_id' => $field->id,
            'value' => $value,
            'submitted_by' => $submission->submitted_by,
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
