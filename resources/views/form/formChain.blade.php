@extends('layouts.app')

@section('content')
    <div class="">
        <div class="row justify-content-center">
            <div class="px-3 px-md-5">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <p class="mb-0 fs-4">จัดการฟอร์มต่อเนื่อง {{ $form_data->title }}</p>
                            <a href="{{ route('form.table', ['form_category' => $form_data->formCategory->name ]) }}" class="btn btn-secondary btn-sm">กลับ</a>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="d-flex gap-2 mb-4">
                            <select id="nextForm" class="form-select" {{ session('org_status') == 2 ? 'disabled' : '' }}>
                                <option value="">เลือกฟอร์มถัดไป</option>
                                @foreach ($candidates as $candidate)
                                    <option value="{{ $candidate->id }}">{{ $candidate->title }}</option>
                                @endforeach
                            </select>
                            <button id="addNextForm" type="button" class="btn btn-success text-nowrap" {{ session('org_status') == 2 ? 'disabled' : '' }}>+ เพิ่มฟอร์มถัดไป</button>
                        </div>

                        @forelse ($chainLinks as $link)
                            @if ($link->nextForm)
                                @php($maps = $link->fieldMaps->keyBy('target_field_id'))
                                <div class="border rounded p-3 mb-4" data-chain-link="{{ $link->id }}">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h2 class="fs-5 mb-0">ฟอร์มถัดไป: {{ $link->nextForm->title }}</h2>
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-link" data-link-id="{{ $link->id }}" {{ session('org_status') == 2 ? 'disabled' : '' }}>ลบ</button>
                                    </div>

                                    @if ($form_data->select_user && $link->nextForm->select_user)
                                        <div class="form-check mb-2">
                                            <input class="form-check-input context-check" type="checkbox" id="copyUser{{ $link->id }}" data-link-id="{{ $link->id }}" data-context="user" {{ $link->copy_selected_user ? 'checked' : '' }} {{ session('org_status') == 2 ? 'disabled' : '' }}>
                                            <label class="form-check-label" for="copyUser{{ $link->id }}">ดึงพนักงานที่เลือก</label>
                                        </div>
                                    @endif
                                    @if ($form_data->select_vehicle && $link->nextForm->select_vehicle)
                                        <div class="form-check mb-3">
                                            <input class="form-check-input context-check" type="checkbox" id="copyVehicle{{ $link->id }}" data-link-id="{{ $link->id }}" data-context="vehicle" {{ $link->copy_selected_vehicle ? 'checked' : '' }} {{ session('org_status') == 2 ? 'disabled' : '' }}>
                                            <label class="form-check-label" for="copyVehicle{{ $link->id }}">ดึงรถที่เลือก</label>
                                        </div>
                                    @endif

                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>รายการในฟอร์มถัดไป</th>
                                                    <th>ดึงข้อมูลจากฟอร์มนี้</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse ($link->targetFields as $targetField)
                                                    <tr>
                                                        <td>{{ $targetField->label }} <span class="text-muted">({{ $targetField->type }})</span></td>
                                                        <td>
                                                            <select class="form-select form-select-sm field-map" data-link-id="{{ $link->id }}" data-target-field-id="{{ $targetField->id }}" {{ session('org_status') == 2 ? 'disabled' : '' }}>
                                                                <option value="">ไม่ต้องดึงข้อมูล</option>
                                                                @foreach ($sourceFields as $sourceField)
                                                                    <option value="{{ $sourceField->id }}" {{ optional($maps->get($targetField->id))->source_field_id == $sourceField->id ? 'selected' : '' }}>
                                                                        {{ $sourceField->label }} ({{ $sourceField->type }})
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="2" class="text-center text-muted">ไม่มีรายการให้เชื่อม</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        @empty
                            <p class="text-center text-muted mb-0">ยังไม่มีฟอร์มต่อเนื่อง</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const chainLinksUrl = @json(route('form.chain.links.store', ['form_id' => $form_data->form_id]));
        const chainBaseUrl = @json(url('/forms/' . $form_data->form_id . '/chain-links'));
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        function chainRequest(url, method, body = null) {
            return fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: body ? JSON.stringify(body) : null,
            }).then(async (response) => {
                const data = await response.json();
                if (!response.ok || data.errors) throw new Error(data.errors || 'บันทึกข้อมูลไม่สำเร็จ');
                return data;
            });
        }

        document.getElementById('addNextForm').addEventListener('click', () => {
            const nextFormId = document.getElementById('nextForm').value;
            if (!nextFormId) return;

            chainRequest(chainLinksUrl, 'POST', { next_form_id: nextFormId })
                .then(() => window.location.reload())
                .catch((error) => Swal.fire('เกิดข้อผิดพลาด', error.message, 'error'));
        });

        document.querySelectorAll('.remove-link').forEach((button) => {
            button.addEventListener('click', () => {
                chainRequest(`${chainBaseUrl}/${button.dataset.linkId}`, 'DELETE')
                    .then(() => window.location.reload())
                    .catch((error) => Swal.fire('เกิดข้อผิดพลาด', error.message, 'error'));
            });
        });

        document.querySelectorAll('.context-check').forEach((input) => {
            input.addEventListener('change', () => {
                const container = input.closest('[data-chain-link]');
                chainRequest(`${chainBaseUrl}/${input.dataset.linkId}/context`, 'PUT', {
                    copy_selected_user: container.querySelector('[data-context="user"]')?.checked ?? false,
                    copy_selected_vehicle: container.querySelector('[data-context="vehicle"]')?.checked ?? false,
                }).catch((error) => Swal.fire('เกิดข้อผิดพลาด', error.message, 'error'));
            });
        });

        document.querySelectorAll('.field-map').forEach((select) => {
            select.addEventListener('change', () => {
                chainRequest(`${chainBaseUrl}/${select.dataset.linkId}/maps/${select.dataset.targetFieldId}`, 'PUT', {
                    source_field_id: select.value || null,
                }).catch((error) => Swal.fire('เกิดข้อผิดพลาด', error.message, 'error'));
            });
        });
    </script>
    <style>
        #formManagePage {
            background-color: var(--main-color);
        }
    </style>
@endsection
