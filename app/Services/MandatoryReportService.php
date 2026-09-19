<?php

namespace App\Services;

use App\Models\FormReportRule;
use App\Models\FormSubmissions;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MandatoryReportService
{
    public function build(?string $orgId, int $quarter, int $year): array
    {
        $catalog = config('mandatory_report.sections', []);
        $result = $this->emptyReport($catalog);
        if ($orgId === null) {
            return $result;
        }

        $itemCodes = collect($catalog)->flatMap(fn (array $section) => array_keys($section['items']))->values();

        [$start, $end] = $this->quarterBounds($quarter, $year);

        $rules = FormReportRule::query()
            ->whereIn('item_code', $itemCodes)
            ->whereHas('form', function ($query) use ($orgId) {
                $query->where('is_sub_form', false)
                    ->where(function ($formQuery) use ($orgId) {
                        $formQuery->where('is_default', true);
                        if ($orgId !== null) {
                            $formQuery->orWhere('org', $orgId);
                        }
                    });
            })
            ->with(['form', 'conditionField', 'distinctField'])
            ->get();

        $aggregate = [];

        foreach ($rules as $rule) {
            $aggregate[$rule->item_code] ??= [
                'submission_ids' => [],
                'unit_keys' => [],
                'counts_units' => false,
                'sources' => [],
            ];

            $aggregate[$rule->item_code]['sources'][$rule->form->title] = true;
            $aggregate[$rule->item_code]['counts_units'] = $aggregate[$rule->item_code]['counts_units']
                || $rule->distinct_by !== 'none';

            foreach ($this->matchingSubmissions($rule, $orgId, $start, $end) as $match) {
                $aggregate[$rule->item_code]['submission_ids'][(string) $match->submission_id] = true;

                if ($match->unit_key !== null && trim((string) $match->unit_key) !== '') {
                    $aggregate[$rule->item_code]['unit_keys'][(string) $match->unit_key] = true;
                }
            }
        }

        foreach ($aggregate as $itemCode => $data) {
            $sectionKey = $this->findSectionKey($catalog, $itemCode);
            if ($sectionKey === null) {
                continue;
            }

            $result[$sectionKey]['items'][$itemCode]['times'] = count($data['submission_ids']);
            $result[$sectionKey]['items'][$itemCode]['units'] = $data['counts_units']
                ? count($data['unit_keys'])
                : null;
            $result[$sectionKey]['items'][$itemCode]['sources'] = array_keys($data['sources']);
        }

        return $result;
    }

    private function emptyReport(array $catalog): array
    {
        $result = [];

        foreach ($catalog as $sectionKey => $section) {
            $result[$sectionKey] = [
                'label' => $section['label'],
                'items' => [],
            ];

            foreach ($section['items'] as $itemCode => $item) {
                $result[$sectionKey]['items'][$itemCode] = [
                    'label' => $item['label'],
                    'unit' => $item['unit'],
                    'times' => null,
                    'units' => null,
                    'sources' => [],
                ];
            }
        }

        return $result;
    }

    private function matchingSubmissions(FormReportRule $rule, ?string $orgId, Carbon $start, Carbon $end): Collection
    {
        if ($rule->condition_field_id !== null && $this->isDeleted($rule->conditionField)) {
            return collect();
        }

        $query = FormSubmissions::query()
            ->where('form_submissions.form_id', $rule->form_id)
            ->where('form_submissions.org', $orgId)
            ->whereBetween('form_submissions.created_at', [$start, $end]);

        if ($rule->condition_field_id !== null) {
            $query->whereExists(function ($subQuery) use ($rule) {
                $subQuery->selectRaw('1')
                    ->from('form_submission_values')
                    ->whereColumn('form_submission_values.submission_id', 'form_submissions.id')
                    ->where('form_submission_values.field_id', $rule->condition_field_id)
                    ->whereNotNull('form_submission_values.value')
                    ->where('form_submission_values.value', '!=', '');

                if (!empty($rule->condition_values)) {
                    $subQuery->whereIn('form_submission_values.value', $rule->condition_values);
                }
            });
        }

        if ($rule->distinct_by === 'field' && $this->isDeleted($rule->distinctField)) {
            return $query->get(['form_submissions.id as submission_id'])
                ->map(fn ($row) => (object) ['submission_id' => $row->submission_id, 'unit_key' => null]);
        }

        return match ($rule->distinct_by) {
            'user' => $query->get(['form_submissions.id as submission_id', 'form_submissions.user_id as unit_key']),
            'vehicle' => $query->get(['form_submissions.id as submission_id', 'form_submissions.vehicle_id as unit_key']),
            'field' => $query
                ->leftJoin('form_submission_values as distinct_values', function ($join) use ($rule) {
                    $join->on('distinct_values.submission_id', '=', 'form_submissions.id')
                        ->where('distinct_values.field_id', $rule->distinct_field_id);
                })
                ->get([
                    'form_submissions.id as submission_id',
                    DB::raw('TRIM(distinct_values.value) as unit_key'),
                ]),
            default => $query->get(['form_submissions.id as submission_id'])
                ->map(fn ($row) => (object) ['submission_id' => $row->submission_id, 'unit_key' => null]),
        };
    }

    private function isDeleted($field): bool
    {
        return $field === null || ($field->trashed() ?? false);
    }

    private function quarterBounds(int $quarter, int $year): array
    {
        $start = Carbon::create($year, (($quarter - 1) * 3) + 1, 1)->startOfDay();
        $end = (clone $start)->addMonths(3)->subMicrosecond();

        return [$start, $end];
    }

    private function findSectionKey(array $catalog, string $itemCode): ?string
    {
        foreach ($catalog as $sectionKey => $section) {
            if (array_key_exists($itemCode, $section['items'])) {
                return $sectionKey;
            }
        }

        return null;
    }
}
