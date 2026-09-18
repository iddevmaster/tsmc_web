@extends('layouts.app')

@section('content')
    <div class="">
        <div class="row justify-content-center">
            <div class="px-3 px-md-5">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between">
                            <p class="mb-0 fs-4">{{ __('ทะเบียนเอกสาร') }} {{ $form_data->title }}</p>
                            <a href="{{ route('document.table.selectform') }}" class="btn btn-secondary btn-sm">กลับ</a>
                        </div>
                    </div>

                    <div class="card-body">
                        {{-- @if (session('success'))
                            <div class="alert alert-success" role="alert">
                                {{ session('success') }}
                            </div>
                        @endif --}}

                        <table class="table table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th scope="col">#</th>
                                    @if ($form_data->select_user)
                                        <th scope="col">ชื่อพนักงาน</th>
                                    @endif
                                    @if ($form_data->select_vehicle)
                                        <th scope="col">ยานพาหนะ</th>
                                    @endif
                                    <th scope="col">วันที่อัปเดตล่าสุด</th>
                                    <th scope="col">สถานะ</th>
                                    <th scope="col">ดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (count($submissions) > 0)
                                    @foreach ($submissions as $index => $submission)
                                        @php
                                            $updatedDate = new Carbon\Carbon($submission->updated_at);
                                            $fill_values = count($submission->getSubmissionValues ?? []);
                                            $null_values = count($submission->getSubmissionValuesIsNull ?? []);
                                            $is_success = $null_values ? false : true;
                                        @endphp
                                        <tr class="{{ $is_success ? 'table-success' : '' }}">
                                            <th scope="row">{{ $index + 1 }}</th>
                                            @if ($form_data->select_user)
                                                <td>{{ optional($submission->getUser)->full_name ?? '-' }}</td>
                                            @endif
                                            @if ($form_data->select_vehicle)
                                                <td>
                                                    {{ optional($submission->getVehicle)->license_plate }} (
                                                    {{ optional($submission->getVehicle)->brand }} )
                                                </td>
                                            @endif
                                            <td>{{ $updatedDate->thaidate('j M Y') }}</td>
                                            <td>{{ $is_success ? "สำเร็จ" : "ยังไม่สำเร็จ" }} ( {{ $fill_values - $null_values  }}/{{ $fill_values }} )</td>
                                            <td>
                                                @if (!$is_success)
                                                    <a href="{{ route('document.submission.edit', ['submission_id' => $submission->submission_id ]) }}" class="btn btn-primary btn-sm" data-bs-toggle="tooltip"
                                                        data-bs-title="ทำแบบฟอร์ม">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                @endif
                                                <a href="{{ route('document.submission.show', ['submission_id' => $submission->submission_id ]) }}" class="btn btn-info btn-sm" data-bs-toggle="tooltip"
                                                    data-bs-title="รายละเอียด">
                                                    <i class="bi bi-list-check"></i>
                                                </a>
                                                <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal"
                                                    data-bs-target="#submissionhis{{ $index }}">
                                                    <i class="bi bi-clock-history"></i>
                                                </button>
                                                @if (!empty($submission->chainActions))
                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal"
                                                        data-bs-target="#chainActions{{ $index }}" title="เอกสารต่อเนื่อง">
                                                        <i class="bi bi-link-45deg"></i>
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                        <!-- Modal -->
                                        @if (!empty($submission->chainActions))
                                            <div class="modal fade" id="chainActions{{ $index }}" tabindex="-1"
                                                aria-labelledby="chainActionsLabel{{ $index }}" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h1 class="modal-title fs-5" id="chainActionsLabel{{ $index }}">เอกสารต่อเนื่อง</h1>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                                aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="d-flex flex-column gap-2">
                                                                @foreach ($submission->chainActions as $chainAction)
                                                                    @if ($chainAction['submission_id'])
                                                                        <a href="{{ route('document.submission.show', ['submission_id' => $chainAction['submission_id']]) }}" class="btn btn-outline-primary">ดูฟอร์มต่อเนื่อง: {{ $chainAction['title'] }}</a>
                                                                    @else
                                                                        <a href="{{ route('document.fill-out', ['form_id' => $chainAction['form_id']]) }}?from_submission={{ $submission->submission_id }}" class="btn btn-primary">ทำฟอร์มต่อเนื่อง: {{ $chainAction['title'] }}</a>
                                                                    @endif
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                        <div class="modal fade" id="submissionhis{{ $index }}" tabindex="-1"
                                            aria-labelledby="submissionhisLabel{{ $index }}" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h1 class="modal-title fs-5" id="submissionhisLabel{{ $index }}">ประวัติการทำเอกสาร</h1>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                            aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <ol class="list-group list-group-numbered">
                                                            @foreach ($submission->getSubmissionHistory ?? [] as $submitHis)
                                                                @php
                                                                    $submitDate = new Carbon\Carbon($submitHis->updated_at);
                                                                @endphp
                                                                <li class="list-group-item d-flex">
                                                                    <div class="d-flex justify-content-around gap-4">
                                                                        <p>{{ optional($submitHis->getUser)->full_name ?? "-" }}</p>
                                                                        <p>{{ $submitDate->thaidate('วันที่ j M Y เวลา H:i:s') }}</p>
                                                                    </div>
                                                                </li>
                                                            @endforeach
                                                        </ol>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="5" class="text-center">ไม่มีข้อมูล</td>
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
        #formCheckTablePage {
            background-color: var(--main-color);
        }
    </style>
@endsection
