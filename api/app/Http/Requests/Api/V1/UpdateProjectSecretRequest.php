<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-platform') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'value' => ['required', 'string', 'max:65535', 'not_regex:/\0/'],
        ];
    }
}
