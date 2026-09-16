<?php

namespace App\Http\Controllers;

use App\Services\SpecialistIncomeService;
use Illuminate\Http\Request;

class SpecialistIncomeController extends Controller
{
    public function index(Request $request, SpecialistIncomeService $incomeService)
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'string', 'max:36'],
            'ticket_type_code' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'specialist_id' => ['nullable', 'integer'],
        ]);

        return view('admin.specialists.income.index', $incomeService->report($request));
    }
}
