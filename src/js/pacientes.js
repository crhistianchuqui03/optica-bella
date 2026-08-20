/**
 * Módulo de Pacientes: buscador instantáneo, tabla, registro, detalle y eliminación.
 * Los escuchadores persistentes (modales y formularios) se registran una sola vez
 * para evitar duplicados entre navegaciones.
 */
window.Modulos = window.Modulos || {};

Modulos.pacientes = (function () {
    let contenedor = null;
    let debounceTimer = null;
    let busquedaActual = '';
    let pacientes = [];
    let pacienteDetalleId = null;
    let pacienteEditandoId = null;
    let historiasDetalle = [];
    let guardando = false;
    let inicializado = false;

    function escapar(texto) {
        const div = document.createElement('div');
        div.textContent = String(texto === null || texto === undefined ? '' : texto);
        return div.innerHTML;
    }

    function generoCapitalizado(genero) {
        if (!genero) {
            return '—';
        }
        return genero.charAt(0) + genero.slice(1).toLowerCase();
    }

    function cargarScript(src) {
        return new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = function () {
                reject(new Error('No se pudo cargar ' + src));
            };
            document.head.appendChild(script);
        });
    }

    /* ============================================================
       Render principal
       ============================================================ */
    function render(container) {
        contenedor = container;

        contenedor.innerHTML =
            '<div class="modulo-cabecera">' +
                '<h2 class="vista-titulo">Gestión de Pacientes</h2>' +
                '<button id="btn-nuevo-paciente" class="btn btn-primary" type="button">' +
                    '<span aria-hidden="true">+</span> Nuevo Paciente' +
                '</button>' +
            '</div>' +

            '<div class="buscador">' +
                '<svg class="buscador-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
                    '<circle cx="11" cy="11" r="7"></circle>' +
                    '<path d="m20 20-3.5-3.5"></path>' +
                '</svg>' +
                '<input id="buscar-pacientes" class="form-control" type="search" placeholder="Buscar por nombre o celular..." autocomplete="off">' +
            '</div>' +

            '<div class="panel">' +
                '<div id="tabla-pacientes-slot">Cargando pacientes...</div>' +
            '</div>';

        inicializar();

        document.getElementById('btn-nuevo-paciente').addEventListener('click', abrirNuevoPaciente);

        const buscador = document.getElementById('buscar-pacientes');
        buscador.addEventListener('input', function () {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(function () {
                busquedaActual = buscador.value;
                cargarPacientes(busquedaActual);
            }, 300);
        });

        document.getElementById('tabla-pacientes-slot').addEventListener('click', function (event) {
            const boton = event.target.closest('[data-accion]');
            if (!boton) {
                return;
            }
            const accion = boton.dataset.accion;

            if (accion === 'ver') {
                abrirDetalle(boton.dataset.id);
            } else if (accion === 'nueva') {
                window.App.ir('historias', [boton.dataset.id]);
            } else if (accion === 'editar') {
                abrirEditarPaciente(boton.dataset.id);
            } else if (accion === 'eliminar') {
                eliminarPaciente(boton.dataset.id, boton.dataset.nombre);
            }
        });

        const pendiente = window.App.paramPaciente;
        window.App.paramPaciente = null;

        cargarPacientes(busquedaActual).then(function () {
            if (pendiente) {
                abrirDetalle(pendiente);
            }
        });
    }

    /**
     * Vincula una sola vez los escuchadores persistentes (modales, formularios
     * y manejadores globales) para evitar duplicados entre navegaciones.
     */
    function inicializar() {
        if (inicializado) {
            return;
        }
        inicializado = true;

        crearModales();

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

        document.getElementById('btn-nueva-historia-detalle').addEventListener('click', function () {
            if (pacienteDetalleId !== null) {
                window.App.ir('historias', [pacienteDetalleId]);
            }
        });

        document.getElementById('modal-detalle').addEventListener('click', function (event) {
            const botonFicha = event.target.closest('[data-ver-ficha]');
            if (botonFicha) {
                const id = parseInt(botonFicha.dataset.verFicha, 10);
                const historia = historiasDetalle.find(function (h) {
                    return Number(h.id) === id;
                });
                if (historia) {
                    abrirFicha(historia);
                }
                return;
            }

            const botonEditar = event.target.closest('[data-editar-historia]');
            if (botonEditar) {
                const id = parseInt(botonEditar.dataset.editarHistoria, 10);
                const historia = historiasDetalle.find(function (h) {
                    return Number(h.id) === id;
                });
                if (historia) {
                    cerrarModal(document.getElementById('modal-detalle'));
                    window.App.ir('historias', [
                        String(historia.paciente_id),
                        'editar',
                        String(historia.id)
                    ]);
                }
            }
        });

        document.getElementById('form-paciente').addEventListener('submit', guardarPaciente);
    }

    /* ============================================================
       Carga y búsqueda
       ============================================================ */
    async function cargarPacientes(termino) {
        const limpio = termino.trim();
        const url = 'api/pacientes.php' + (limpio === '' ? '' : '?q=' + encodeURIComponent(limpio));

        try {
            const respuesta = await API.get(url);
            if (respuesta.status === 'success') {
                renderTabla(respuesta.data || []);
                return;
            }
            renderTabla([]);
        } catch (error) {
            renderTablaPendiente(error.message);
        }
    }

    function renderTabla(lista) {
        const slot = document.getElementById('tabla-pacientes-slot');
        pacientes = lista || [];

        if (!lista.length) {
            slot.innerHTML = '<p class="sin-datos">No se encontraron pacientes.</p>';
            return;
        }

        let html =
            '<table class="tabla">' +
                '<thead><tr>' +
                    '<th>Nombres y Apellidos</th>' +
                    '<th>Edad</th>' +
                    '<th>Celular</th>' +
                    '<th>Ocupación</th>' +
                    '<th>Género</th>' +
                    '<th>Acciones</th>' +
                '</tr></thead><tbody>';

        lista.forEach(function (paciente) {
            html +=
                '<tr>' +
                    '<td>' + escapar(paciente.nombres_apellidos) + '</td>' +
                    '<td>' + (paciente.edad === null ? '—' : escapar(paciente.edad)) + '</td>' +
                    '<td>' + escapar(paciente.celular) + '</td>' +
                    '<td>' + escapar(paciente.ocupacion) + '</td>' +
                    '<td>' + generoCapitalizado(paciente.genero) + '</td>' +
                    '<td class="celda-acciones">' +
                        '<button type="button" class="btn btn-ghost btn-sm" data-accion="ver" data-id="' + paciente.id + '">Ver Historial</button>' +
                        '<button type="button" class="btn btn-primary btn-sm" data-accion="nueva" data-id="' + paciente.id + '">Nueva Historia</button>' +
                        '<button type="button" class="btn btn-ghost btn-sm" data-accion="editar" data-id="' + paciente.id + '">Editar</button>' +
                        '<button type="button" class="btn btn-danger-outline btn-sm" data-accion="eliminar" data-id="' + paciente.id +
                            '" data-nombre="' + escapar(paciente.nombres_apellidos) + '">Eliminar</button>' +
                    '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        slot.innerHTML = html;
    }

    function renderTablaPendiente(mensaje) {
        const slot = document.getElementById('tabla-pacientes-slot');
        slot.innerHTML = '<p class="sin-datos">' + escapar(mensaje || 'Error al cargar pacientes.') + '</p>';
    }

    /* ============================================================
       Eliminación
       ============================================================ */
    async function eliminarPaciente(id, nombre) {
        const confirmado = window.confirm(
            '¿Eliminar el paciente "' + nombre + '"?\nEsta acción no se puede deshacer.'
        );

        if (!confirmado) {
            return;
        }

        try {
            const respuesta = await API.post('api/pacientes.php', {
                accion: 'eliminar',
                id: Number(id)
            });

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo eliminar el paciente.');
            }

            window.App.notificar('Paciente eliminado correctamente.', 'ok');
            cargarPacientes(busquedaActual);
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo eliminar el paciente.', 'error');
        }
    }

    /* ============================================================
       Detalle: historial de contratos e historias clínicas
       ============================================================ */
    function historialHtml(paciente, contratos, historias) {
        let contratosHtml = '<p class="sin-datos">Sin contratos registrados.</p>';
        if (contratos.length) {
            contratosHtml =
                '<table class="tabla"><thead><tr>' +
                    '<th>N.º Contrato</th><th>Monto Total</th><th>Estado</th><th>Fecha</th>' +
                '</tr></thead><tbody>';
            contratos.forEach(function (c) {
                contratosHtml +=
                    '<tr>' +
                        '<td>' + escapar(c.numero_contrato) + '</td>' +
                        '<td>S/ ' + Number(c.monto_total).toFixed(2) + '</td>' +
                        '<td>' + escapar(c.estado) + '</td>' +
                        '<td>' + escapar(c.fecha_entrega || '—') + '</td>' +
                    '</tr>';
            });
            contratosHtml += '</tbody></table>';
        }

        let historiasHtml = '<p class="sin-datos">Sin historias clínicas registradas.</p>';
        if (historias.length) {
            historiasHtml = '';
            historias.forEach(function (h) {
                historiasHtml +=
                    '<div class="historia-item">' +
                        '<div class="historia-item-cabezal">' +
                            '<strong>Consulta: ' + escapar(h.fecha_consulta) + '</strong>' +
                            '<span>' + escapar(h.material || '') + (h.tipo_lente ? ' · ' + escapar(h.tipo_lente) : '') + '</span>' +
                        '</div>' +
                        '<div class="historia-medidas">' +
                            '<span><b>V.L. O.D.:</b> ' + escapar(h.esf_od_vl || '—') + '</span>' +
                            '<span><b>V.L. O.I.:</b> ' + escapar(h.esf_oi_vl || '—') + '</span>' +
                            '<span><b>A.D.D.:</b> ' + escapar(h.add_medicion || '—') + '</span>' +
                        '</div>' +
                        (h.tratamiento ? '<p><b>Tratamiento:</b> ' + escapar(h.tratamiento) + '</p>' : '') +
                        (h.indicaciones ? '<p><b>Indicaciones:</b> ' + escapar(h.indicaciones) + '</p>' : '') +
                        '<div class="historia-item-acciones">' +
                            '<button type="button" class="btn btn-ghost btn-sm" data-ver-ficha="' + h.id + '">' +
                                '<span aria-hidden="true">👁️</span> Ver Ficha' +
                            '</button>' +
                            '<button type="button" class="btn btn-danger-outline btn-sm" data-editar-historia="' + h.id + '">' +
                                'Editar Historia' +
                            '</button>' +
                        '</div>' +
                    '</div>';
            });
        }

        return (
            '<div class="form-group">' +
                '<h3 class="detalle-titulo">Datos del Paciente</h3>' +
                '<p class="detalle-datos"><strong>' + escapar(paciente.nombres_apellidos) + '</strong>' +
                ' - Edad: ' + (paciente.edad === null ? '—' : escapar(paciente.edad)) +
                ' - Celular: ' + escapar(paciente.celular) +
                ' - Ocupación: ' + escapar(paciente.ocupacion) + '</p>' +
            '</div>' +
            '<div class="form-group">' +
                '<h3 class="detalle-titulo">Contratos (' + contratos.length + ')</h3>' + contratosHtml +
            '</div>' +
            '<div class="form-group">' +
                '<h3 class="detalle-titulo">Historias Clínicas (' + historias.length + ')</h3>' +
                '<div class="historia-lista">' + historiasHtml + '</div>' +
            '</div>'
        );
    }

    async function abrirDetalle(id) {
        try {
            const respuesta = await API.get('api/pacientes.php?id=' + Math.floor(Number(id)));

            if (respuesta.status !== 'success' || !respuesta.data) {
                throw new Error(respuesta.message || 'No se pudo cargar el paciente.');
            }

            const slot = document.getElementById('detalle-contenido');
            const datos = respuesta.data;
            pacienteDetalleId = datos.paciente.id;
            historiasDetalle = datos.historias_clinicas || [];
            slot.innerHTML = historialHtml(datos.paciente, datos.contratos || [], historiasDetalle);

            abrirModal('modal-detalle');
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo cargar el detalle.', 'error');
        }
    }

    /* ============================================================
       Visor de ficha de refracción
       ============================================================ */
    async function abrirFicha(historia) {
        if (!window.Modulos.historias || typeof window.Modulos.historias.verFicha !== 'function') {
            try {
                await cargarScript('js/historias.js');
            } catch (error) {
                window.App.notificar(error.message || 'No se pudo cargar el visor de fichas.', 'error');
                return;
            }
        }

        window.Modulos.historias.verFicha(historia);
    }

    /* ============================================================
       Modales
       ============================================================ */
    function crearModales() {
        if (document.getElementById('modal-detalle')) {
            return;
        }

        const overlayDetalle = document.createElement('div');
        overlayDetalle.className = 'modal-overlay';
        overlayDetalle.id = 'modal-detalle';
        overlayDetalle.hidden = true;
        overlayDetalle.innerHTML =
            '<div class="modal modal-lg" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-detalle">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-modal-detalle">Detalle del Paciente</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<div class="modal-body" id="detalle-contenido">Cargando...</div>' +
                '<div class="modal-actions">' +
                    '<button id="btn-nueva-historia-detalle" class="btn btn-primary" type="button">Nueva Historia Clínica</button>' +
                    '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cerrar</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlayDetalle);

        const overlayNuevo = document.createElement('div');
        overlayNuevo.className = 'modal-overlay';
        overlayNuevo.id = 'modal-nuevo-paciente';
        overlayNuevo.hidden = true;
        overlayNuevo.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-nuevo">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-modal-nuevo">Nuevo Paciente</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<form id="form-paciente" novalidate>' +
                    '<div class="modal-body">' +
                        '<div class="form-group">' +
                            '<label for="mp-nombres">Nombres y Apellidos *</label>' +
                            '<input id="mp-nombres" name="nombres_apellidos" class="form-control" required>' +
                        '</div>' +
                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="mp-edad">Edad</label>' +
                                '<input id="mp-edad" name="edad" class="form-control" type="number" min="0" max="150" inputmode="numeric">' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="mp-genero">Género</label>' +
                                '<select id="mp-genero" name="genero" class="form-control">' +
                                    '<option value="">— Seleccionar —</option>' +
                                    '<option value="MASCULINO">Masculino</option>' +
                                    '<option value="FEMENINO">Femenino</option>' +
                                    '<option value="OTRO">Otro</option>' +
                                '</select>' +
                            '</div>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="mp-celular">Celular</label>' +
                            '<input id="mp-celular" name="celular" class="form-control" type="tel" inputmode="tel">' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="mp-ocupacion">Ocupación</label>' +
                            '<input id="mp-ocupacion" name="ocupacion" class="form-control">' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cancelar</button>' +
                        '<button type="submit" class="btn btn-primary">Guardar Paciente</button>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(overlayNuevo);
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

    function abrirNuevoPaciente() {
        pacienteEditandoId = null;
        const formulario = document.getElementById('form-paciente');
        formulario.reset();
        document.getElementById('titulo-modal-nuevo').textContent = 'Nuevo Paciente';
        const botonGuardar = formulario.querySelector('button[type="submit"]');
        botonGuardar.textContent = 'Guardar Paciente';
        abrirModal('modal-nuevo-paciente', 'mp-nombres');
    }

    function abrirEditarPaciente(id) {
        const paciente = pacientes.find(function (p) {
            return Number(p.id) === Number(id);
        });

        if (!paciente) {
            window.App.notificar('No se encontró el paciente.', 'error');
            return;
        }

        pacienteEditandoId = Number(paciente.id);

        const formulario = document.getElementById('form-paciente');
        formulario.reset();

        document.getElementById('mp-nombres').value = paciente.nombres_apellidos || '';
        if (paciente.edad !== null && paciente.edad !== undefined) {
            document.getElementById('mp-edad').value = paciente.edad;
        }
        document.getElementById('mp-genero').value = paciente.genero || '';
        document.getElementById('mp-celular').value = paciente.celular || '';
        document.getElementById('mp-ocupacion').value = paciente.ocupacion || '';

        document.getElementById('titulo-modal-nuevo').textContent = 'Editar Paciente';
        const botonGuardar = formulario.querySelector('button[type="submit"]');
        botonGuardar.textContent = 'Guardar Cambios';

        abrirModal('modal-nuevo-paciente', 'mp-nombres');
    }

    /* ============================================================
       Guardado de paciente (una sola ejecución por envío)
       ============================================================ */
    async function guardarPaciente(event) {
        event.preventDefault();

        if (guardando) {
            return;
        }

        const formulario = event.target;
        const boton = formulario.querySelector('button[type="submit"]');

        const datos = { genero: '' };

        new FormData(formulario).forEach(function (valor, clave) {
            datos[clave] = typeof valor === 'string' ? valor.trim() : valor;
        });

        for (const clave in datos) {
            if (!datos[clave]) {
                delete datos[clave];
            }
        }

        const esEdicion = pacienteEditandoId !== null;
        if (esEdicion) {
            datos.accion = 'actualizar';
            datos.id = pacienteEditandoId;
        }

        guardando = true;
        boton.disabled = true;

        try {
            const respuesta = await API.post('api/pacientes.php', datos);

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo guardar el paciente.');
            }

            window.App.notificar(
                esEdicion ? 'Paciente actualizado correctamente.' : 'Paciente registrado correctamente.',
                'ok'
            );
            pacienteEditandoId = null;
            cerrarModal(document.getElementById('modal-nuevo-paciente'));
            cargarPacientes(busquedaActual);
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo guardar el paciente.', 'error');
        } finally {
            guardando = false;
            boton.disabled = false;
        }
    }

    return { render: render };
})();