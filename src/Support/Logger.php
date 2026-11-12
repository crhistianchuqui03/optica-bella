<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use stdClass;

/**
 * Log estructurado en una línea JSON por evento, pensado para
 * ingestión directa en Wazuh SIEM.
 */
final class Logger
{
    public static function evento(
        string $evento,
        string $estado,
        array $detalles = [],
        ?string $usuario = null,
        ?string $ip = null
    ): void {
        $ruta = Env::obtener('SECURITY_LOG_PATH', '/var/log/app/security.log')
            ?? '/var/log/app/security.log';

        $registro = [
            'app'       => 'optica_bellavista',
            'timestamp' => (new DateTimeImmutable('now', self::zonaHoraria()))->format(DateTimeInterface::ATOM),
            'event'     => $evento,
            'user'      => $usuario,
            'ip'        => $ip,
            'status'    => $estado,
            'details'   => $detalles === [] ? new stdClass() : $detalles,
        ];

        $linea = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($linea === false) {
            error_log('[bellavista] No se pudo serializar el evento de seguridad: ' . $evento);

            return;
        }

        try {
            $directorio = dirname($ruta);

            if (!is_dir($directorio)) {
                @mkdir($directorio, 0770, true);
            }

            if (!is_dir($directorio) || !is_writable($directorio)) {
                throw new \RuntimeException('Directorio de logs no escribible: ' . $directorio);
            }

            $resultado = @file_put_contents($ruta, $linea . PHP_EOL, FILE_APPEND | LOCK_EX);

            if ($resultado === false) {
                throw new \RuntimeException('No se pudo escribir el log de seguridad.');
            }

            @chmod($ruta, 0640);
        } catch (\Throwable $excepcion) {
            error_log('[bellavista] ' . $excepcion->getMessage() . ' Evento: ' . $linea);
        }
    }

    private static function zonaHoraria(): DateTimeZone
    {
        $zona = Env::obtener('APP_TIMEZONE', 'America/Lima') ?? 'America/Lima';

        try {
            return new DateTimeZone($zona);
        } catch (\Throwable) {
            return new DateTimeZone('America/Lima');
        }
    }
}