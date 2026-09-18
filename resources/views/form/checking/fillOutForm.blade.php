@extends('layouts.app')

@section('content')
    <div class="">
        <div class="row justify-content-center">
            <div class="px-3 px-md-5">
                <div class="card">
                    <div class="card-body px-md-5" x-data="formFillOut({{ $form_data->formFields }}, {{ $vehicles }}, '{{ $form_data->form_id }}', {{ json_encode($prefilledValues) }}, {{ json_encode($prefilledUserId) }}, {{ json_encode($prefilledVehicleId) }}, {{ json_encode($chainParentSubmission) }})">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <p class="fs-5 fw-bold mb-0">{{ $form_data->title }}</p>
                            <button type="button" class="btn btn-outline-primary btn-sm" @click="openImportPicker()">
                                <i class="bi bi-download"></i> นำเข้าข้อมูลจากฟอร์มอื่น (วันนี้)
                            </button>
                        </div>
                        <form @submit.prevent="handleSubmit">
                            @csrf

                            <div class="row row-cols-1 row-cols-md-2 mb-3 fs-5">
                                @if ($form_data->select_vehicle)
                                    <div class="col">
                                        <div class="d-flex align-items-center">
                                            <label for="position" class="col-form-label text-nowrap w-25 text-end">รถ</label>
                                            <select class="form-select ms-2" aria-label="Default select example"
                                                x-model="selectVehicleId" @change="updateUser()" required>
                                                <option value="" selected>เลือกรถ</option>
                                                @foreach ($vehicles as $vehicle)
                                                    <option value="{{ $vehicle->id }}">{{ $vehicle->license_plate }} :
                                                        {{ $vehicle->brand }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div x-show="showVehicleError" class="text-danger text-center form-text">กรุณาเลือกรถ</div>
                                    </div>
                                @endif
                                @if ($form_data->select_user)
                                    <div class="col">
                                        <div class="d-flex align-items-center">
                                            <label for="empName"
                                                class="col-form-label text-nowrap w-25 text-end">ผู้ประจำรถ</label>
                                            <select class="form-select ms-2" aria-label="Default select example"
                                                x-model="selectUserId" @change="updateError()" required>
                                                <option value="" selected>ไม่พบผู้ประจำรถ</option>
                                                @foreach ($users as $user)
                                                    <option value="{{ $user->user_id }}">{{ $user->fname }} {{ $user->lname }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div x-show="showUserError" class="text-danger text-center form-text">กรุณาเลือกผู้ประจำรถ</div>
                                    </div>
                                @endif
                            </div>

                            <hr>

                            <div class="row mb-3">
                                <template x-for="(field) in formFieldsAnswer" :key="field.id">
                                    <div class="mb-2 align-items-center px-4 fs-5"
                                        :class="field.type === 'subform' ? 'col-12' : 'col-md-6 col-12'">

                                        <div>
                                            {{-- <label class="col-form-label text-end" x-text="field.label"></label> --}}
                                            <template x-if="field.type !== 'subform'">
                                                <label class="col-form-label text-end" x-text="field.label"></label>
                                            </template>

                                            <!-- Subform Header (Full Width) -->
                                            <template x-if="field.type === 'subform'">
                                                <div class="fs-5 px-2 px-md-4 my-4 row border">
                                                    <label class="col-form-label text-center" x-text="field.label"></label>
                                                    <template x-for="(subfield) in field.subfields" :key="subfield.id">
                                                        <div class="p-2 rounded-3 col-12 col-md-6 px-md-4">
                                                            <div x-text="subfield.label"></div>

                                                            <!-- Input Type: Text -->
                                                            <template x-if="subfield.type === 'text'">
                                                                <input type="text" class="form-control ms-2" x-model="subfield.answer" placeholder="กรอกข้อมูล">
                                                            </template>

                                                            <!-- Input Type: Number -->
                                                            <template x-if="subfield.type === 'number'">
                                                                <input type="number" class="form-control ms-2" x-model="subfield.answer" placeholder="กรอกข้อมูล">
                                                            </template>

                                                            <!-- Select Dropdown -->
                                                            <template x-if="subfield.type === 'select'">
                                                                <div class="d-flex gap-2">
                                                                    <template x-for="option in subfield.options" :key="option.value">
                                                                        <div class="w-100">
                                                                            <input type="radio" x-model="subfield.answer" class="btn-check" :value="option.value" :name="subfield.id" :id="subfield.id + option.value" autocomplete="off" checked>
                                                                            <label class="btn btn-outline-primary w-100" :for="subfield.id + option.value" x-text="option.value"></label>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                                {{-- <table class="table table-bordered my-4">
                                                    <thead class="text-center table-secondary">
                                                        <tr>
                                                            <th colspan="3" x-text="field.label"></th>
                                                        </tr>
                                                        <tr>
                                                            <th>รายการ</th>
                                                            <th>ผลการตรวจ</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <template x-for="(subfield) in field.subfields" :key="subfield.id">
                                                            <tr>
                                                                <td x-text="subfield.label"></td>
                                                                <td>
                                                                    <!-- Input Type: Text -->
                                                                    <template x-if="subfield.type === 'text'">
                                                                        <input type="text" class="form-control ms-2" x-model="subfield.answer" placeholder="กรอกข้อมูล">
                                                                    </template>

                                                                    <!-- Input Type: Number -->
                                                                    <template x-if="subfield.type === 'number'">
                                                                        <input type="number" class="form-control ms-2" x-model="subfield.answer" placeholder="กรอกข้อมูล">
                                                                    </template>

                                                                    <!-- Select Dropdown -->
                                                                    <template x-if="subfield.type === 'select'">
                                                                        <div class="d-flex gap-2">
                                                                            <template x-for="option in subfield.options" :key="option.value">
                                                                                <div class="w-100">
                                                                                    <input type="radio" x-model="subfield.answer" class="btn-check" :value="option.value" :name="subfield.id" :id="subfield.id + option.value" autocomplete="off" checked>
                                                                                    <label class="btn btn-outline-primary w-100" :for="subfield.id + option.value" x-text="option.value"></label>
                                                                                </div>
                                                                            </template>
                                                                        </div>
                                                                    </template>
                                                                </td>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table> --}}
                                            </template>

                                            <!-- Input Type: Text -->
                                            <template x-if="field.type === 'text'">
                                                <input type="text" class="form-control ms-2" x-model="field.answer" placeholder="กรอกข้อมูล">
                                            </template>

                                            <!-- Input Type: Job Number (Auto) -->
                                            <template x-if="field.type === 'job_number'">
                                                <input type="text" class="form-control ms-2" x-model="field.answer" placeholder="ระบบสร้างเลขที่งานให้อัตโนมัติ แก้ไขได้">
                                            </template>

                                            <!-- Input Type: Date -->
                                            <template x-if="field.type === 'date'">
                                                <input type="date" class="form-control ms-2" x-model="field.answer" placeholder="เลือกวันที่">
                                            </template>

                                            <!-- Input Type: Number -->
                                            <template x-if="field.type === 'number'">
                                                <input type="number" class="form-control ms-2" x-model="field.answer" placeholder="กรอกข้อมูล">
                                            </template>

                                            <!-- Select Dropdown -->
                                            <template x-if="field.type === 'select'">
                                                <select class="form-control ms-2" x-model="field.answer">
                                                    <option value="" selected disabled>กรุณาเลือกคำตอบ</option>
                                                    <template x-for="option in field.options" :key="option.value">
                                                        <option :value="option.value" x-text="option.value"></option>
                                                    </template>
                                                </select>
                                            </template>

                                            <!-- Input Type: Autocomplete (Text + Options) -->
                                            <template x-if="field.type === 'autocomplete'">
                                                <div>
                                                    <input type="text" class="form-control ms-2" x-model="field.answer" :list="'dl-' + field.id" placeholder="กรอกหรือเลือกคำตอบ">
                                                    <datalist :id="'dl-' + field.id">
                                                        <template x-for="option in field.options" :key="option.value">
                                                            <option :value="option.value"></option>
                                                        </template>
                                                    </datalist>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <div class="d-flex justify-content-center gap-2">
                                <button class="btn btn-success" type="submit">บันทึก</button>
                                <a href="{{ route('document.fill-out.selectform') }}" class="btn btn-secondary">กลับ</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        function formFillOut(fieldData, vehicles, formId, prefilledValues = {}, prefilledUserId = null, prefilledVehicleId = null, chainParentSubmission = null) {
            return {
                formFieldsAnswer: fieldData.map((field) => ({
                    id: field.id,
                    label: field.label,
                    type: field.type,
                    options: field.options ? field.options.map(option => ({
                        value: option.value
                    })) : '',
                    subfields: field.type === 'subform' ? field.subform.subformfields.map(subfield => ({
                        id: subfield.id,
                        label: subfield.label,
                        type: subfield.type,
                        options: subfield.options ? subfield.options.map(option => ({
                            value: option.value
                        })) : '',
                        answer: Object.prototype.hasOwnProperty.call(prefilledValues, subfield.id) ? prefilledValues[subfield.id] : ''
                    })) : '',
                    answer: Object.prototype.hasOwnProperty.call(prefilledValues, field.id)
                        ? prefilledValues[field.id]
                        : (field.type === 'job_number' ? (field.job_number_default || '') : '')
                })),
                selectUserId: prefilledUserId || '',
                selectVehicleId: prefilledVehicleId || '',
                chainParentSubmission,
                showVehicleError: false,
                showUserError: false,
                storageKey: `form_history_${formId}`,

                init() {
                    if (!this.chainParentSubmission) {
                        this.restoreHistory();
                    }
                },

                // บันทึก history ลง LocalStorage
                saveHistory() {
                    const snapshot = {
                        fields: this.formFieldsAnswer.map(field => ({
                            id: field.id,
                            answer: field.answer,
                            subfields: Array.isArray(field.subfields)
                                ? field.subfields.map(sf => ({ id: sf.id, answer: sf.answer }))
                                : null
                        }))
                    };
                    localStorage.setItem(this.storageKey, JSON.stringify(snapshot));
                },

                // เติมข้อมูลจาก history กลับเข้าฟอร์ม
                restoreHistory() {
                    const today = new Date().toISOString().split('T')[0];
                    const raw = localStorage.getItem(this.storageKey);
                    const snapshot = raw ? JSON.parse(raw) : null;

                    // Reassign array ทั้งหมดเพื่อให้ Alpine detect การเปลี่ยนแปลงได้แน่นอน
                    this.formFieldsAnswer = this.formFieldsAnswer.map(field => {
                        const savedField = snapshot?.fields?.find(sf => sf.id === field.id);

                        const restoredSubfields = Array.isArray(field.subfields)
                            ? field.subfields.map(sf => {
                                const savedSub = savedField?.subfields?.find(s => s.id === sf.id);
                                if (sf.type === 'date') return { ...sf, answer: today };
                                return { ...sf, answer: savedSub?.answer ?? sf.answer };
                            })
                            : field.subfields;

                        if (field.type === 'date') return { ...field, answer: today, subfields: restoredSubfields };
                        return { ...field, answer: savedField?.answer ?? field.answer, subfields: restoredSubfields };
                    });
                },

                updateError() {
                    this.showVehicleError = !this.selectVehicleId;
                    this.showUserError = !this.selectUserId;
                },

                updateUser() {
                    let selectedVehicle = vehicles.find(v => v.id == this.selectVehicleId);
                    this.selectUserId = selectedVehicle ? selectedVehicle.driver_id : '';
                    this.updateError();
                },

                async openImportPicker() {
                    const response = await fetch(`/document/{{ $form_data->form_id }}/import-candidates`, {
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                    });
                    const data = await response.json();
                    const candidates = data.candidates || [];

                    if (candidates.length === 0) {
                        Swal.fire({ icon: 'info', title: 'ไม่มีใบที่นำเข้าได้วันนี้', confirmButtonText: 'ตกลง' });
                        return;
                    }

                    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    }[character]));
                    const options = candidates.map((candidate) => {
                        const who = escapeHtml(candidate.employee_name || candidate.submitted_by_name || '');
                        const vehicle = candidate.vehicle_label ? ` - ${escapeHtml(candidate.vehicle_label)}` : '';
                        const person = who ? ` - ${who}` : '';
                        return `<option value="${escapeHtml(candidate.submission_id)}">${escapeHtml(candidate.form_title)} (${escapeHtml(candidate.submitted_at)})${person}${vehicle}</option>`;
                    }).join('');

                    const { value: submissionId } = await Swal.fire({
                        title: 'เลือกใบที่จะนำเข้าข้อมูล',
                        html: `<select id="import-source-select" class="form-select">${options}</select>`,
                        showCancelButton: true,
                        confirmButtonText: 'นำเข้า',
                        cancelButtonText: 'ยกเลิก',
                        preConfirm: () => document.getElementById('import-source-select').value,
                    });

                    if (submissionId) {
                        await this.applyImport(submissionId);
                    }
                },

                async applyImport(submissionId) {
                    const response = await fetch(`/document/import-data/${submissionId}?target_form_id={{ $form_data->form_id }}`, {
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                    });
                    const data = await response.json();

                    if (data.errors) {
                        Swal.fire({ icon: 'error', title: data.errors, confirmButtonText: 'ตกลง' });
                        return;
                    }

                    const values = data.values || {};
                    let filledCount = 0;
                    const isEmpty = (value) => value === '' || value === null || value === undefined;

                    this.formFieldsAnswer = this.formFieldsAnswer.map((field) => {
                        if (field.type === 'subform') {
                            const subfields = field.subfields.map((subfield) => {
                                if (isEmpty(subfield.answer) && Object.prototype.hasOwnProperty.call(values, subfield.id)) {
                                    filledCount++;
                                    return { ...subfield, answer: values[subfield.id] };
                                }
                                return subfield;
                            });
                            return { ...field, subfields };
                        }

                        if (isEmpty(field.answer) && Object.prototype.hasOwnProperty.call(values, field.id)) {
                            filledCount++;
                            return { ...field, answer: values[field.id] };
                        }
                        return field;
                    });

                    if (isEmpty(this.selectUserId) && data.user_id) {
                        this.selectUserId = data.user_id;
                        filledCount++;
                    }
                    if (isEmpty(this.selectVehicleId) && data.vehicle_id) {
                        this.selectVehicleId = data.vehicle_id;
                        filledCount++;
                    }

                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: filledCount > 0 ? 'success' : 'info',
                        title: filledCount > 0 ? `นำเข้าข้อมูลสำเร็จ (เติม ${filledCount} ช่อง)` : 'ไม่มีช่องว่างให้เติมจากใบนี้',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                    });
                },

                // validateForm() {
                //     this.showVehicleError = !this.selectVehicleId;
                //     this.showUserError = !this.selectUserId;
                //     return this.selectVehicleId && this.selectUserId;
                // },


                handleSubmit() {
                    this.saveHistory();
                    const allFields = this.formFieldsAnswer.flatMap(field =>
                        field.type === "subform" ? field.subfields : field
                    );

                    // console.log('Form Fields Answer: ', this.formFieldsAnswer);
                    // console.log('Form Fields flatMap: ', this.formFieldsAnswer.flatMap(field =>
                    //     field.type === "subform" ? field.subfields : field
                    // ))

                    const fieldsWithAns = allFields.map((field, index) => ({
                        field_id: field.id,
                        answer: field.answer,
                    }));

                    const formData = {
                        selected_user_id: this.selectUserId,
                        selected_vehicle_id: this.selectVehicleId,
                        fieldsAns: fieldsWithAns,
                        chain_parent_submission: this.chainParentSubmission,
                    };

                    fetch(`/document/{{ $form_data->form_id }}/submit`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify(formData)
                        })
                        .then(response => response.json())
                        .then(data => {
                            console.log('Data: ', data);
                            if (data.errors) {
                                Swal.fire({
                                    toast: true,
                                    position: "top-end",
                                    icon: "error",
                                    title: data.errors,
                                    showConfirmButton: false,
                                    timer: 3000,
                                    timerProgressBar: true,
                                    didOpen: (toast) => {
                                        toast.onmouseenter = Swal.stopTimer;
                                        toast.onmouseleave = Swal.resumeTimer;
                                    }
                                });
                            } else {
                                Swal.fire(data.success ? data.success :"บันทึกสำเร็จ", "", "success").then(() => {
                                    window.location.href = "/document/fill-out/select-form";
                                });
                            }
                        })
                        .catch(error => {
                            Swal.fire({
                                toast: true,
                                position: "top-end",
                                icon: "error",
                                title: "เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง",
                                showConfirmButton: false,
                                timer: 3000,
                                timerProgressBar: true,
                                didOpen: (toast) => {
                                    toast.onmouseenter = Swal.stopTimer;
                                    toast.onmouseleave = Swal.resumeTimer;
                                }
                            });
                        });
                }

            }
        }
    </script>
    <style>
        #formCheckpage {
            background-color: var(--main-color);
        }
    </style>
@endsection
