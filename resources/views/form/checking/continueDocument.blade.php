@extends('layouts.app')

@section('content')
    <div class="">
        <div class="row justify-content-center">
            <div class="px-3 px-md-5" x-data="formFillOut({{ $form_data->formFields }}, {{ $submission->getSubmissionValues }})">
                <form @submit.prevent="handleSubmit">
                    @csrf
                    <div class="card mb-4" id="exportPaper">
                        <div class="card-body px-md-5">
                            <p class="text-center fs-5 mb-0 fw-bold">{{ Auth::user()->org_name }}</p>
                            <p class="text-center fs-5 mb-0 fw-bold">{{ $form_data->title }}</p>
                            @php
                                $updatedDate = new Carbon\Carbon($submission->updated_at);
                            @endphp
                            <p class="text-center">{{ $updatedDate->thaidate('วันที่ j F พ.ศ.Y') }}</p>

                            <div class="row row-cols-1 row-cols-sm-2 mb-3">
                                @if ($form_data->select_vehicle)
                                    <div class="col d-flex align-items-center">
                                        <label for="vehicle" class="col-form-label text-nowrap w-25 text-end">รถ</label>
                                        <input type="text" id="user_id" class="form-control ms-2" value="{{ optional($submission->getVehicle)->license_plate ?? '-' }}" disabled>
                                    </div>
                                @endif
                                @if ($form_data->select_user)
                                    <div class="col d-flex align-items-center">
                                        <label for="user_id" class="col-form-label text-nowrap w-25 text-end">พนักงาน</label>
                                        <input type="text" id="user_id" class="form-control ms-2" value="{{ optional($submission->getUser)->full_name ?? '-' }}" disabled>
                                    </div>
                                @endif
                            </div>

                            <hr>

                            <div class="row mb-2">
                                <template x-for="(field, index) in formFieldsAnswer" :key="field.id">
                                    <div class="mb-2 align-items-center px-4 fs-6 {{ $is_show ? 'd-flex gap-2' : '' }}"
                                        :class="field.type === 'subform' ? 'col-12' : 'col-md-6 col-12 eachCheckList'">

                                        <template x-if="field.type !== 'subform'">
                                            <label class="col-form-label text-end" x-text="field.label"></label>
                                        </template>

                                        <!-- Subform Header (Full Width) -->
                                        <template x-if="field.type === 'subform'">
                                            @if ($is_show)
                                                <table class="table table-bordered my-4">
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
                                                        <template x-for="(subfield, index) in field.subfields" :key="subfield.id">
                                                            <tr>
                                                                <td x-text="subfield.label"></td>
                                                                @if ($is_show)
                                                                    <td>
                                                                        <div x-text="subfield.answer"></div>
                                                                    </td>
                                                                @else
                                                                    <td>
                                                                        <!-- Input Type: Text -->
                                                                        <template x-if="subfield.type === 'text'">
                                                                            <input type="text" class="form-control ms-2" x-model="subfield.answer" {{ $is_show ? 'disabled' : '' }}>
                                                                        </template>

                                                                        <!-- Input Type: Number -->
                                                                        <template x-if="subfield.type === 'number'">
                                                                            <input type="number" class="form-control ms-2" x-model="subfield.answer" {{ $is_show ? 'disabled' : '' }}>
                                                                        </template>

                                                                        <!-- Select Dropdown -->
                                                                        <template x-if="subfield.type === 'select'">
                                                                            <div class="d-flex gap-2">
                                                                                <template x-for="option in subfield.options" :key="option.value">
                                                                                    <div class="w-100">
                                                                                        <input type="radio" x-model="subfield.answer" class="btn-check" :value="option.value" :name="subfield.id" :id="index + option.value" autocomplete="off" :checked="option.value === subfield.answer">
                                                                                        <label class="btn btn-outline-primary w-100" :for="index + option.value" x-text="option.value"></label>
                                                                                    </div>
                                                                                </template>
                                                                            </div>
                                                                        </template>
                                                                    </td>
                                                                @endif
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table>
                                            @else
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
                                                                            <input type="radio" x-model="subfield.answer" class="btn-check" :value="option.value" :name="subfield.id" :id="index + option.value" autocomplete="off" :checked="option.value === subfield.answer">
                                                                            <label class="btn btn-outline-primary w-100" :for="index + option.value" x-text="option.value"></label>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            @endif
                                        </template>

                                        @if ($is_show)
                                            <div x-text="field.answer" class="text-decoration-underline"></div>
                                        @else
                                            <!-- Input Type: Text -->
                                            <template x-if="field.type === 'text' || field.type === 'job_number'">
                                                <input type="text" class="form-control ms-2" x-model="field.answer">
                                            </template>

                                            <!-- Input Type: Number -->
                                            <template x-if="field.type === 'number'">
                                                <input type="number" class="form-control ms-2" x-model="field.answer" {{ $is_show ? 'disabled' : '' }}>
                                            </template>

                                            <!-- Select Dropdown -->
                                            <template x-if="field.type === 'select'">
                                                <select class="form-control ms-2" x-model="field.answer" {{ $is_show ? 'disabled' : '' }}>
                                                    <option value="" selected>กรุณาเลือกคำตอบ</option>
                                                    <template x-for="option in field.options" :key="option.value">
                                                        <option :value="option.value" x-text="option.value" :selected="option.value === field.answer"></option>
                                                    </template>
                                                </select>
                                            </template>

                                            <template x-if="field.type === 'autocomplete'">
                                                <div>
                                                    <input type="text" class="form-control ms-2" x-model="field.answer" :list="'dl-' + field.id">
                                                    <datalist :id="'dl-' + field.id">
                                                        <template x-for="option in field.options" :key="option.value">
                                                            <option :value="option.value"></option>
                                                        </template>
                                                    </datalist>
                                                </div>
                                            </template>
                                        @endif
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-2">
                        @if ($is_show)
                            <button class="btn btn-success" type="button" onclick="window.print()">Print</button>
                        @else
                            <button class="btn btn-success" type="submit" {{ session('org_status') == 2 ? 'disabled' : '' }}>บันทึก</button>
                        @endif
                        <a href="{{ route('document.table', ['form_id' => $form_data->form_id]) }}" class="btn btn-secondary">กลับ</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        function formFillOut(fieldData, ansData) {
            console.log('Field Data: ', fieldData);
            console.log('Answer Data: ', ansData);
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
                        answer: ansData.find(ans => ans.field_id === subfield.id) ? ansData.find(ans => ans.field_id === subfield.id).value : ''
                    })) : '',
                    answer: ansData.find(ans => ans.field_id === field.id) ? ansData.find(ans => ans.field_id === field.id).value : ''
                })),

                handleSubmit() {
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
                        fieldsAns: fieldsWithAns
                    };

                    fetch(`/document/{{ $submission->id}}/update`, {
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
                                    window.location.reload();
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

        /* #exportPaper {
            background-color: white;
            padding: 1cm;
            width: 210mm;
        } */

        @media print {
            body {
                visibility: hidden;
            }
            #exportPaper {
                visibility: visible;
                position: absolute;
                left: 0;
                top: 0;
                color: black;
            }
            .eachCheckList {
                width: 50%;
            }
        }
    </style>
@endsection
