<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Form_category;
use App\Models\FormChainLink;
use App\Models\FormSubmissionHistory;
use App\Models\FormSubmissions;
use App\Models\FormSubmissionValue;
use App\Models\Organization;
use App\Models\User_detail;
use App\Models\Vehicle;
use App\Services\FormChainService;
use App\Services\FormImportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function __construct(
        private FormChainService $chainService,
        private FormImportService $importService,
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    public function selectForm() {
        $categories = Form_category::all();
        return view('form.checking.selectForm', compact('categories'));
    }
    public function selectTableForm() {
        $categories = Form_category::all();
        return view('form.table.selectForm', compact('categories'));
    }

    public function fillOutForm($form_id) {
        $org_id = $this->chainService->currentOrgId() ?? '';

        $form_data = Form::where('form_id', $form_id)
            ->where(function ($query) use ($org_id) {
                $query->where('org', $org_id)->orWhere('is_default', true);
            })
            ->firstOrFail();
        abort_unless($this->chainService->canFillForm($form_data), 403);

        $prefilledValues = [];
        $prefilledUserId = null;
        $prefilledVehicleId = null;
        $chainParentSubmission = null;

        if ($requestParentId = request('from_submission')) {
            $parentSubmission = FormSubmissions::where('submission_id', $requestParentId)->first();
            $chainLink = $parentSubmission && $this->chainService->canAccessSubmission($parentSubmission)
                ? FormChainLink::with(['fieldMaps.sourceField', 'fieldMaps.targetField'])
                    ->where('source_form_id', $parentSubmission->form_id)
                    ->where('next_form_id', $form_data->id)
                    ->first()
                : null;

            if ($chainLink) {
                $parentValues = FormSubmissionValue::where('submission_id', $parentSubmission->id)
                    ->pluck('value', 'field_id');

                foreach ($chainLink->fieldMaps as $map) {
                    if ($map->sourceField && $map->targetField && $parentValues->has($map->source_field_id)) {
                        $prefilledValues[$map->target_field_id] = $parentValues->get($map->source_field_id);
                    }
                }

                $prefilledUserId = $chainLink->copy_selected_user ? $parentSubmission->user_id : null;
                $prefilledVehicleId = $chainLink->copy_selected_vehicle ? $parentSubmission->vehicle_id : null;
                $chainParentSubmission = $parentSubmission->submission_id;
            }
        }

        $users = User_detail::where('org', $org_id)->get(['user_id', 'fname', 'lname']);
        $vehicles = Vehicle::where('org_id', $org_id)->get(['id', 'license_plate', 'brand']);
        return view('form.checking.fillOutForm', compact(
            'form_data',
            'users',
            'vehicles',
            'prefilledValues',
            'prefilledUserId',
            'prefilledVehicleId',
            'chainParentSubmission'
        ));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $form_id)
    {
        $validator = Validator::make($request->all(), [
            'selected_user_id' => 'nullable|exists:users,id',
            'selected_vehicle_id' => 'nullable|exists:vehicles,id',
            'fieldsAns' => 'required|array',
            'chain_parent_submission' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => "แบบฟอร์มเกิดข้อผิดพลาด"], 400);
        }

        try {
            $form = Form::where('form_id', $form_id)->firstOrFail();
            $parentSubmission = null;

            if ($request->filled('chain_parent_submission')) {
                $parentSubmission = FormSubmissions::where('submission_id', $request->chain_parent_submission)->first();
                $hasChainLink = $parentSubmission && $this->chainService->canAccessSubmission($parentSubmission)
                    && $this->chainService->canFillForm($form)
                    && FormChainLink::where('source_form_id', $parentSubmission->form_id)
                        ->where('next_form_id', $form->id)
                        ->exists();

                if (!$hasChainLink) {
                    return response()->json(['errors' => 'ไม่สามารถเชื่อมแบบฟอร์มนี้ได้'], 422);
                }
            }

            $org_id = $this->chainService->currentOrgId();

            $form_submission = FormSubmissions::create([
                'submission_id' => Str::uuid(),
                'form_id' => $form->id,
                'parent_submission_id' => $parentSubmission?->id,
                'user_id' => $request->selected_user_id ?? null,
                'vehicle_id' => $request->selected_vehicle_id ?? null,
                'submitted_by' => Auth::user()->id,
                'org' => $org_id,
            ]);

            foreach ($request->fieldsAns as $field) {
                FormSubmissionValue::create([
                    'submission_id' => $form_submission->id,
                    'field_id' => $field['field_id'],
                    'value' => $field['answer'],
                    'submitted_by' => Auth::user()->id,
                ]);
            }

            FormSubmissionHistory::create([
                'submission_id' => $form_submission->id,
                'user_id' => Auth::user()->id,
            ]);

            return response()->json(['success' => 'ส่งแบบฟอร์มสำเร็จ!']);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'parent_submission_id')) {
                return response()->json(['errors' => 'มีแบบฟอร์มต่อเนื่องนี้แล้ว'], 422);
            }

            return response()->json(['errors' => 'เกิดข้อผิดพลาดขณะบันทึกแบบฟอร์ม']);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['errors' => 'เกิดข้อผิดพลาดขณะบันทึกแบบฟอร์ม']);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $submission = FormSubmissions::where('submission_id', $id)->firstOrFail();
        abort_unless($this->chainService->canAccessSubmission($submission), 403);
        $form_data = Form::where('id', $submission->form_id)->firstOrFail();
        $is_show = true;
        return view('form.checking.continueDocument', compact('submission', 'form_data', 'is_show'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $submission = FormSubmissions::where('submission_id', $id)->firstOrFail();
        abort_unless($this->chainService->canAccessSubmission($submission), 403);
        $form_data = Form::where('id', $submission->form_id)->firstOrFail();
        $is_show = false;
        return view('form.checking.continueDocument', compact('submission', 'form_data', 'is_show'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $form_submission = FormSubmissions::findOrFail($id);

            foreach ($request->fieldsAns as $field) {
                if (FormSubmissionValue::where('submission_id', $form_submission->id)->where('field_id', $field['field_id'])->exists()) {
                    FormSubmissionValue::where('submission_id', $form_submission->id)->where('field_id', $field['field_id'])->update([
                        'value' => $field['answer'],
                        'submitted_by' => Auth::user()->id,
                    ]);
                } else {
                    FormSubmissionValue::create([
                        'submission_id' => $form_submission->id,
                        'field_id' => $field['field_id'],
                        'value' => $field['answer'],
                        'submitted_by' => Auth::user()->id,
                    ]);
                }
            }

            FormSubmissionHistory::create([
                'submission_id' => $form_submission->id,
                'user_id' => Auth::user()->id,
            ]);

            return response()->json(['success' => 'บันทึกแบบฟอร์มสำเร็จ!']);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['errors' => 'เกิดข้อผิดพลาดขณะบันทึกแบบฟอร์ม']);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $submission_id)
    {
        abort_unless(Auth::user()->username === 'tsmcadmin', 403);

        $submission = FormSubmissions::where('submission_id', $submission_id)->firstOrFail();
        $submission->delete();

        return back()->with('success', 'ลบแบบฟอร์มที่ส่งแล้วเรียบร้อย');
    }

    public function submissionsIndex(Request $request)
    {
        abort_unless(Auth::user()->username === 'tsmcadmin', 403);

        $request->validate([
            'form_id' => ['nullable', 'integer', 'exists:forms,id'],
            'org' => ['nullable', 'integer', 'exists:organizations,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], [
            'form_id.integer' => 'แบบฟอร์มที่เลือกไม่ถูกต้อง',
            'form_id.exists' => 'ไม่พบแบบฟอร์มที่เลือก',
            'org.integer' => 'องค์กรที่เลือกไม่ถูกต้อง',
            'org.exists' => 'ไม่พบองค์กรที่เลือก',
            'date_from.date' => 'รูปแบบวันที่เริ่มไม่ถูกต้อง',
            'date_to.date' => 'รูปแบบวันที่สิ้นสุดไม่ถูกต้อง',
            'date_to.after_or_equal' => 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่ม',
        ]);

        $submissions = FormSubmissions::with(['getForm', 'organization', 'submittedByUser.userDetail.getPrefix'])
            ->when($request->filled('form_id'), fn ($query) => $query->where('form_id', $request->integer('form_id')))
            ->when($request->filled('org'), fn ($query) => $query->where('org', (string) $request->integer('org')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date_to))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->appends($request->query());

        $forms = Form::orderBy('title')->get(['id', 'title']);
        $organizations = Organization::orderBy('name')->get(['id', 'name']);

        return view('exportDocument.submissionsIndex', compact('submissions', 'forms', 'organizations'));
    }

    public function showDocTable($form_id) {
        $form_data = Form::where('form_id', $form_id)->firstOrFail();

        if (Auth()->user()->is_tsm) {
            $query = FormSubmissions::where('form_id', $form_data->id)->where('org', session('connected_org') ?? '');
        } else {
            $query = FormSubmissions::where('form_id', $form_data->id)->where('org', Auth::user()->userDetail->org ?? '');
            if (!optional(Auth::user()->userDetail->getPosition)->hasPermissionName('can_see_all_docs',Auth::user()->userDetail->org) ?? true) {
                $query->where(function ($query) {
                    $query->where('submitted_by', Auth::user()->id)->orWhere('user_id', Auth::user()->id);
                });
            }
        }

        $submissions = $query->orderByDesc('created_at')->get();

        if (FormChainLink::where('source_form_id', $form_data->id)->exists()) {
            foreach ($submissions as $submission) {
                $submission->chainActions = $this->chainActionsFor($submission);
            }
        }

        return view('form.table.formDataTable', compact('submissions', 'form_data'));
    }

    public function filterDocument() {
        $form_cates = Form_category::all();

        if (Auth()->user()->is_tsm) {
            $vehicles = Vehicle::where('org_id', session('connected_org') ?? '')->get(['id', 'license_plate', 'brand']);
            $users = User_detail::where('org', session('connected_org') ?? '')->get(['user_id', 'fname', 'lname']);
        } else {
            $vehicles = Vehicle::where('org_id', Auth::user()->userDetail->org ?? '')->get(['id', 'license_plate', 'brand']);
            $users = User_detail::where('org', Auth::user()->userDetail->org ?? '')->get(['user_id', 'fname', 'lname']);
        }

        return view('exportDocument.filterData', compact('form_cates', 'vehicles', 'users'));
    }

    public function importCandidates(string $form_id)
    {
        $org_id = $this->chainService->currentOrgId() ?? '';

        $form = Form::where('form_id', $form_id)
            ->where(function ($query) use ($org_id) {
                $query->where('org', $org_id)->orWhere('is_default', true);
            })
            ->firstOrFail();
        abort_unless($this->chainService->canFillForm($form), 403);

        return response()->json(['candidates' => $this->importService->candidatesForToday($form)]);
    }

    public function importData(Request $request, string $submission_id)
    {
        $org_id = $this->chainService->currentOrgId() ?? '';

        $targetForm = Form::where('form_id', $request->query('target_form_id'))
            ->where(function ($query) use ($org_id) {
                $query->where('org', $org_id)->orWhere('is_default', true);
            })
            ->firstOrFail();
        abort_unless($this->chainService->canFillForm($targetForm), 403);

        $source = FormSubmissions::where('submission_id', $submission_id)->first();
        $isValidSource = $source
            && $this->chainService->canAccessSubmission($source)
            && $source->created_at >= now()->startOfDay()
            && $source->created_at <= now();

        if (!$isValidSource) {
            return response()->json(['errors' => 'ไม่สามารถนำเข้าข้อมูลจากใบนี้ได้'], 422);
        }

        return response()->json($this->importService->matchedValues($source, $targetForm));
    }

    private function chainActionsFor(FormSubmissions $submission): array
    {
        $actions = [];
        $links = FormChainLink::with('nextForm')
            ->where('source_form_id', $submission->form_id)
            ->get();

        foreach ($links as $link) {
            $nextForm = $link->nextForm;
            if (!$nextForm || !$nextForm->status || !$this->chainService->canFillForm($nextForm)) {
                continue;
            }

            $child = FormSubmissions::where('parent_submission_id', $submission->id)
                ->where('form_id', $nextForm->id)
                ->first();

            if ($child && !$this->chainService->canAccessSubmission($child)) {
                continue;
            }

            $actions[] = [
                'title' => $nextForm->title,
                'submission_id' => $child?->submission_id,
                'form_id' => $nextForm->form_id,
            ];
        }

        return $actions;
    }
}
