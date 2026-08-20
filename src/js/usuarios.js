/**
 * Módulo de Gestión de Usuarios (solo ADMIN): listado, creación,
 * activación/desactivación y reseteo de contraseña.
 */
window.Modulos = window.Modulos || {};

Modulos.usuarios = (function () {
    let contenedor = null;
    let usuarios = [];
    let guardando = false;
    let inicializado = false;

    function escapar(texto) {
        const div = document.createElement('div');
        div.textContent = String(texto === null || texto === undefined ? '' : texto);
        return div.innerHTML;
    }

    /* ============================================================
       Render principal
       ============================================================ */
    function render(container) {
        contenedor = container;

        contenedor.innerHTML =
            '<div class="modulo-cabecera">' +
                '<h2 class="vista-titulo">Gestión de Usuarios</h2>' +
                '<button id="btn-nuevo-usuario" class="btn btn-primary" type="button">' +
                    '<span aria-hidden="true">+</span> Nuevo Usuario' +
                '</button>' +
            '</div>' +

            '<div class="panel">' +
                '<div id="tabla-usuarios-slot">Cargando usuarios...</div>' +
            '</div>';

        crearModales();
        inicializar();

        document.getElementById('btn-nuevo-usuario').addEventListener('click', abrirNuevoUsuario);

        document.getElementById('tabla-usuarios-slot').addEventListener('click', function (event) {
            const boton = event.target.closest('[data-accion]');
            if (!boton) {
                return;
            }
            const accion = boton.dataset.accion;

            if (accion === 'estado') {
                cambiarEstado(boton.dataset.id, boton.dataset.estado === '1' ? '0' : '1', boton.dataset.nombre);
            } else if (accion === 'resetear') {
                abrirResetPassword(boton.dataset.id, boton.dataset.nombre);
            } else if (accion === 'editar') {
                abrirEditarUsuario(boton.dataset.id);
            }
        });

        cargarUsuarios();
    }

    /* ============================================================
       Carga y tabla
       ============================================================ */
    async function cargarUsuarios() {
        try {
            const respuesta = await API.get('api/usuarios.php');
            if (respuesta.status === 'success') {
                usuarios = respuesta.data || [];
                renderTabla(usuarios);
                return;
            }
            renderTabla([]);
        } catch (error) {
            renderTablaPendiente(error.message);
        }
    }

    function renderTabla(lista) {
        const slot = document.getElementById('tabla-usuarios-slot');

        if (!lista.length) {
            slot.innerHTML = '<p class="sin-datos">No hay usuarios registrados.</p>';
            return;
        }

        let html =
            '<table class="tabla">' +
                '<thead><tr>' +
                    '<th>Usuario</th>' +
                    '<th>Nombre Completo</th>' +
                    '<th>Rol</th>' +
                    '<th>Estado</th>' +
                    '<th>Acciones</th>' +
                '</tr></thead><tbody>';

        lista.forEach(function (u) {
            const activo = Number(u.estado) === 1;
            const estadoHtml = activo
                ? '<span class="badge badge-activo">Activo</span>'
                : '<span class="badge badge-inactivo">Inactivo</span>';
            const esAdmin = window.App.usuario && window.App.usuario.rol === 'ADMIN';
            const botonEstado = activo
                ? '<button type="button" class="btn btn-danger-outline btn-sm" data-accion="estado" ' +
                    'data-id="' + u.id + '" data-estado="1" data-nombre="' + escapar(u.username) + '">' +
                    'Desactivar' + '</button>'
                : '<button type="button" class="btn btn-ghost btn-sm" data-accion="estado" ' +
                    'data-id="' + u.id + '" data-estado="0" data-nombre="' + escapar(u.username) + '">' +
                    'Activar' + '</button>';

            html +=
                '<tr>' +
                    '<td><strong>' + escapar(u.username) + '</strong></td>' +
                    '<td>' + escapar(u.nombre_completo) + '</td>' +
                    '<td>' + escapar(u.rol) + '</td>' +
                    '<td>' + estadoHtml + '</td>' +
                    '<td class="celda-acciones">' +
                        (esAdmin ? botonEstado : '') +
                        '<button type="button" class="btn btn-ghost btn-sm" data-accion="editar" data-id="' + u.id + '">' +
                            'Editar' + '</button>' +
                        '<button type="button" class="btn btn-ghost btn-sm" data-accion="resetear" ' +
                            'data-id="' + u.id + '" data-nombre="' + escapar(u.username) + '">' +
                            'Resetear Contraseña' + '</button>' +
                    '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        slot.innerHTML = html;
    }

    function renderTablaPendiente(mensaje) {
        const slot = document.getElementById('tabla-usuarios-slot');
        slot.innerHTML = '<p class="sin-datos">' + escapar(mensaje || 'Error al cargar usuarios.') + '</p>';
    }

    /* ============================================================
       Acciones: estado y reseteo de contraseña
       ============================================================ */
    async function cambiarEstado(id, nuevoEstado, nombre) {
        const accion = nuevoEstado === '1' ? 'Activar' : 'Desactivar';
        const confirmado = window.confirm('¿' + accion + ' el usuario "' + nombre + '"?');

        if (!confirmado) {
            return;
        }

        try {
            const respuesta = await API.post('api/usuarios.php', {
                accion: 'cambiar_estado',
                id: Number(id),
                estado: Number(nuevoEstado)
            });

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo cambiar el estado del usuario.');
            }

            window.App.notificar(respuesta.message, 'ok');
            cargarUsuarios();
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo cambiar el estado.', 'error');
        }
    }

    function abrirResetPassword(id, nombre) {
        document.getElementById('rp-usuario').textContent = 'Usuario: ' + nombre;
        document.getElementById('rp-password').value = '';
        document.getElementById('rp-id').value = id;
        abrirModal('modal-reset-password', 'rp-password');
    }

    function abrirEditarUsuario(id) {
        const usuario = usuarios.find(function (u) {
            return Number(u.id) === Number(id);
        });

        if (!usuario) {
            window.App.notificar('No se encontró el usuario.', 'error');
            return;
        }

        document.getElementById('eu-id').value = String(usuario.id);
        document.getElementById('eu-username').textContent = 'Usuario: ' + usuario.username;
        document.getElementById('eu-nombre').value = usuario.nombre_completo || '';
        document.getElementById('eu-rol').value = usuario.rol || 'VENDEDOR';

        abrirModal('modal-editar-usuario', 'eu-nombre');
    }

    async function guardarEditarUsuario(event) {
        event.preventDefault();

        if (guardando) {
            return;
        }

        const id = Number(document.getElementById('eu-id').value);
        const nombreCompleto = document.getElementById('eu-nombre').value.trim();
        const rol = document.getElementById('eu-rol').value;

        if (!nombreCompleto) {
            window.App.notificar('El nombre completo es obligatorio.', 'error');
            return;
        }

        const formulario = event.target;
        const boton = formulario.querySelector('button[type="submit"]');
        guardando = true;
        boton.disabled = true;

        try {
            const respuesta = await API.post('api/usuarios.php', {
                accion: 'actualizar',
                id: id,
                nombre_completo: nombreCompleto,
                rol: rol
            });

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo actualizar el usuario.');
            }

            window.App.notificar(respuesta.message, 'ok');
            cerrarModal(document.getElementById('modal-editar-usuario'));
            cargarUsuarios();
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo actualizar el usuario.', 'error');
        } finally {
            guardando = false;
            boton.disabled = false;
        }
    }

    async function guardarResetPassword(event) {
        event.preventDefault();

        if (guardando) {
            return;
        }

        const formulario = event.target;
        const boton = formulario.querySelector('button[type="submit"]');
        const id = Number(document.getElementById('rp-id').value);
        const password = document.getElementById('rp-password').value;

        if (String(password).length < 6) {
            window.App.notificar('La contraseña debe tener al menos 6 caracteres.', 'error');
            return;
        }

        guardando = true;
        boton.disabled = true;

        try {
            const respuesta = await API.post('api/usuarios.php', {
                accion: 'resetear_password',
                id: id,
                password: password
            });

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo actualizar la contraseña.');
            }

            window.App.notificar(respuesta.message, 'ok');
            cerrarModal(document.getElementById('modal-reset-password'));
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo actualizar la contraseña.', 'error');
        } finally {
            guardando = false;
            boton.disabled = false;
        }
    }

    /* ============================================================
       Creación de usuario
       ============================================================ */
    function abrirNuevoUsuario() {
        const formulario = document.getElementById('form-usuario');
        formulario.reset();
        abrirModal('modal-nuevo-usuario', 'nu-username');
    }

    async function guardarUsuario(event) {
        event.preventDefault();

        if (guardando) {
            return;
        }

        const formulario = event.target;
        const boton = formulario.querySelector('button[type="submit"]');

        const datos = {};

        new FormData(formulario).forEach(function (valor, clave) {
            datos[clave] = typeof valor === 'string' ? valor.trim() : valor;
        });

        if (!datos.password || String(datos.password).length < 6) {
            window.App.notificar('La contraseña debe tener al menos 6 caracteres.', 'error');
            return;
        }

        guardando = true;
        boton.disabled = true;

        try {
            const respuesta = await API.post('api/usuarios.php', datos);

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo crear el usuario.');
            }

            window.App.notificar(respuesta.message, 'ok');
            cerrarModal(document.getElementById('modal-nuevo-usuario'));
            cargarUsuarios();
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo crear el usuario.', 'error');
        } finally {
            guardando = false;
            boton.disabled = false;
        }
    }

    /* ============================================================
       Modales y escuchadores persistentes
       ============================================================ */
    function crearModales() {
        if (document.getElementById('modal-nuevo-usuario')) {
            return;
        }

        const overlayNuevo = document.createElement('div');
        overlayNuevo.className = 'modal-overlay';
        overlayNuevo.id = 'modal-nuevo-usuario';
        overlayNuevo.hidden = true;
        overlayNuevo.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-usuario">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-modal-usuario">Nuevo Usuario</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<form id="form-usuario" novalidate>' +
                    '<div class="modal-body">' +
                        '<div class="form-group">' +
                            '<label for="nu-username">Usuario *</label>' +
                            '<input id="nu-username" name="username" class="form-control" required autocomplete="off">' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="nu-nombre">Nombre Completo *</label>' +
                            '<input id="nu-nombre" name="nombre_completo" class="form-control" required>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="nu-rol">Rol</label>' +
                            '<select id="nu-rol" name="rol" class="form-control">' +
                                '<option value="VENDEDOR">Vendedor</option>' +
                                '<option value="ADMIN">Administrador</option>' +
                            '</select>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="nu-password">Contraseña *</label>' +
                            '<input id="nu-password" name="password" class="form-control" type="password" required ' +
                                'autocomplete="new-password">' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cancelar</button>' +
                        '<button type="submit" class="btn btn-primary">Crear Usuario</button>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(overlayNuevo);

        const overlayReset = document.createElement('div');
        overlayReset.className = 'modal-overlay';
        overlayReset.id = 'modal-reset-password';
        overlayReset.hidden = true;
        overlayReset.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="titulo-reset-password">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-reset-password">Resetear Contraseña</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<form id="form-reset-password" novalidate>' +
                    '<div class="modal-body">' +
                        '<input type="hidden" id="rp-id">' +
                        '<p id="rp-usuario" class="sin-datos"></p>' +
                        '<div class="form-group">' +
                            '<label for="rp-password">Nueva Contraseña *</label>' +
                            '<input id="rp-password" name="password" class="form-control" type="password" required ' +
                                'autocomplete="new-password">' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cancelar</button>' +
                        '<button type="submit" class="btn btn-primary">Actualizar Contraseña</button>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(overlayReset);

        const overlayEditar = document.createElement('div');
        overlayEditar.className = 'modal-overlay';
        overlayEditar.id = 'modal-editar-usuario';
        overlayEditar.hidden = true;
        overlayEditar.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="titulo-editar-usuario">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-editar-usuario">Editar Usuario</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<form id="form-editar-usuario" novalidate>' +
                    '<div class="modal-body">' +
                        '<input type="hidden" id="eu-id">' +
                        '<p id="eu-username" class="sin-datos"></p>' +
                        '<div class="form-group">' +
                            '<label for="eu-nombre">Nombre Completo *</label>' +
                            '<input id="eu-nombre" name="nombre_completo" class="form-control" required>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="eu-rol">Rol</label>' +
                            '<select id="eu-rol" name="rol" class="form-control">' +
                                '<option value="VENDEDOR">Vendedor</option>' +
                                '<option value="ADMIN">Administrador</option>' +
                            '</select>' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cancelar</button>' +
                        '<button type="submit" class="btn btn-primary">Guardar Cambios</button>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(overlayEditar);
    }

    function inicializar() {
        if (inicializado) {
            return;
        }
        inicializado = true;

        document.addEventListener('click', function (event) {
            const botonCierre = event.target.closest('[data-cerrar-modal]');
            if (botonCierre) {
                const modal = botonCierre.closest('.modal-overlay');
                if (modal) {
                    cerrarModal(modal);
                }
                return;
            }

            if (event.target.classList && event.target.classList.contains('modal-overlay') && !event.target.hidden) {
                cerrarModal(event.target);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                const abierto = document.querySelector('.modal-overlay:not([hidden])');
                if (abierto) {
                    cerrarModal(abierto);
                }
            }
        });

        document.getElementById('form-usuario').addEventListener('submit', guardarUsuario);
        document.getElementById('form-reset-password').addEventListener('submit', guardarResetPassword);
        document.getElementById('form-editar-usuario').addEventListener('submit', guardarEditarUsuario);
    }

    function abrirModal(id, inputEnfoque) {
        const modal = document.getElementById(id);
        if (!modal) {
            return;
        }
        modal.hidden = false;
        document.body.classList.add('modal-abierto');
        if (inputEnfoque) {
            const campo = document.getElementById(inputEnfoque);
            if (campo) {
                campo.focus();
            }
        }
    }

    function cerrarModal(modal) {
        modal.hidden = true;
        document.body.classList.remove('modal-abierto');
    }

    return { render: render };
})();