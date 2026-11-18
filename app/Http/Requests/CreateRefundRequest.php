<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        $data = parent::validationData();

        // Global string trimming must not turn malformed JSON money into valid input.
        if ($this->isJson()) {
            $original = json_decode($this->getContent(), true);
            if (is_array($original) && array_key_exists('amount', $original)) {
                $data['amount'] = $original['amount'];
            }
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'amount' => ['bail', 'required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)
                    || preg_match('/\A\d{1,8}(\.\d{1,2})?\z/', $value) !== 1
                    || bccomp($value, '0.00', 2) <= 0) {
                    $fail('The amount must be a positive decimal string with at most two decimal places.');
                }
            }],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255'],
            'mode' => ['required', 'string', 'in:auto,needs_approval'],
        ];
    }
}
