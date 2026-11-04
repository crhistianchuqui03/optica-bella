<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UsuarioRepository;
use App\Support\Logger;
use App\Support\Session;

/**
 * Gestión de usuarios. Todas las rutas exigen rol ADMIN.
 */
final class UsuarioController
{
    private UsuarioRepository $usuarios;

    public function __construct()
    {
        $this->usuarios = new UsuarioRepository(Database::getConnection());
    }

    public function index(Request $request): void
    {
        Response::json([
            'status' => 'success',
            'data'   => $this->usuarios->listar(),
        ], 200);
    }

    public function store(Request $request): void
    {
        $input  = $request->json();
        $accion = trim((string) ($input['accion'] ?? ''));

        $sesion         = Session::actual();
        $usuarioActual  = (int) ($sesion['usuario_id'] ?? 0);

        if ($accion === 'cambiar_estado') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                Response::error('Identificador de usuario inválido.', 400);
            }

            $estado = filter_var($input['estado'] ?? 0, FILTER_VALIDATE_INT) === 1 ? 1 : 0;

            if ($estado === 0 && $usuarioActual === $id) {
                Response::error('No puede desactivar su propia cuenta.', 400);
            }

            if (!$this->usuarios->existe($id)) {
                Response::error('Usuario no encontrado.', 404);
            }

            $this->usuarios->actualizarEstado($id, $estado);

            Logger::evento('user_status_changed', 'SUCCESS', [
                'usuario_afectado' => $id,
                'estado'           => $estado,
            ], Session::nombreUsuario(), $request->ip());

            Response::json([
                'status'  => 'success',
                'message' => $estado === 1
                    ? 'Usuario activado correctamente.'
                    : 'Usuario desactivado correctamente.',
            ], 200);
        }

        if ($accion === 'actualizar') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                Response::error('Identificador de usuario inválido.', 400);
            }

            $usuario = $this->usuarios->buscar($id);

            if ($usuario === false) {
                Response::error('Usuario no encontrado.', 404);
            }

            $nombreCompleto = trim((string) ($input['nombre_completo'] ?? ''));

            if ($nombreCompleto === '') {
                Response::error('El campo nombre_completo es obligatorio.', 400);
            }

            $rol = trim((string) ($input['rol'] ?? $usuario['rol']));

            if (!in_array($rol, ['ADMIN', 'VENDEDOR'], true)) {
                Response::error('Rol inválido.', 400);
            }

            if ($usuarioActual === $id && $rol !== 'ADMIN') {
                Response::error('No puede cambiar el rol de su propia cuenta de administrador.', 400);
            }

            $this->usuarios->actualizar($id, $nombreCompleto, $rol);

            Logger::evento('user_updated', 'SUCCESS', [
                'usuario_afectado' => $id,
                'rol'              => $rol,
            ], Session::nombreUsuario(), $request->ip());

            Response::json([
                'status'  => 'success',
                'message' => 'Usuario actualizado correctamente.',
            ], 200);
        }

        if ($accion === 'resetear_password') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                Response::error('Identificador de usuario inválido.', 400);
            }

            $password = trim((string) ($input['password'] ?? ''));

            if (strlen($password) < 6) {
                Response::error('La contraseña debe tener al menos 6 caracteres.', 400);
            }

            if (!$this->usuarios->existe($id)) {
                Response::error('Usuario no encontrado.', 404);
            }

            $this->usuarios->actualizarPassword($id, password_hash($password, PASSWORD_BCRYPT));

            Logger::evento('password_reset', 'SUCCESS', [
                'usuario_afectado' => $id,
            ], Session::nombreUsuario(), $request->ip());

            Response::json([
                'status'  => 'success',
                'message' => 'Contraseña actualizada correctamente.',
            ], 200);
        }

        $username       = trim((string) ($input['username'] ?? ''));
        $nombreCompleto = trim((string) ($input['nombre_completo'] ?? ''));
        $rol            = trim((string) ($input['rol'] ?? 'VENDEDOR'));
        $password       = (string) ($input['password'] ?? '');

        if ($username === '') {
            Response::error('El campo username es obligatorio.', 400);
        }

        if (strlen($username) < 3) {
            Response::error('El usuario debe tener al menos 3 caracteres.', 400);
        }

        if ($nombreCompleto === '') {
            Response::error('El campo nombre_completo es obligatorio.', 400);
        }

        if (!in_array($rol, ['ADMIN', 'VENDEDOR'], true)) {
            Response::error('Rol inválido.', 400);
        }

        if (strlen($password) < 6) {
            Response::error('La contraseña debe tener al menos 6 caracteres.', 400);
        }

        if ($this->usuarios->existeUsername($username)) {
            Response::error('El nombre de usuario ya existe.', 400);
        }

        $id = $this->usuarios->crear(
            $username,
            password_hash($password, PASSWORD_BCRYPT),
            $nombreCompleto,
            $rol
        );

        Logger::evento('user_created', 'SUCCESS', [
            'usuario_afectado' => $id,
            'username'         => $username,
            'rol'              => $rol,
        ], Session::nombreUsuario(), $request->ip());

        Response::json([
            'status'  => 'success',
            'message' => 'Usuario creado correctamente.',
            'data'    => ['id' => $id],
        ], 200);
    }
}