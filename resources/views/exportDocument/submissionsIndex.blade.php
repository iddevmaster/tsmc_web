@extends('layouts.app')

@section('content')
    <div class="row justify-content-center">
        <div class="px-3 px-md-5">
            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="fw-bold mb-3"><i class="bi bi-funnel-fill me-2"></i>กรองข้อมูล</h5>
                    <form action="{{ route('document.submissions.index') }}" method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="submissionForm" class="form-label small fw-bold">แบบฟอร์ม</label>
                            <select id="submissionForm" name="form_id" class="form-select">
                                <option value="">ทั้งหมด</option>
                                @foreach ($forms as $form)
                                    <option value="{{ $form->id }}" @selected(request('form_id') == $form->id)>
                                        {{ $form->title }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="submissionOrganization" class="form-label small fw-bold">องค์กร</label>
                            <select id="submissionOrganization" name="org" class="form-select">
                                <option value="">ทั้งหมด</option>
                                @foreach ($organizations as $organization)
                                    <option value="{{ $organization->id }}" @selected(request('org') == $organization->id)>
                                        {{ $organization->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="submissionDateFrom" class="form-label small fw-bold">วันที่เริ่ม</label>
                            <input id="submissionDateFrom" type="date" name="date_from" class="form-control"
                                value="{{ request('date_from') }}">
                        </div>
                        <div class="col-md-2">
                            <label for="submissionDateTo" class="form-label small fw-bold">ถึงวันที่</label>
                            <input id="submissionDateTo" type="date" name="date_to" class="form-control"
                                value="{{ request('date_to') }}">
                        </div>
                        <div class="col-md-2 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-filter"></i> กรอง
                            </button>
                            <a href="{{ route('document.submissions.index') }}" class="btn btn-light w-100 border">ล้างค่า</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <p class="mb-0 fs-4">{{ __('รายการแบบฟอร์มที่ส่งทั้งหมด') }}</p>
                </div>
                <div class="card-body overflow-auto">
                    <table class="table table-hover table-bordered align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">ฟอร์ม</th>
                                <th scope="col">องค์กร</th>
                                <th scope="col">ผู้ส่ง</th>
                                <th scope="col">วันที่ส่ง</th>
                                <th scope="col">ดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($submissions as $index => $submission)
                                <tr>
                                    <td>{{ (($submissions->currentPage() - 1) * $submissions->perPage()) + $index + 1 }}</td>
                                    <td>{{ optional($submission->getForm)->title ?? '-' }}</td>
                                    <td>{{ optional($submission->organization)->name ?? '-' }}</td>
                                    <td>{{ optional($submission->submittedByUser)->full_name ?: '-' }}</td>
                                    <td>{{ \Carbon\Carbon::parse($submission->created_at)->thaidate('j F Y \\เวลา H:i:s') }}</td>
                                    <td>
                                        <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal"
                                            data-bs-target="#deleteSubmissionModal"
                                            data-delete-url="{{ route('document.submissions.destroy', $submission->submission_id) }}">
                                            <i class="bi bi-trash"></i>
                                            <span class="visually-hidden">ลบ submission</span>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center">ไม่พบข้อมูล</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    {{ $submissions->links() }}
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteSubmissionModal" tabindex="-1" aria-labelledby="deleteSubmissionModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteSubmissionModalLabel">ยืนยันการลบ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    ยืนยันการลบแบบฟอร์มนี้? การลบนี้จะไม่แสดงในหน้ารายงานอีกต่อไป
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <form id="deleteSubmissionForm" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">ลบแบบฟอร์ม</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('deleteSubmissionModal');
            const form = document.getElementById('deleteSubmissionForm');

            modal.addEventListener('show.bs.modal', function (event) {
                form.action = event.relatedTarget.dataset.deleteUrl;
            });
        });
    </script>
@endpush
