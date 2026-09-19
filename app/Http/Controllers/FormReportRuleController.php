<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormReportRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormReportRuleController extends Controller
{
    private const ALLOWED_FIELD_TYPES = ['select', 'autocomplete', 'text', 'number', 'date'];

    public function edit(string $form_id)
    {
        $form = $this->guardedForm($form_id);
        $rules = $form->reportRules()->with(['conditionField', 'distinctField'])->get();
        $catalog = config('mandatory_report.sections', []);
        $availableFields = $form->formFields()
            ->whereIn('type', self::ALLOWED_FIELD_TYPES)
            ->get();
        $categoryName = $form->formCategory?->name;

        return view('form.formReportRules', compact('form', 'rules', 'catalog', 'availableFields', 'categoryName'));
    }

    public function store(Request $request, string $form_id)
    {
        $form = $this->guardedForm($form_id);
        $validated = $this->validateRule($request, $form);

        try {
            FormReportRule::create([...$validated, 'form_id' => $form->id]);

            return response()->json(['success' => 'เพิ่มรายการรายงานสำเร็จ']);
        } catch (\Throwable) {
            return response()->json(['errors' => 'เพิ่มรายการรายงานไม่สำเร็จ'], 422);
        }
    }

    public function update(Request $request, string $form_id, int $rule)
    {
        $form = $this->guardedForm($form_id);
        $ruleModel = $form->reportRules()->findOrFail($rule);
        $validated = $this->validateRule($request, $form, $ruleModel);

        try {
            $ruleModel->update($validated);

            return response()->json(['success' => 'แก้ไขรายการรายงานสำเร็จ']);
        } catch (\Throwable) {
            return response()->json(['errors' => 'แก้ไขรายการรายงานไม่สำเร็จ'], 422);
        }
    }

    public function destroy(string $form_id, int $rule)
    {
        $form = $this->guardedForm($form_id);
        $form->reportRules()->findOrFail($rule)->delete();

        return response()->json(['success' => 'ลบรายการรายงานสำเร็จ']);
    }

    private function validateRule(Request $request, Form $form, ?FormReportRule $current = null): array
    {
        $itemCodes = collect(config('mandatory_report.sections', []))
            ->flatMap(fn (array $section) => array_keys($section['items']))
            ->all();

        $uniqueItem = Rule::unique('form_report_rules', 'item_code')
            ->where(fn ($query) => $query->where('form_id', $form->id));
        if ($current) {
            $uniqueItem->ignore($current->id);
        }

        $data = $request->validate([
            'item_code' => ['required', Rule::in($itemCodes), $uniqueItem],
            'condition_field_id' => ['nullable', 'integer'],
            'condition_values' => ['nullable', 'array'],
            'condition_values.*' => ['string'],
            'distinct_by' => ['required', Rule::in(['user', 'vehicle', 'field', 'none'])],
            'distinct_field_id' => ['nullable', 'integer', 'required_if:distinct_by,field'],
        ], [
            'item_code.unique' => 'รายการนี้ถูกผูกกับฟอร์มนี้แล้ว',
            'distinct_field_id.required_if' => 'กรุณาเลือกช่องที่ใช้นับไม่ซ้ำ',
        ]);

        foreach (['condition_field_id', 'distinct_field_id'] as $fieldKey) {
            if (!empty($data[$fieldKey])) {
                $field = FormField::where('id', $data[$fieldKey])
                    ->where('form_id', $form->id)
                    ->first();

                if (!$field) {
                    throw ValidationException::withMessages([$fieldKey => 'ช่องที่เลือกไม่ใช่ของฟอร์มนี้']);
                }
                if (!in_array($field->type, self::ALLOWED_FIELD_TYPES, true)) {
                    throw ValidationException::withMessages([$fieldKey => 'ประเภทช่องที่เลือกไม่รองรับสำหรับรายงาน']);
                }
            }
        }

        if ($data['distinct_by'] === 'user' && !$form->select_user) {
            throw ValidationException::withMessages(['distinct_by' => 'ฟอร์มนี้ไม่ได้เปิดใช้การเลือกผู้ใช้']);
        }
        if ($data['distinct_by'] === 'vehicle' && !$form->select_vehicle) {
            throw ValidationException::withMessages(['distinct_by' => 'ฟอร์มนี้ไม่ได้เปิดใช้การเลือกรถ']);
        }

        if ($data['distinct_by'] !== 'field') {
            $data['distinct_field_id'] = null;
        }
        if (empty($data['condition_field_id'])) {
            $data['condition_values'] = null;
        }

        return $data;
    }

    private function guardedForm(string $formId): Form
    {
        $user = Auth::user();
        $orgId = $user->is_tsm ? session('connected_org') : $user->userDetail?->org;

        return Form::where('form_id', $formId)
            ->where('is_sub_form', false)
            ->when($user->username !== 'tsmcadmin', fn ($query) => $query->where('is_default', false))
            ->where(function ($query) use ($orgId, $user) {
                $query->where('org', $orgId);
                if ($user->username === 'tsmcadmin') {
                    $query->orWhere('is_default', true);
                }
            })
            ->firstOrFail();
    }
}
