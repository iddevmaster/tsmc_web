@extends('layouts.app')

@section('content')
    <div class="container py-4" x-data="formBuilder(@js($form_data))">
        <form @submit.prevent="handleSubmit" class="vstack gap-4">
            <input type="hidden" x-model="formId" value="{{ $form_data->form_id }}">
            <input type="hidden" x-model="formCategory" value="{{ $form_category }}">

            {{-- Form Header --}}
            <div class="text-center fs-4 fw-bold">
                แก้ไขแบบฟอร์ม
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="mb-3">
                        <input type="text" x-model="formTitle" placeholder="ชื่อฟอร์ม"
                            class="form-control form-control-lg border-0 border-bottom rounded-0 fw-bold" required>
                    </div>
                    <div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="inlineCheckbox1" x-model="selectUser" value="1" checked>
                            <label class="form-check-label" for="inlineCheckbox1">มีการเลือกผู้ใช้</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="inlineCheckbox2" x-model="selectVehicle" value="1">
                            <label class="form-check-label" for="inlineCheckbox2">มีการเลือกยานพาหนะ</label>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Field List --}}
            <template x-for="(field, index) in formFields" :key="field.id">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge bg-primary" x-text="field.order"></span>
                            <h3 class="h5 mb-0" x-text="'รายการที่ ' + (index + 1)"></h3>
                            <div class="btn-group">
                                <button type="button" @click="moveField(index, 'up')" :disabled="index === 0"
                                    class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button type="button" @click="moveField(index, 'down')"
                                    :disabled="index === formFields.length - 1" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button type="button" @click="removeField(field.id)" class="btn btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">หัวข้อ</label>
                                <input type="text" x-model="field.label" class="form-control"
                                    placeholder="กรอกหัวข้อรายการ" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">ประเภทคำตอบ</label>
                                <select x-model="field.type" class="form-select">
                                    <option value="text" :selected="field.type === 'text'">ข้อความ</option>
                                    <option value="number" :selected="field.type === 'number'">ตัวเลข</option>
                                    <option value="select" :selected="field.type === 'select'">ตัวเลือก</option>
                                    <option value="autocomplete" :selected="field.type === 'autocomplete'">ข้อความ + ตัวเลือก (Autocomplete)</option>
                                    <option value="date" :selected="field.type === 'date'">วันที่</option>
                                    <option value="job_number" :selected="field.type === 'job_number'">เลขที่งาน (Auto)</option>
                                    <option value="subform" :selected="field.type === 'subform'">แบบฟอร์มย่อย</option>
                                </select>
                            </div>

                            {{-- Select Options --}}
                            <template x-if="field.type === 'select' || field.type === 'autocomplete'">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <label class="form-label mb-0">ตัวเลือก</label>
                                        <button type="button" @click="addOption(field.id)"
                                            class="btn btn-sm btn-outline-primary">
                                            + เพิ่มตัวเลือก
                                        </button>
                                    </div>

                                    <div class="vstack gap-2">
                                        <template x-for="option in field.options" :key="option.id">
                                            <div class="input-group">
                                                <input type="text" x-model="option.value" placeholder="ค่า"
                                                    class="form-control">
                                                <button type="button" @click="removeOption(field.id, option.id)"
                                                    class="btn btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Select Subform --}}
                            <template x-if="field.type === 'subform'">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                        <label class="form-label text-nowrap">แบบฟอร์มย่อย</label>
                                        <select class="form-select" x-model="field.subform_id">
                                            <option value="">เลือกแบบฟอร์มย่อย</option>
                                            @foreach ($sub_forms as $sub_form)
                                                <option value="{{ $sub_form->id }}">{{ $sub_form->title }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>

            <div class="d-flex justify-content-center gap-2">
                <a href="{{ route('form.table', ['form_category' => $form_category]) }}">
                    <button type="button"
                        class="btn btn-danger p-2 d-flex align-items-center justify-content-center border-dashed">
                        ยกเลิก
                    </button>
                </a>
                {{-- Add Field Button --}}
                <button type="button" @click="addField"
                    class="btn btn-outline-primary p-2 d-flex align-items-center justify-content-center border-dashed">
                    <i class="bi bi-plus me-1"></i>เพิ่มรายการ
                </button>

                {{-- Submit Button --}}
                <button type="submit" class="btn btn-success p-2 d-flex align-items-center justify-content-center">
                    <i class="bi bi-floppy me-1"></i> บันทึกฟอร์ม
                </button>
            </div>
        </form>
    </div>
    <script>
        function formBuilder(existingData) {
            return {
                formId: existingData.form_id,
                formCategory: existingData.form_category,
                formTitle: existingData.title,
                selectUser: existingData.select_user === 1 ? true : false,
                selectVehicle: existingData.select_vehicle === 1 ? true : false,
                formFields: existingData.form_fields.map(field => ({
                    ...field,
                    id: field.id || Date.now() // Ensure each field has an id
                })),
                fieldTypes: [{
                        value: 'text',
                        label: 'ข้อความ'
                    },
                    {
                        value: 'number',
                        label: 'ตัวเลข'
                    },
                    {
                        value: 'select',
                        label: 'ตัวเลือก'
                    },
                    {
                        value: 'autocomplete',
                        label: 'ข้อความ + ตัวเลือก (Autocomplete)'
                    },
                    {
                        value: 'date',
                        label: 'วันที่'
                    },
                    {
                        value: 'job_number',
                        label: 'เลขที่งาน (Auto)'
                    },
                    {
                        value: 'subform',
                        label: 'แบบฟอร์มย่อย'
                    }
                ],

                addField() {
                    this.formFields.push({
                        id: Date.now(),
                        type: 'text',
                        label: '',
                        required: false,
                        options: []
                    });
                },

                removeField(id) {
                    this.formFields = this.formFields.filter(field => field.id !== id);
                },

                moveField(index, direction) {
                    if (direction === 'up' && index > 0) {
                        [this.formFields[index], this.formFields[index - 1]] = [this.formFields[index - 1], this.formFields[
                            index]];
                    } else if (direction === 'down' && index < this.formFields.length - 1) {
                        [this.formFields[index], this.formFields[index + 1]] = [this.formFields[index + 1], this.formFields[
                            index]];
                    }
                },

                addOption(fieldId) {
                    const field = this.formFields.find(f => f.id === fieldId);
                    if (field) {
                        field.options.push({
                            id: Date.now(),
                            value: '',
                        });
                    }
                },

                removeOption(fieldId, optionId) {
                    const field = this.formFields.find(f => f.id === fieldId);
                    if (field) {
                        field.options = field.options.filter(opt => opt.id !== optionId);
                    }
                },

                handleSubmit() {
                    // Add index to each field before sending
                    // const fieldsWithIndex = this.formFields.map((field, index) => ({
                    //     ...field,
                    //     order: index + 1  // Add 1-based index
                    // }));

                    const formData = {
                        title: this.formTitle,
                        select_user: this.selectUser,
                        select_vehicle: this.selectVehicle,
                        fields: this.formFields
                    };

                    fetch(`/forms/{{ $form_category }}/update/${this.formId}`, {
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
                                    title: data.errors[Object.keys(data.errors)[0]][0],
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
                                    window.location.href = "{{ route('form.table', ['form_category' => $form_category]) }}";
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
        #formManagePage {
            background-color: var(--main-color);
        }
    </style>
@endsection
