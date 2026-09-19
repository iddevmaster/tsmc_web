<?php

namespace App\Http\Controllers;

use App\Services\MandatoryReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MandatoryReportController extends Controller
{
    public function __construct(private readonly MandatoryReportService $service)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'quarter' => ['nullable', 'integer', 'between:1,4'],
        ]);

        $quarter = (int) ($request->input('quarter') ?: now()->quarter);
        $year = now()->year;
        $orgId = Auth::user()->is_tsm
            ? session('connected_org')
            : Auth::user()->userDetail?->org;
        $report = $this->service->build($orgId, $quarter, $year);

        return view('exportDocument.mandatoryReport', compact('report', 'quarter', 'year'));
    }
}
