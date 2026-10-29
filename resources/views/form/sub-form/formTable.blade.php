@extends('layouts.app')

@section('content')
    <div class="">
        <div class="row justify-content-center">
            <div class="px-3 px-md-5">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between">
                            <p class="mb-0 fs-4">ทะเบียนแบบฟอร์มย่อย</p>
                            <div>
                                <a href="{{ route('form.create', ['form_category' => 'sub-form']) }}" class="btn btn-success btn-sm">สร้าง</a>
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
                                                <a href="{{ route('form.edit', ['form_category' => 'sub-form', 'id' => $form->form_id]) }}" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-title="แก้ไข">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>

                                                <button type="button" class="btn btn-danger btn-sm delete-data-btn" del-id="{{ $form->id }}" del-target="forms/sub-form/form"data-bs-toggle="tooltip" data-bs-title="ลบ">
                                                    <i class="bi bi-trash"></i>
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
@endsection
