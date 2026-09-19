@extends('layouts.app')

@section('content')
    <div class="">
        <div class="row justify-content-center">
            <div class="px-3 px-md-5">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between">
                            <p class="mb-0 fs-4">ทะเบียนแบบฟอร์ม {{ $category_name }}</p>
                            <div>
                                <a href="{{ route('form.create', ['form_category' => $category_name]) }}" class="btn btn-success btn-sm">สร้าง</a>
                                <a href="{{ route('form.select-form-category') }}" class="btn btn-secondary btn-sm">กลับ</a>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        {{-- @if (session('success'))
                            <div class="alert alert-success" role="alert">
                                {{ session('success') }}
                            </div>
                        @endif --}}

                        <table class="table table-hover table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">ชื่อแบบฟอร์ม</th>
                                    <th scope="col">สถานะ</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (count($forms ?? []) > 0)
                                    @foreach ($forms as $index => $form)
                                        <tr>
                                            <th scope="row">{{ $index + 1 }}</th>
                                            <td>{{ $form->title }}</td>
                                            <td>
                                                @if ($form->status)
                                                    <span class="badge text-bg-success">เปิดใช้งาน</span>
                                                @else
                                                    <span class="badge text-bg-danger">ปิดใช้งาน</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if (!$form->is_default || Auth::user()->username === 'tsmcadmin')
                                                    <a href="{{ route('form.edit', ['form_category' => $category_name, 'id' => $form->form_id]) }}" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-title="แก้ไข">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-danger btn-sm delete-data-btn" del-id="{{ $form->id }}" del-target="form" data-bs-toggle="tooltip" data-bs-title="ลบ">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                @endif
                                                <a href="{{ route('form.perm', ['form_id' => $form->form_id]) }}" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" data-bs-title="กำหนดสิทธิ์">
                                                    <i class="bi bi-person-gear"></i>
                                                </a>
                                                <a href="{{ route('form.chain.edit', ['form_id' => $form->form_id]) }}" class="btn btn-secondary btn-sm" data-bs-toggle="tooltip" data-bs-title="จัดการฟอร์มต่อเนื่อง">
                                                    <i class="bi bi-diagram-3"></i>
                                                </a>
                                                @if (!$form->is_default || Auth::user()->username === 'tsmcadmin')
                                                    <a href="{{ route('form.report-rules.edit', ['form_id' => $form->form_id]) }}" class="btn btn-info btn-sm" data-bs-toggle="tooltip" data-bs-title="รายงานภาคบังคับ">
                                                        <i class="bi bi-clipboard-data"></i>
                                                    </a>
                                                @endif
                                                <button type="button" class="btn btn-info btn-sm clone-form-btn" data-form-id="{{ $form->id }}" data-form-category="{{ $category_name }}" data-bs-toggle="tooltip" data-bs-title="คัดลอก">
                                                    <i class="bi bi-files"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="3">
                                            <div class="text-center">ไม่พบข้อมูล</div>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <style>
        #formManagePage {
            background-color: var(--main-color);
        }
    </style>
    <script>
        document.querySelectorAll('.clone-form-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const formId = btn.getAttribute('data-form-id');
                const formCategory = btn.getAttribute('data-form-category');

                Swal.fire({
                    title: 'คัดลอกแบบฟอร์มนี้?',
                    text: 'ระบบจะสร้างแบบฟอร์มใหม่ที่มีรายการเหมือนกันทุกประการ (ปิดใช้งานไว้ก่อน แก้ไขได้ทันที)',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'คัดลอก',
                    cancelButtonText: 'ยกเลิก',
                }).then((result) => {
                    if (!result.isConfirmed) return;

                    fetch(`/forms/${formCategory}/duplicate/${formId}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            }
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.errors) {
                                Swal.fire('เกิดข้อผิดพลาด', data.errors, 'error');
                            } else {
                                Swal.fire(data.success, '', 'success').then(() => {
                                    window.location.href = `/forms/${formCategory}/edit/${data.form_id}`;
                                });
                            }
                        })
                        .catch(() => {
                            Swal.fire('เกิดข้อผิดพลาด', 'กรุณาลองใหม่อีกครั้ง', 'error');
                        });
                });
            });
        });
    </script>
@endsection
