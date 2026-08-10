<?php

namespace App\Http\Requests;

use App\Models\WalletLoadRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreLoadRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', WalletLoadRequest::class);
    }

    protected function prepareForValidation(): void
    {
        // Accept "1,500.00" from the form; the service normalizes again anyway.
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => str_replace(',', '', $this->input('amount'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // decimal(14,2) — the ceiling keeps a typo from creating an absurd request.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.min' => 'The amount must be greater than zero.',
            'amount.decimal' => 'The amount may have at most 2 decimal places.',
        ];
    }
}
