<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Acceso a datos de usuarios del sistema.
 */
final class UsuarioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listar(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, username, nombre_completo, rol, estado, creado_en
             FROM usuarios
             ORDER BY nombre_completo ASC'
        );

        return $stmt->fetchAll();
    }

    public function buscarPorUsername(string $username): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, nombre_completo, rol, estado
             FROM usuarios
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);

        return $stmt->fetch();
    }

    public function buscar(int $id): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, rol FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch();
    }

    public function existe(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() !== false;
    }

    public function existeUsername(string $username): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);

        return $stmt->fetch() !== false;
    }

    public function actualizarEstado(int $id, int $estado): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET estado = :estado WHERE id = :id');
        $stmt->execute([':estado' => $estado, ':id' => $id]);
    }

    public function actualizar(int $id, string $nombreCompleto, string $rol): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios SET nombre_completo = :nombre, rol = :rol WHERE id = :id'
        );
        $stmt->execute([
            ':nombre' => $nombreCompleto,
            ':rol'    => $rol,
            ':id'     => $id,
        ]);
    }

    public function actualizarPassword(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $id]);
    }

    public function crear(string $username, string $hash, string $nombreCompleto, string $rol): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (username, password_hash, nombre_completo, rol)
             VALUES (:username, :password_hash, :nombre_completo, :rol)'
        );
        $stmt->execute([
            ':username'        => $username,
            ':password_hash'   => $hash,
            ':nombre_completo' => $nombreCompleto,
            ':rol'             => $rol,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}