<?php

declare(strict_types=1);

/**
 * Conexión Singleton a la base de datos mediante PDO.
 * Lee credenciales desde variables de entorno con valores fallback.
 */
final class Database
{
    private static ?Database $instancia = null;

    private PDO $pdo;

    private function __construct()
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $name = getenv('DB_NAME') ?: 'optica_bellavista';
        $user = getenv('DB_USER') ?: 'bellavista_user';
        $pass = getenv('DB_PASS') ?: 'Bellavista2026!';

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function getInstance(): Database
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
    }
}
