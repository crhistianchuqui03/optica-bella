<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Carga y consulta variables de entorno desde .env sin sobrescribir
 * las variables reales inyectadas por Docker o el sistema.
 */
final class Env
{
    public static function cargar(string $ruta): void
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            return;
        }

        $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lineas === false) {
            return;
        }

        foreach ($lineas as $linea) {
            $linea = trim($linea);

            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }

            if (str_starts_with($linea, 'export ')) {
                $linea = trim(substr($linea, 7));
            }

            $posicion = strpos($linea, '=');

            if ($posicion === false) {
                continue;
            }

            $clave = trim(substr($linea, 0, $posicion));
            $valor = trim(substr($linea, $posicion + 1));

            if ($clave === '' || getenv($clave) !== false) {
                continue;
            }

            $valor = self::quitarComillas($valor);

            putenv($clave . '=' . $valor);
            $_ENV[$clave] = $valor;
        }
    }

    public static function obtener(string $clave, ?string $porDefecto = null): ?string
    {
        $valor = getenv($clave);

        if ($valor === false || $valor === '') {
            return $porDefecto;
        }

        return $valor;
    }

    public static function requerir(string $clave): string
    {
        $valor = self::obtener($clave);

        if ($valor === null) {
            throw new \RuntimeException(
                'Falta la variable de entorno obligatoria: ' . $clave . '. Revise .env.'
            );
        }

        return $valor;
    }

    public static function entero(string $clave, int $porDefecto): int
    {
        $valor = self::obtener($clave);

        if ($valor === null || filter_var($valor, FILTER_VALIDATE_INT) === false) {
            return $porDefecto;
        }

        return (int) $valor;
    }

    private static function quitarComillas(string $valor): string
    {
        if (strlen($valor) >= 2) {
            $primero = $valor[0];
            $ultimo  = $valor[strlen($valor) - 1];

            if (($primero === '"' && $ultimo === '"') || ($primero === "'" && $ultimo === "'")) {
                return substr($valor, 1, -1);
            }
        }

        return $valor;
    }
}