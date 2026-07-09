<?php

namespace App\Http\Requests;

use App\Services\TboAir\DTO\SelectionInput;
use Illuminate\Foundation\Http\FormRequest;

class FareDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level `can:flight.search` gates authorization.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'traceId' => ['required', 'string', 'max:255'],
            // ResultIndex is an opaque, sometimes very long provider token (well over 255
            // chars in production) — bound it generously, not at a display length.
            'resultIndex' => ['required', 'string', 'max:8192'],
        ];
    }

    public function selection(): SelectionInput
    {
        return new SelectionInput(
            (string) $this->validated('traceId'),
            (string) $this->validated('resultIndex'),
        );
    }
}
