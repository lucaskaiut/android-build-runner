<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeRelativePath implements ValidationRule
{
    /**
     * Relative directory inside the repository workspace. Absolute paths and
     * parent traversal are never accepted.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Informe um diretório relativo.');

            return;
        }

        $path = trim($value);

        if (strlen($path) > 255
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || str_contains($path, ':')
            || str_starts_with($path, '/')
            || str_contains($path, '..')) {
            $fail('O diretório deve ser um caminho relativo e não pode conter "..".');

            return;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' && $path !== '.') {
                $fail('O diretório informado é inválido.');

                return;
            }

            if (preg_match('/[^A-Za-z0-9._\- ]/', $segment) === 1) {
                $fail('O diretório contém caracteres não permitidos.');

                return;
            }
        }
    }
}
