<?php

declare(strict_types=1);

namespace App\Support;

use DateTime;

/**
 * Utilidades de formato y validación compartidas por los controladores.
 */
final class Formato
{
    public static function decimal(mixed $valor): string
    {
        return sprintf('%.2f', (float) $valor);
    }

    public static function fechaValida(string $fecha): bool
    {
        $objeto = DateTime::createFromFormat('Y-m-d', $fecha);

        return $objeto !== false && $objeto->format('Y-m-d') === $fecha;
    }

    public static function textoONulo(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}