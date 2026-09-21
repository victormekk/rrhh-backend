<?php

namespace App\Traits;

// Convierte un monto a su representación en letras para constancias y
// documentos legales (formato "TRECE MIL LEMPIRAS CON 00/100").
trait ConvierteMontoALetras
{
    // Nota: PHP 8.1 no permite constantes en traits, se usan propiedades estáticas.
    private static array $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
    private static array $diezADiecinueve = [
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE',
        'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
    ];
    private static array $veintis = [
        'VEINTE', 'VEINTIÚN', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO',
        'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];
    private static array $decenas = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    private static array $centenas = [
        '', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS',
    ];

    protected function montoEnLetras(float $monto, string $moneda = 'LEMPIRAS'): string
    {
        $monto    = round($monto, 2);
        $entero   = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);

        $letras = $entero === 0 ? 'CERO' : $this->enteroALetras($entero);

        return sprintf('%s %s CON %02d/100', $letras, strtoupper($moneda), $centavos);
    }

    private function enteroALetras(int $n): string
    {
        if ($n < 30) return $this->menorTreinta($n);
        if ($n < 100) return $this->decenas($n);
        if ($n < 1000) return $this->centenas($n);
        if ($n < 1000000) return $this->miles($n);

        return $this->millones($n);
    }

    private function menorTreinta(int $n): string
    {
        if ($n < 10) return self::$unidades[$n];
        if ($n < 20) return self::$diezADiecinueve[$n - 10];

        return self::$veintis[$n - 20];
    }

    private function decenas(int $n): string
    {
        $d = intdiv($n, 10);
        $u = $n % 10;

        return $u === 0 ? self::$decenas[$d] : self::$decenas[$d] . ' Y ' . self::$unidades[$u];
    }

    private function centenas(int $n): string
    {
        if ($n === 100) return 'CIEN';

        $c     = intdiv($n, 100);
        $resto = $n % 100;
        $texto = self::$centenas[$c];

        return $resto > 0 ? "{$texto} " . $this->enteroALetras($resto) : $texto;
    }

    private function miles(int $n): string
    {
        $miles = intdiv($n, 1000);
        $resto = $n % 1000;
        $texto = $miles === 1 ? 'MIL' : $this->enteroALetras($miles) . ' MIL';

        return $resto > 0 ? "{$texto} " . $this->enteroALetras($resto) : $texto;
    }

    private function millones(int $n): string
    {
        $millones = intdiv($n, 1000000);
        $resto    = $n % 1000000;
        $texto    = $millones === 1 ? 'UN MILLÓN' : $this->enteroALetras($millones) . ' MILLONES';

        return $resto > 0 ? "{$texto} " . $this->enteroALetras($resto) : $texto;
    }
}
