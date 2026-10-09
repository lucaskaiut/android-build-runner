<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BranchName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('O nome da branch é obrigatório.');

            return;
        }

        if (strlen($value) > 255
            || preg_match('/[\s~^:?*\[\\\\]/', $value) === 1
            || str_contains($value, '..')
            || str_starts_with($value, '/')
            || str_ends_with($value, '/')
            || str_ends_with($value, '.')
            || str_contains($value, '//')) {
            $fail('O nome da branch é inválido.');
        }
    }
}
