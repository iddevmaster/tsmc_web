<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmissions;
use App\Models\User;
use Illuminate\Support\Collection;

class FormImportService
{
    public function __construct(private FormChainService $chainService)
    {
    }

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
            ->whereBetween('created_at', [now()->startOfDay(), now()])
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
                    $sourceForm
                        ? $this->fieldSignatures($this->chainService->answerableFields($sourceForm))
                        : collect(),
                ];
            }

            [$sourceForm, $sourceSignatures] = $formCache[$submission->form_id];
            if (!$sourceForm || $sourceSignatures->intersect($targetSignatures)->isEmpty()) {
                continue;
            }

            $submitter = User::with('userDetail')->find($submission->submitted_by);
            $employee = User::with('userDetail')->find($submission->user_id);

            $candidates->push([
                'submission_id' => (string) $submission->submission_id,
                'form_title' => $sourceForm->title,
                'submitted_at' => $submission->created_at->format('H:i'),
                'submitted_by_name' => $this->userDisplayName($submitter),
                'employee_name' => $this->userDisplayName($employee),
                'vehicle_label' => $submission->getVehicle?->license_plate,
            ]);
        }

        return $candidates->values();
    }

    private function fieldSignatures(Collection $fields): Collection
    {
        return $fields
            ->map(fn (FormField $field) => trim($field->label) . '|' . $field->type)
            ->unique()
            ->values();
    }

    private function userDisplayName(?User $user): ?string
    {
        if (!$user?->userDetail) {
            return null;
        }

        $name = trim($user->userDetail->fname . ' ' . $user->userDetail->lname);
        return $name !== '' ? $name : null;
    }
}
