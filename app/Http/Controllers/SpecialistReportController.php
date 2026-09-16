<?php

namespace App\Http\Controllers;

use App\Services\SpecialistReportService;
use Illuminate\Http\Request;

class SpecialistReportController extends Controller
{
    public function index(Request $request, SpecialistReportService $reportService)
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'specialist_id' => ['nullable', 'integer', 'exists:users,id'],
            'inactive_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return view('admin.specialists.reports.index', $reportService->report($request));
    }
}
