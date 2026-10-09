<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SecretFilePath implements ValidationRule
{
    /**
     * Relative file path inside the repository workspace where a secret file
     * is materialized before the build script runs. Absolute paths, parent
     * traversal and the runner/git control directories are rejected.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Informe o caminho relativo do arquivo.');

            return;
        }

        $path = trim($value);

        if (strlen($path) > 500
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || str_contains($path, ':')
            || str_starts_with($path, '/')
            || str_contains($path, '..')) {
            $fail('O caminho deve ser relativo, sem "..", sem "\\" e sem unidade de disco.');

            return;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                $fail('O caminho informado é inválido.');

                return;
            }

            if (strcasecmp($segment, '.git') === 0 || strcasecmp($segment, '_runner') === 0) {
                $fail('O caminho não pode apontar para .git ou _runner.');

                return;
            }

            if (preg_match('/[^A-Za-z0-9._\- ]/', $segment) === 1) {
                $fail('O caminho contém caracteres não permitidos.');

                return;
            }
        }
    }
}
