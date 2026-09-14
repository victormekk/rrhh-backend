<?php

namespace App\Traits;

trait NombraArchivos
{
    // Token compacto sin espacios ni acentos, para el segmento "Nombre" de
    // "Tipo_Nombre_ddmmaaaa".
    protected function nombreCompacto(string $texto): string
    {
        $n = iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?? $texto;
        return preg_replace('/[^a-zA-Z0-9]/', '', str_replace(' ', '', $n));
    }

    // "Tipo_Nombre_ddmmaaaa.ext" (omite el segmento de nombre si viene vacio).
    protected function nombreArchivo(string $tipo, string $nombre, string $ext): string
    {
        $partes   = array_filter([$tipo, $this->nombreCompacto($nombre)]);
        $partes[] = now()->format('dmY');

        return implode('_', $partes) . '.' . $ext;
    }

    // Para archivos que ya llevan su propio nombre descriptivo (planilla,
    // aguinaldo): solo quita acentos y caracteres invalidos, conserva los espacios.
    protected function sanitizarNombreArchivo(string $texto): string
    {
        $n = iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?? $texto;

        return trim(preg_replace('/[\\\\\/:*?"<>|]/', '', $n));
    }
}
