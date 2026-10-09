<?php

namespace App\Rules;

use App\Calculos\Fechas;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Fecha de inicio laboral: no se permite 29 de febrero (el aniversario no existiría
// 3 de cada 4 años). Se registra el 28 de febrero o el 1 de marzo.
class NoEs29Febrero implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (Fechas::es29DeFebrero($value)) {
                $fail('La fecha de inicio no puede ser 29 de febrero. Registra el 28 de febrero o el 1 de marzo.');
            }
        } catch (\Throwable) {
            // Fecha inválida: la regla "date" ya la rechaza.
        }
    }
}
