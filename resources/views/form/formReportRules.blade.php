@extends('layouts.app')

@section('content')
    <div class="px-3 px-md-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <p class="mb-0 fs-4">รายงานภาคบังคับ: {{ $form->title }}</p>
                @if ($categoryName)
                    <a href="{{ route('form.table', $categoryName) }}" class="btn btn-outline-secondary btn-sm">กลับ</a>
                @endif
            </div>
            <div class="card-body">
                <div id="ruleAlert"></div>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead>
                            <tr>
                                <th>รายการ</th>
                                <th>นับเมื่อ</th>
                                <th>ค่าที่ถือว่าทำแล้ว</th>
                                <th>นับไม่ซ้ำตาม</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rules as $rule)
                                @php
                                    $itemSection = collect($catalog)->first(fn ($section) => array_key_exists($rule->item_code, $section['items']));
                                    $item = $itemSection['items'][$rule->item_code] ?? null;
                                    $conditionDeleted = $rule->condition_field_id && (!$rule->conditionField || $rule->conditionField->trashed());
                                    $distinctDeleted = $rule->distinct_by === 'field' && (!$rule->distinctField || $rule->distinctField->trashed());
                                @endphp
                                <tr>
                                    <td>{{ $item['label'] ?? $rule->item_code }}</td>
                                    <td>
                                        @if ($conditionDeleted)
                                            <span class="badge bg-warning text-dark">ช่องที่ใช้ถูกลบแล้ว</span>
                                        @elseif ($rule->conditionField)
                                            {{ $rule->conditionField->label }}
                                        @else
                                            ทุกใบ
                                        @endif
                                    </td>
                                    <td>{{ !empty($rule->condition_values) ? implode(', ', $rule->condition_values) : ($rule->conditionField ? '-' : 'ทุกค่า') }}</td>
                                    <td>
                                        @switch($rule->distinct_by)
                                            @case('user') คน @break
                                            @case('vehicle') รถ @break
                                            @case('field')
                                                @if ($distinctDeleted)
                                                    <span class="badge bg-warning text-dark">ช่องที่ใช้ถูกลบแล้ว</span>
                                                @else
                                                    {{ $rule->distinctField->label }}
                                                @endif
                                                @break
                                            @default ไม่นับ
                                        @endswitch
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-danger btn-sm delete-rule" data-rule-id="{{ $rule->id }}">ลบ</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">ยังไม่มีรายการที่ผูกไว้</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <hr>
                <h5>เพิ่มรายการใหม่</h5>
                <form id="addRuleForm" class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">รายการของกรมฯ</label>
                        <select name="item_code" class="form-control" required>
                            <option value="">-- เลือกรายการ --</option>
                            @foreach ($catalog as $section)
                                <optgroup label="{{ $section['label'] }}">
                                    @foreach ($section['items'] as $code => $item)
                                        <option value="{{ $code }}">{{ $item['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">นับเมื่อ</label>
                        <select name="condition_field_id" id="conditionFieldSelect" class="form-control">
                            <option value="">ทุกใบ</option>
                            @foreach ($availableFields as $field)
                                <option value="{{ $field->id }}" data-type="{{ $field->type }}" data-options='@json(collect($field->options)->pluck("value")->values())'>{{ $field->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3" id="conditionValuesWrap" style="display:none">
                        <label class="form-label">ค่าที่ถือว่าทำแล้ว</label>
                        <div id="conditionValuesOptions"></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">นับไม่ซ้ำตาม</label>
                        <select name="distinct_by" id="distinctBySelect" class="form-control" required>
                            <option value="none">ไม่นับ</option>
                            @if ($form->select_user)<option value="user">คน</option>@endif
                            @if ($form->select_vehicle)<option value="vehicle">รถ</option>@endif
                            <option value="field">ช่องในฟอร์ม</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="distinctFieldWrap" style="display:none">
                        <label class="form-label">ช่องที่ใช้นับไม่ซ้ำ</label>
                        <select name="distinct_field_id" class="form-control">
                            @foreach ($availableFields as $field)
                                <option value="{{ $field->id }}">{{ $field->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">เพิ่ม</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const conditionFieldSelect = document.getElementById('conditionFieldSelect');
    const conditionValuesWrap = document.getElementById('conditionValuesWrap');
    const conditionValuesOptions = document.getElementById('conditionValuesOptions');
    const distinctBySelect = document.getElementById('distinctBySelect');
    const distinctFieldWrap = document.getElementById('distinctFieldWrap');
    const alertBox = document.getElementById('ruleAlert');

    function refreshConditionValues() {
        const selected = conditionFieldSelect.options[conditionFieldSelect.selectedIndex];
        let options = [];
        try { options = JSON.parse(selected.dataset.options || '[]'); } catch (e) { options = []; }
        if ((selected.dataset.type === 'select' || selected.dataset.type === 'autocomplete') && options.length) {
            conditionValuesWrap.style.display = '';
            conditionValuesOptions.replaceChildren();
            options.forEach(function (value, index) {
                const wrapper = document.createElement('div');
                wrapper.className = 'form-check';
                wrapper.innerHTML = '<input class="form-check-input" type="checkbox" name="condition_values[]" value="">' +
                    '<label class="form-check-label"></label>';
                wrapper.querySelector('input').value = value;
                wrapper.querySelector('input').id = 'condition_value_' + index;
                wrapper.querySelector('label').htmlFor = wrapper.querySelector('input').id;
                wrapper.querySelector('label').textContent = value;
                conditionValuesOptions.appendChild(wrapper);
            });
        } else {
            conditionValuesWrap.style.display = 'none';
            conditionValuesOptions.replaceChildren();
        }
    }

    conditionFieldSelect.addEventListener('change', refreshConditionValues);
    distinctBySelect.addEventListener('change', function () {
        distinctFieldWrap.style.display = distinctBySelect.value === 'field' ? '' : 'none';
    });

    document.getElementById('addRuleForm').addEventListener('submit', function (event) {
        event.preventDefault();
        fetch('{{ route('form.report-rules.store', $form->form_id) }}', {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json'},
            body: new FormData(event.target)
        }).then(function (response) {
            return response.json().then(function (body) { return {status: response.status, body: body}; });
        }).then(function (result) {
            if (result.status >= 400) {
                const errors = result.body.errors;
                const message = typeof errors === 'string' ? errors : Object.values(errors || {}).flat().join(' ');
                alertBox.innerHTML = '<div class="alert alert-danger">' + (message || 'เกิดข้อผิดพลาด') + '</div>';
                return;
            }
            window.location.reload();
        });
    });

    document.querySelectorAll('.delete-rule').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!confirm('ยืนยันการลบรายการนี้?')) return;
            fetch('{{ url('/forms/'.$form->form_id.'/report-rules') }}/' + button.dataset.ruleId, {
                method: 'DELETE',
                headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json'}
            }).then(function () { window.location.reload(); });
        });
    });
});
</script>
@endpush
