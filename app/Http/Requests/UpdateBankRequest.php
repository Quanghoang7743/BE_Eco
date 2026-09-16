<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBankRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $bankId = $this->route('bank')?->id;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('banks', 'code')->ignore($bankId),
            ],
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|max:2048',
            'is_active' => 'nullable|boolean',
        ];
    }
}
