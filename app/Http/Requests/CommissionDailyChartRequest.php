<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class CommissionDailyChartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $startDate = $this->input('start_date');
            $endDate = $this->input('end_date');

            if ($startDate && $endDate) {
                try {
                    $start = Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
                    $end = Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
                } catch (\Exception $e) {
                    return;
                }

                if ($start->gt($end)) {
                    $validator->errors()->add('start_date', 'start_date phải nhỏ hơn hoặc bằng end_date.');
                }

                if ($start->diffInDays($end) > 30) {
                    $validator->errors()->add('start_date', 'Khoảng thời gian không được quá 31 ngày.');
                }
            }
        });
    }
}
