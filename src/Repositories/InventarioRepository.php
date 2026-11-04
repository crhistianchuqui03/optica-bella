<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Acceso a datos del inventario de monturas.
 */
final class InventarioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function buscar(int $id): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventario_monturas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch();
    }

    public function listar(bool $todos): array
    {
        $orden = 'ORDER BY marca ASC, codigo_montura ASC';

        if ($todos) {
            $stmt = $this->pdo->query('SELECT * FROM inventario_monturas ' . $orden . ' LIMIT 200');

            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->query(
            'SELECT * FROM inventario_monturas WHERE stock > 0 ' . $orden
        );

        return $stmt->fetchAll();
    }

    public function buscarPorTexto(string $texto): array
    {
        $patron = '%' . $texto . '%';

        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM inventario_monturas
             WHERE codigo_montura LIKE :patron_codigo
                OR marca LIKE :patron_marca
                OR descripcion_material LIKE :patron_descripcion
             ORDER BY marca ASC, codigo_montura ASC
             LIMIT 50'
        );
        $stmt->execute([
            ':patron_codigo'      => $patron,
            ':patron_marca'       => $patron,
            ':patron_descripcion' => $patron,
        ]);

        return $stmt->fetchAll();
    }

    public function buscarPorCodigo(string $codigo): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM inventario_monturas WHERE codigo_montura = :codigo LIMIT 1'
        );
        $stmt->execute([':codigo' => $codigo]);

        return $stmt->fetch();
    }

    public function existe(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM inventario_monturas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() !== false;
    }

    public function existeCodigoEnOtro(string $codigo, int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM inventario_monturas WHERE codigo_montura = :codigo AND id <> :id LIMIT 1'
        );
        $stmt->execute([':codigo' => $codigo, ':id' => $id]);

        return $stmt->fetch() !== false;
    }

    public function crear(array $datos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventario_monturas (codigo_montura, descripcion_material, genero, marca, tipo_montura, stock)
             VALUES (:codigo, :descripcion, :genero, :marca, :tipo, :stock)'
        );
        $stmt->execute([
            ':codigo'      => $datos['codigo_montura'],
            ':descripcion' => $datos['descripcion_material'],
            ':genero'      => $datos['genero'],
            ':marca'       => $datos['marca'],
            ':tipo'        => $datos['tipo_montura'],
            ':stock'       => $datos['stock'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $datos): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inventario_monturas
             SET codigo_montura = :codigo, descripcion_material = :descripcion, genero = :genero,
                 marca = :marca, tipo_montura = :tipo, stock = :stock
             WHERE id = :id'
        );
        $stmt->execute([
            ':codigo'      => $datos['codigo_montura'],
            ':descripcion' => $datos['descripcion_material'],
            ':genero'      => $datos['genero'],
            ':marca'       => $datos['marca'],
            ':tipo'        => $datos['tipo_montura'],
            ':stock'       => $datos['stock'],
            ':id'          => $id,
        ]);
    }

    public function actualizarPorCodigo(int $id, array $datos): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inventario_monturas
             SET descripcion_material = :descripcion, genero = :genero, marca = :marca,
                 tipo_montura = :tipo, stock = :stock
             WHERE id = :id'
        );
        $stmt->execute([
            ':descripcion' => $datos['descripcion_material'],
            ':genero'      => $datos['genero'],
            ':marca'       => $datos['marca'],
            ':tipo'        => $datos['tipo_montura'],
            ':stock'       => $datos['stock'],
            ':id'          => $id,
        ]);
    }
}