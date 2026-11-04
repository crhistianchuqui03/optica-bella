<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use RuntimeException;

/**
 * Conexión PDO Singleton.
 * Las credenciales y el endpoint se leen exclusivamente de variables de entorno.
 * DB_HOST admite el endpoint privado de RDS; DB_PORT por defecto es 3306.
 */
final class Database
{
    private static ?self $instancia = null;

    private PDO $pdo;

    private function __construct()
    {
        $host    = getenv('DB_HOST');
        $puerto  = getenv('DB_PORT');
        $nombre  = getenv('DB_NAME');
        $usuario = getenv('DB_USER');
        $clave   = getenv('DB_PASS');

        if ($host === false || $host === ''
            || $nombre === false || $nombre === ''
            || $usuario === false || $usuario === ''
            || $clave === false || $clave === ''
        ) {
            throw new RuntimeException(
                'Configuración de base de datos incompleta. Defina DB_HOST, DB_NAME, DB_USER y DB_PASS en .env.'
            );
        }

        $puerto = ($puerto === false || $puerto === '') ? '3306' : $puerto;

        $puertoValido = filter_var($puerto, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($puertoValido === false) {
            throw new RuntimeException('DB_PORT inválido. Debe ser un puerto TCP entre 1 y 65535.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            (string) $puertoValido,
            $nombre
        );

        $this->pdo = new PDO($dsn, $usuario, $clave, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // RDS puede venir en UTC; fijamos la sesión a America/Lima (sin DST).
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-05:00'",
        ]);
    }

    public static function getInstance(): self
    {
        if (self::$instancia === null) {
            self::$instancia = new self();
        }

        return self::$instancia;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public static function getConnection(): PDO
    {
        return self::getInstance()->getPdo();
    }

    private function __clone()
    {
    }

    public function __wakeup(): void
    {
        throw new RuntimeException('No se permite deserializar la conexión a la base de datos.');
    }
}