<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BranchPattern implements ValidationRule
{
    /**
     * Exact branch names or simple wildcard patterns (release/*).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('O padrão de branch é obrigatório.');

            return;
        }

        if (strlen($value) > 255
            || preg_match('/[^A-Za-z0-9._\/\-*]/', $value) === 1
            || str_contains($value, '..')
            || str_starts_with($value, '/')
            || str_ends_with($value, '/')
            || str_contains($value, '//')) {
            $fail('O padrão de branch é inválido. Use letras, números, ".", "-", "_", "/" e "*".');
        }
    }
}
