<?php

namespace App\Http\Controllers;

use App\Models\FieldOption;
use App\Models\Form;
use App\Models\Form_category;
use App\Models\FormField;
use App\Models\Position;
use App\Models\PositionHasForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FormController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    public function selectFormCategory()
    {
        $form_cates = Form_category::all();
        return view('form.selectFormCategory', compact('form_cates'));
    }

    public function showformTable($category_name)
    {
        try {
            if ($category_name === 'sub-form') {
                $forms = Form::where('is_sub_form', true)->where(function ($query) {
                    if (Auth()->user()->is_tsm) {
                        $query->where('org', session('connected_org') ?? '')->orWhere('created_by', Auth::user()->id);
                    } else {
                        $query->where('org', optional(Auth::user()->userDetail)->org ?? '')->orWhere('created_by', Auth::user()->id);
                    }

                })->get();
                return view('form.sub-form.formTable', compact('forms'));
            } else {
                $category = Form_category::where('name', $category_name)->firstOrFail();
                $forms = Form::where('category', $category->id)->where(function ($query) {
                    if (Auth()->user()->is_tsm) {
                        $query->where('org', session('connected_org') ?? '')
                        ->orWhere('created_by', Auth::user()->id)
                        ->orWhere('is_default', true);
                    } else {
                        $query->where('org', optional(Auth::user()->userDetail)->org ?? '')
                        ->orWhere('created_by', Auth::user()->id)
                        ->orWhere('is_default', true);
                    }

                })->get();
                return view('form.formTable', compact('forms', 'category_name'));
            }
        } catch (\Throwable $th) {
            //throw $th;
            return redirect()->back()->with('formTableError', 'ไม่พบหมวดหมู่ ' . $category_name);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create($form_category)
    {
        $sub_forms = Form::where('is_sub_form', true)->where(function ($query) {
            if (Auth()->user()->is_tsm) {
                $query->where('org', session('connected_org') ?? '')->orWhere('is_default', true);
            } else {
                $query->where('org', optional(Auth::user()->userDetail)->org ?? '')->orWhere('is_default', true);
            }
        })->get(['id', 'title']);
        if ($form_category === 'sub-form') {
            return view('form.sub-form.createForm');
        } else {
            return view('form.createForm', compact('form_category', 'sub_forms'));
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $form_category)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'fields' => 'required|array',
            'fields.*.type' => 'required|string|in:text,number,date,select,subform,job_number,autocomplete',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.options' => 'array|required_if:fields.*.type,select,autocomplete',
            'fields.*.subform_id' => 'required_if:fields.*.type,subform',
            'fields.*.options.*.value' => 'required_with:fields.*.options|string',
        ], [
            'fields.*.options.required_if' => 'กรุณาเพิ่มตัวเลือกในกรณีที่ประเภทของคำตอบเป็น ตัวเลือก',
            'fields.*.options.*.value.required_with' => 'กรุณาเพิ่มค่าในตัวเลือก',
            'fields.*.options.*.value.string' => 'ค่าของตัวเลือกต้องเป็นข้อความ',
            'fields.*.type.in' => 'ประเภทของรายการต้องเป็น ข้อความ, ตัวเลข, วันที่, แบบฟอร์มย่อย, ตัวเลือก, เลขที่งาน (Auto) หรือ ข้อความ+ตัวเลือก เท่านั้น',
            'fields.*.label.required' => 'กรุณาเพิ่มชื่อรายการ',
            'fields.*.label.string' => 'ชื่อรายการต้องเป็นข้อความ',
            'fields.required' => 'กรุณาเพิ่มรายการ',
            'title.required' => 'กรุณาเพิ่มชื่อฟอร์ม',
            'title.string' => 'ชื่อฟอร์มต้องเป็นข้อความ',
            'fields.*.subform_id.required_if' => 'กรุณาเลือกแบบฟอร์มย่อย',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        try {
            $form_category_target = Form_category::where('name', $form_category)->first();
            $org_id = Auth()->user()->is_tsm ? session('connected_org') : Auth()->user()->userDetail->org;
            $newForm = Form::create([
                'form_id' => Str::uuid(),
                'title' => $request->title,
                'category' => $form_category_target?->id,
                'select_user' => $request->select_user,
                'select_vehicle' => $request->select_vehicle,
                'org' => $org_id,
                'created_by' => $request->user()->id,
                'is_sub_form' => $request->is_sub_form,
                'is_default' => $request->user()->username === 'tsmcadmin' ? true : false,
            ]);

            foreach ($request['fields'] ?? [] as $index => $field) {
                $newField = FormField::create([
                    'form_id' => $newForm->id,
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'order_number' => $index,
                    'subform_id' => $field['subform_id'] ?? null,
                    'is_default' => $request->user()->username === 'tsmcadmin' ? true : false,
                ]);

                if (in_array($field['type'], ['select', 'autocomplete'])) {
                    foreach ($field['options'] ?? [] as $option) {
                        FieldOption::create([
                            'field_id' => $newField->id,
                            'value' => $option['value'],
                        ]);
                    }
                }
            }

            return response()->json(['success' => 'บันทึกข้อมูลสำเร็จ'], 200);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['errors' => 'บันทึกข้อมูลไม่สำเร็จ' . $th->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $form_category, string $form_id)
    {
        $form_data = Form::where('form_id', $form_id)->with('formFields')->firstOrFail();
        $sub_forms = Form::where('is_sub_form', true)->get(['id', 'title']);
        if ($form_category === 'sub-form') {
            return view('form.sub-form.editForm', compact('form_data'));
        } else {
            return view('form.editForm', compact('form_data', 'form_category', 'sub_forms'));
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $form_category, string $form_id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'fields' => 'required|array',
            'fields.*.type' => 'required|string|in:text,number,select,subform,date,job_number,autocomplete',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.options' => 'array|required_if:fields.*.type,select,autocomplete',
            'fields.*.subform_id' => 'required_if:fields.*.type,subform',
            'fields.*.options.*.value' => 'required_with:fields.*.options|string',
        ], [
            'fields.*.options.required_if' => 'กรุณาเพิ่มตัวเลือกในกรณีที่ประเภทของคำตอบเป็น ตัวเลือก',
            'fields.*.options.*.value.required_with' => 'กรุณาเพิ่มค่าในตัวเลือก',
            'fields.*.options.*.value.string' => 'ค่าของตัวเลือกต้องเป็นข้อความ',
            'fields.*.type.in' => 'ประเภทของรายการต้องเป็น ข้อความ, ตัวเลข, วันที่, แบบฟอร์มย่อย, ตัวเลือก, เลขที่งาน (Auto) หรือ ข้อความ+ตัวเลือก เท่านั้น',
            'fields.*.label.required' => 'กรุณาเพิ่มชื่อรายการ',
            'fields.*.label.string' => 'ชื่อรายการต้องเป็นข้อความ',
            'fields.required' => 'กรุณาเพิ่มรายการ',
            'title.required' => 'กรุณาเพิ่มชื่อฟอร์ม',
            'title.string' => 'ชื่อฟอร์มต้องเป็นข้อความ',
            'fields.*.subform_id.required_if' => 'กรุณาเลือกแบบฟอร์มย่อย',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        try {
            $formTarget = Form::where('form_id', $form_id)->firstOrFail();
            $formTarget->update([
                'title' => $request->title,
                'select_user' => $request->select_user,
                'select_vehicle' => $request->select_vehicle,
            ]);

            $fieldIds = [];
            $optionIds = [];

            foreach ($request['fields'] ?? [] as $index => $field) {
                if (FormField::where('id', $field['id'])->exists()) {
                    $fieldTarget = FormField::where('id', $field['id'])->first();
                    $fieldTarget->update([
                        'label' => $field['label'],
                        'type' => $field['type'],
                        'order_number' => $index,
                        'subform_id' => $field['subform_id'] ?? null,
                    ]);
                } else {
                    $fieldTarget = FormField::create([
                        'form_id' => $formTarget->id,
                        'label' => $field['label'],
                        'type' => $field['type'],
                        'order_number' => $index,
                        'subform_id' => $field['subform_id'] ?? null,
                    ]);
                }

                $fieldIds[] = $fieldTarget->id;

                if (in_array($field['type'], ['select', 'autocomplete'])) {
                    foreach ($field['options'] ?? [] as $option) {
                        if (FieldOption::where('id', $option['id'])->exists()) {
                            $optionTarget = FieldOption::where('id', $option['id'])->first();
                            $optionTarget->update([
                                'value' => $option['value'],
                            ]);
                        } else {
                            $optionTarget = FieldOption::create([
                                'field_id' => $fieldTarget->id,
                                'value' => $option['value'],
                            ]);
                        }

                        $optionIds[] = $optionTarget->id;
                    }
                    FieldOption::where('field_id', $fieldTarget->id)->whereNotIn('id', $optionIds)->delete();
                }
            }
            FormField::where('form_id', $formTarget->id)->whereNotIn('id', $fieldIds)->delete();

            return response()->json(['success' => 'บันทึกข้อมูลสำเร็จ'], 200);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['errors' => 'บันทึกข้อมูลไม่สำเร็จ'], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $form_category, string $id)
    {
        try {
            Form::findOrFail($id)->delete();
            return response()->json(['success'=> 'ลบแบบฟอร์มสำเร็จ']);
        } catch (\Throwable $th) {
            return response()->json(['error'=> "เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง"]);
        }
    }

    public function duplicate(string $form_category, string $id)
    {
        try {
            $originalForm = Form::with('formFields')->findOrFail($id);
            $org_id = Auth()->user()->is_tsm ? session('connected_org') : Auth()->user()->userDetail->org;

            $newForm = Form::create([
                'form_id' => Str::uuid(),
                'title' => $originalForm->title . ' (Copy)',
                'category' => $originalForm->category,
                'select_user' => $originalForm->select_user,
                'select_vehicle' => $originalForm->select_vehicle,
                'has_approve' => $originalForm->has_approve,
                'org' => $org_id,
                'created_by' => Auth::user()->id,
                'status' => false,
                'is_sub_form' => $originalForm->is_sub_form,
                'is_default' => Auth::user()->username === 'tsmcadmin' ? true : false,
            ]);

            foreach ($originalForm->formFields as $field) {
                $newField = FormField::create([
                    'form_id' => $newForm->id,
                    'label' => $field->label,
                    'type' => $field->type,
                    'subform_id' => $field->subform_id,
                    'required' => $field->required,
                    'order_number' => $field->order_number,
                    'is_default' => $newForm->is_default,
                ]);

                foreach ($field->options as $option) {
                    FieldOption::create([
                        'field_id' => $newField->id,
                        'value' => $option->value,
                    ]);
                }
            }

            foreach ($originalForm->hasPosition as $positionLink) {
                PositionHasForm::create([
                    'position_id' => $positionLink->position_id,
                    'form_id' => $newForm->id,
                ]);
            }

            return response()->json([
                'success' => 'คัดลอกแบบฟอร์มสำเร็จ',
                'form_id' => $newForm->form_id,
            ]);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['errors' => 'คัดลอกแบบฟอร์มไม่สำเร็จ'], 500);
        }
    }

    public function formPerm(string $form_id)
    {
        $form_data = Form::where('form_id', $form_id)->firstOrFail();
        $org_id = Auth()->user()->is_tsm ? session('connected_org') : Auth()->user()->userDetail->org;
        $positions = Position::where('org', $org_id ?? '')->get();
        return view('form.formPermission', compact('form_data', 'positions'));
    }

    public function formSetPerm(Request $request)
    {
        try {
            if ($request->is_checked) {
                PositionHasForm::create([
                    'position_id' => $request->position_id,
                    'form_id' => $request->form_id,
                ]);
            } else {
                PositionHasForm::where('position_id', $request->position_id)->where('form_id', $request->form_id)->delete();
            }
            return response()->json(['success' => 'บันทึกข้อมูลสำเร็จ'], 200);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['errors' => 'บันทึกข้อมูลไม่สำเร็จ'], 500);
        }
    }
}
