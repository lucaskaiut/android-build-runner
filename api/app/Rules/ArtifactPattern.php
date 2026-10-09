<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ArtifactPattern implements ValidationRule
{
    /**
     * Glob pattern (Str::is compatible) relative to the artifact name.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('O padrão de artefato é obrigatório.');

            return;
        }

        if (strlen($value) > 255
            || str_contains($value, '..')
            || str_contains($value, '\\')
            || str_starts_with($value, '/')
            || preg_match('/[^A-Za-z0-9._\-*?\[\]\/ ]/', $value) === 1) {
            $fail('O padrão de artefato é inválido. Use nomes de arquivo com "*" se necessário.');
        }
    }
}
