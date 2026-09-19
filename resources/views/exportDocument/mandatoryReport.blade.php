@extends('layouts.app')

@section('content')
    <div class="row justify-content-center">
        <div class="px-3 px-md-5">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <p class="mb-0 fs-4">รายงานภาคบังคับ</p>
                        <div class="d-flex align-items-center">
                            <label for="mandatoryReportQuarter" class="text-nowrap me-2 mb-0">เลือกไตรมาส</label>
                            <select id="mandatoryReportQuarter" name="quarter" class="form-control border-2 border-primary">
                                @foreach (range(1, 4) as $option)
                                    <option value="{{ $option }}" @selected($quarter === $option)>ไตรมาสที่ {{ $option }} ปี {{ Carbon\Carbon::create($year, 1, 1)->thaidate('Y') }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="card-body overflow-auto">
                    <table class="table table-bordered border-secondary">
                        <thead class="text-center">
                            <tr>
                                <th style="background-color: #E7D3EF">รายการ</th>
                                <th style="background-color: #E7D3EF">จำนวน</th>
                                <th style="background-color: #E7D3EF">หน่วย</th>
                                <th style="background-color: #E7D3EF">จำนวน</th>
                                <th style="background-color: #E7D3EF">หน่วย</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report as $section)
                                <tr><td colspan="5" style="background-color: #dbdbdb"><strong>{{ $section['label'] }}</strong></td></tr>
                                @foreach ($section['items'] as $item)
                                    <tr>
                                        <td>
                                            {{ $item['label'] }}
                                            @if ($item['sources'])
                                                <br><small class="text-muted">จาก: {{ implode(', ', $item['sources']) }}</small>
                                            @endif
                                        </td>
                                        <td class="text-center">{{ $item['times'] ?? '–' }}</td>
                                        <td class="text-center">ครั้ง</td>
                                        <td class="text-center">{{ $item['units'] ?? '–' }}</td>
                                        <td class="text-center">{{ $item['unit'] }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <style>#mandatoryReportPage { background-color: var(--main-color); }</style>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('mandatoryReportQuarter').addEventListener('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('quarter', this.value);
        window.location.href = url.toString();
    });
});
</script>
@endpush
