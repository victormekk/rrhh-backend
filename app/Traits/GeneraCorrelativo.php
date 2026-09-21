<?php

namespace App\Traits;

use App\Models\DocumentoGenerado;
use Illuminate\Support\Facades\DB;

trait GeneraCorrelativo
{
    // Correlativo de 6 digitos (000001, 000002...) para un tipo de documento.
    // Cada tipo lleva su propia secuencia, con bloqueo de fila para evitar
    // duplicados si dos PDFs del mismo tipo se generan al mismo tiempo.
    protected function siguienteCorrelativo(string $tipo, ?int $referenciaId = null): string
    {
        $numero = DB::transaction(function () use ($tipo, $referenciaId) {
            $ultimo = DocumentoGenerado::where('tipo', $tipo)
                ->lockForUpdate()
                ->max('correlativo');

            $siguiente = ($ultimo ?? 0) + 1;

            DocumentoGenerado::create([
                'tipo'          => $tipo,
                'correlativo'   => $siguiente,
                'referencia_id' => $referenciaId,
            ]);

            return $siguiente;
        });

        return str_pad($numero, 6, '0', STR_PAD_LEFT);
    }
}
