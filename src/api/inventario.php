<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth/session.php';

requerirAutenticacion(['ADMIN', 'VENDEDOR']);

$pdo = Database::getConnection();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de montura inválido.'], 400);
            }

            $stmt = $pdo->prepare('SELECT * FROM inventario_monturas WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $montura = $stmt->fetch();

            if ($montura === false) {
                responderJSON(['status' => 'error', 'message' => 'Montura no encontrada.'], 404);
            }

            responderJSON(['status' => 'success', 'data' => $montura], 200);
        }

        if (isset($_GET['q'])) {
            $q = trim((string) $_GET['q']);

            if ($q === '') {
                responderJSON(['status' => 'error', 'message' => 'Parámetro de búsqueda vacío.'], 400);
            }

            $patron = '%' . $q . '%';

            $stmt = $pdo->prepare(
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

            responderJSON(['status' => 'success', 'data' => $stmt->fetchAll()], 200);
        }

        $orden = 'ORDER BY marca ASC, codigo_montura ASC';

        if (isset($_GET['todos']) && $_GET['todos'] === '1') {
            $stmt = $pdo->query('SELECT * FROM inventario_monturas ' . $orden . ' LIMIT 200');

            responderJSON(['status' => 'success', 'data' => $stmt->fetchAll()], 200);
        }

        $stmt = $pdo->query(
            'SELECT *
             FROM inventario_monturas
             WHERE stock > 0
             ' . $orden
        );

        responderJSON(['status' => 'success', 'data' => $stmt->fetchAll()], 200);

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            responderJSON(['status' => 'error', 'message' => 'JSON inválido.'], 400);
        }

        $codigo      = trim((string) ($input['codigo_montura'] ?? ''));
        $descripcion = trim((string) ($input['descripcion_material'] ?? ''));
        $marca       = trim((string) ($input['marca'] ?? ''));
        $genero      = trim((string) ($input['genero'] ?? ''));
        $tipoMontura = trim((string) ($input['tipo_montura'] ?? 'RECETADO'));
        $stock = isset($input['stock']) && $input['stock'] !== '' ? $input['stock'] : null;

        if ($codigo === '' || $descripcion === '' || $marca === '' || $genero === '') {
            responderJSON(['status' => 'error', 'message' => 'codigo_montura, descripcion_material, marca y genero son obligatorios.'], 400);
        }

        if ($genero !== null && !in_array($genero, ['MASCULINO', 'FEMENINO', 'UNISEX', 'NIÑO'], true)) {
            responderJSON(['status' => 'error', 'message' => 'Género inválido.'], 400);
        }

        if (!in_array($tipoMontura, ['SOL', '5EN1', 'AMARILLO', 'LECTURA', 'SEGURIDAD', 'RECETADO', 'DESCANSO'], true)) {
            responderJSON(['status' => 'error', 'message' => 'Tipo de montura inválido.'], 400);
        }

        if ($stock === null || !is_numeric($stock) || (int) $stock < 0) {
            responderJSON(['status' => 'error', 'message' => 'Stock inválido.'], 400);
        }

        $stockCast = (int) $stock;

        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

        if (is_int($id) && $id > 0) {
            $stmt = $pdo->prepare('SELECT id FROM inventario_monturas WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);

            if ($stmt->fetch() === false) {
                responderJSON(['status' => 'error', 'message' => 'Montura no encontrada.'], 404);
            }

            $stmt = $pdo->prepare('SELECT id FROM inventario_monturas WHERE codigo_montura = :codigo AND id <> :id LIMIT 1');
            $stmt->execute([':codigo' => $codigo, ':id' => $id]);

            if ($stmt->fetch() !== false) {
                responderJSON(['status' => 'error', 'message' => 'El código de montura ya está en uso.'], 400);
            }

            $stmt = $pdo->prepare(
                'UPDATE inventario_monturas
                 SET codigo_montura = :codigo, descripcion_material = :descripcion, genero = :genero,
                     marca = :marca, tipo_montura = :tipo, stock = :stock
                 WHERE id = :id'
            );
            $stmt->execute([
                ':codigo'      => $codigo,
                ':descripcion' => $descripcion,
                ':genero'      => $genero,
                ':marca'       => $marca,
                ':tipo'        => $tipoMontura,
                ':stock'       => $stockCast,
                ':id'          => $id,
            ]);

            responderJSON([
                'status'  => 'success',
                'message' => 'Montura actualizada correctamente.',
                'data'    => ['id' => $id, 'actualizado' => true],
            ], 200);
        }

        $stmt = $pdo->prepare('SELECT id FROM inventario_monturas WHERE codigo_montura = :codigo LIMIT 1');
        $stmt->execute([':codigo' => $codigo]);
        $existente = $stmt->fetch();

        if ($existente !== false) {
            $stmt = $pdo->prepare(
                'UPDATE inventario_monturas
                 SET descripcion_material = :descripcion, genero = :genero, marca = :marca,
                     tipo_montura = :tipo, stock = :stock
                 WHERE id = :id'
            );
            $stmt->execute([
                ':descripcion' => $descripcion,
                ':genero'      => $genero,
                ':marca'       => $marca,
                ':tipo'        => $tipoMontura,
                ':stock'       => $stockCast,
                ':id'          => (int) $existente['id'],
            ]);

            responderJSON([
                'status'  => 'success',
                'message' => 'Montura actualizada correctamente.',
                'data'    => ['id' => (int) $existente['id'], 'actualizado' => true],
            ], 200);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO inventario_monturas (codigo_montura, descripcion_material, genero, marca, tipo_montura, stock)
             VALUES (:codigo, :descripcion, :genero, :marca, :tipo, :stock)'
        );
        $stmt->execute([
            ':codigo'      => $codigo,
            ':descripcion' => $descripcion,
            ':genero'      => $genero,
            ':marca'       => $marca,
            ':tipo'        => $tipoMontura,
            ':stock'       => $stockCast,
        ]);

        responderJSON([
            'status'  => 'success',
            'message' => 'Montura registrada correctamente.',
            'data'    => ['id' => (int) $pdo->lastInsertId(), 'actualizado' => false],
        ], 200);

    default:
        responderJSON(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}