<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DecideRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decided_by' => ['required', 'string', 'min:1', 'max:100'],
            'reason' => $this->is('api/agent/refunds/*/reject')
                ? ['required', 'string', 'min:1', 'max:500']
                : ['exclude'],
        ];
    }
}
