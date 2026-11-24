<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'size' => ['required', 'integer', 'min:1', 'max:'.(int) config('queuetable.max_party_size')],
            'name' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['size' => '人數', 'name' => '稱呼', 'phone' => '手機號碼'];
    }
}
