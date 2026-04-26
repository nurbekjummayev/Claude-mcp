<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TranslateRequest extends FormRequest
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
            'articles' => ['required', 'array', 'min:1', 'max:50'],
            'articles.*.title' => ['required', 'string', 'max:500'],
            'articles.*.url' => ['required', 'url', 'max:1000'],
            'target_language' => ['nullable', 'string', 'in:uz,ru,en'],
            'format' => ['nullable', 'string', 'in:telegram_markdown,plain'],
            'model' => ['nullable', 'string', 'max:50'],
        ];
    }
}
