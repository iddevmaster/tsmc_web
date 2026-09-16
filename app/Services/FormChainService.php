<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormChainLink;
use App\Models\FormField;
use App\Models\FormSubmissions;
use Illuminate\Support\Facades\Auth;

class FormChainService
{
    public function currentOrgId(): ?string
    {
        return Auth::user()->is_tsm
            ? session('connected_org')
            : Auth::user()->userDetail?->org;
    }

    public function canFillForm(Form $form): bool
    {
        if (Auth::user()->is_tsm) {
            return true;
        }

        return (bool) Auth::user()->userDetail?->getPosition?->hasThisForm($form->id);
    }

    public function canAccessSubmission(FormSubmissions $submission): bool
    {
        $orgId = $this->currentOrgId();
        if (!$orgId || $submission->org !== $orgId) {
            return false;
        }
        if (Auth::user()->is_tsm) {
            return true;
        }

        $position = Auth::user()->userDetail?->getPosition;
        $canSeeAll = $position?->hasPermissionName('can_see_all_docs', $orgId);

        return (bool) $canSeeAll
            || $submission->submitted_by === Auth::id()
            || $submission->user_id === Auth::id();
    }

    public function answerableFields(Form $form)
    {
        $fields = $form->formFields()->where('type', '!=', 'subform')->get();
        $subformIds = $form->formFields()->where('type', 'subform')->pluck('subform_id')->filter();

        return $fields->concat(FormField::whereIn('form_id', $subformIds)->get());
    }

    public function wouldCreateChainCycle(int $sourceFormId, int $nextFormId): bool
    {
        $pending = [$nextFormId];
        $seen = [];

        while ($pending) {
            $current = array_pop($pending);
            if ($current === $sourceFormId) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $pending = array_merge($pending, FormChainLink::where('source_form_id', $current)
                ->pluck('next_form_id')
                ->all());
        }

        return false;
    }
}
