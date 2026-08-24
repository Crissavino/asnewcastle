<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CNP rumano: 13 dígitos; el 13.º es un dígito de control calculado con
 * los pesos 2-7-9-1-4-6-3-5-8-2-7-9 (suma mod 11; si da 10, es 1).
 */
class ValidCnp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\d{13}$/', $value)) {
            $fail(__('legitimacion.cnp_invalid'));

            return;
        }

        $weights = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += (int) $value[$i] * $w;
        }
        $control = $sum % 11 === 10 ? 1 : $sum % 11;

        if ($control !== (int) $value[12]) {
            $fail(__('legitimacion.cnp_invalid'));
        }
    }
}
