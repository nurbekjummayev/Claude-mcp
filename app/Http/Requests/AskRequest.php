<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'max:50000'],
            'context' => ['nullable', 'string', 'max:50000'],
            'system' => ['nullable', 'string', 'max:10000'],
            'max_turns' => ['nullable', 'integer', 'min:1', 'max:50'],
            'model' => ['nullable', 'string', 'max:50'],
        ];
    }
}
