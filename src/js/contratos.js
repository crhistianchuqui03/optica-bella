/**
 * Módulo de Contratos de Venta: listado histórico con buscador, hoja de
 * talonario para nuevos contratos y visor/impresión de contratos guardados.
 */
window.Modulos = window.Modulos || {};

Modulos.contratos = (function () {
    let contenedor = null;
    let debounceBusqueda = null;
    let busquedaActual = '';
    let contratos = [];
    let pacientes = [];
    let monturas = [];
    let pacienteSeleccionado = null;
    let monturaSeleccionada = null;
    let pendienteVista = null;
    let contratoEditandoId = null;
    let guardando = false;
    let inicializado = false;

    function escapar(texto) {
        const div = document.createElement('div');
        div.textContent = String(texto === null || texto === undefined ? '' : texto);
        return div.innerHTML;
    }

    function aNumero(valor) {
        return parseFloat(String(valor).replace(',', '.')) || 0;
    }

    function moneda(valor) {
        return 'S/ ' + Number(valor || 0).toFixed(2);
    }

    function hoyIso() {
        const ahora = new Date();
        const mes = String(ahora.getMonth() + 1).padStart(2, '0');
        const dia = String(ahora.getDate()).padStart(2, '0');
        return ahora.getFullYear() + '-' + mes + '-' + dia;
    }

    /* ============================================================
       Render principal
       ============================================================ */
    function render(container, parametros) {
        contenedor = container;
        pacienteSeleccionado = null;
        monturaSeleccionada = null;
        ultimoContrato = null;

        if (parametros && parametros[0] === 'nuevo') {
            pendienteVista = null;
            renderHojaNuevo();
            return;
        }

        renderListado();
    }

    /* ============================================================
       Listado de contratos
       ============================================================ */
    function renderListado() {
        contenedor.innerHTML =
            '<div class="modulo-cabecera">' +
                '<h2 class="vista-titulo">Gestión de Contratos</h2>' +
                '<button id="btn-nuevo-contrato" class="btn btn-primary" type="button">' +
                    '<span aria-hidden="true">+</span> Nuevo Contrato' +
                '</button>' +
            '</div>' +

            '<div class="buscador">' +
                '<svg class="buscador-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
                    '<circle cx="11" cy="11" r="7"></circle>' +
                    '<path d="m20 20-3.5-3.5"></path>' +
                '</svg>' +
                '<input id="buscar-contratos" class="form-control" type="search" ' +
                    'placeholder="Buscar por número de contrato, paciente o código de montura..." autocomplete="off">' +
            '</div>' +

            '<div class="panel">' +
                '<div id="tabla-contratos-slot">Cargando contratos...</div>' +
            '</div>';

        crearModales();
        inicializar();

        document.getElementById('btn-nuevo-contrato').addEventListener('click', function () {
            window.App.ir('contratos', ['nuevo']);
        });

        const buscador = document.getElementById('buscar-contratos');
        buscador.addEventListener('input', function () {
            window.clearTimeout(debounceBusqueda);
            debounceBusqueda = window.setTimeout(function () {
                busquedaActual = buscador.value;
                cargarContratos(busquedaActual);
            }, 300);
        });

        document.getElementById('tabla-contratos-slot').addEventListener('click', function (event) {
            const boton = event.target.closest('[data-accion]');
            if (!boton) {
                return;
            }
            const accion = boton.dataset.accion;

            if (accion === 'ver') {
                abrirVistaContrato(boton.dataset.id);
            } else if (accion === 'editar') {
                abrirEdicionContrato(boton.dataset.id);
            } else if (accion === 'eliminar') {
                anularContrato(boton.dataset.id);
            }
        });

        cargarContratos(busquedaActual).then(function () {
            if (pendienteVista) {
                const id = pendienteVista;
                pendienteVista = null;
                abrirVistaContrato(id);
            }
        });
    }

    async function cargarContratos(termino) {
        const limpio = termino.trim();
        const url = 'api/contratos.php' + (limpio === '' ? '' : '?q=' + encodeURIComponent(limpio));

        try {
            const respuesta = await API.get(url);
            if (respuesta.status === 'success') {
                contratos = respuesta.data || [];
                renderTabla(contratos);
                return;
            }
            renderTabla([]);
        } catch (error) {
            renderTablaPendiente(error.message);
        }
    }

    function renderTabla(lista) {
        const slot = document.getElementById('tabla-contratos-slot');

        if (!lista.length) {
            slot.innerHTML = '<p class="sin-datos">No se encontraron contratos.</p>';
            return;
        }

        let html =
            '<table class="tabla">' +
                '<thead><tr>' +
                    '<th>N.º Contrato</th>' +
                    '<th>Fecha de Registro</th>' +
                    '<th>Paciente</th>' +
                    '<th>Montura</th>' +
                    '<th>Monto Total</th>' +
                    '<th>Saldo Pendiente</th>' +
                    '<th>Estado</th>' +
                    '<th>Acciones</th>' +
                '</tr></thead><tbody>';

        lista.forEach(function (c) {
            const saldo = aNumero(c.saldo_pendiente);
            const saldoHtml = c.estado === 'CANCELADO'
                ? '<span class="saldo-tachado">' + moneda(saldo) + '</span>'
                : moneda(saldo);
            const estadoHtml = c.estado === 'CANCELADO'
                ? '<span class="badge badge-cancelado">CANCELADO</span>'
                : '<span class="badge badge-pendiente">PENDIENTE</span>';
            const monturaHtml = c.codigo_montura
                ? escapar(c.codigo_montura) + (c.montura_marca ? ' · ' + escapar(c.montura_marca) : '')
                : 'Propia del cliente';

            html +=
                '<tr>' +
                    '<td><strong>' + escapar(c.numero_contrato) + '</strong></td>' +
                    '<td>' + escapar(c.creado_en ? String(c.creado_en).slice(0, 10) : '—') + '</td>' +
                    '<td>' + escapar(c.paciente_nombre || '—') + '</td>' +
                    '<td>' + monturaHtml + '</td>' +
                    '<td>' + moneda(c.monto_total) + '</td>' +
                    '<td>' + saldoHtml + '</td>' +
                    '<td>' + estadoHtml + '</td>' +
                    '<td class="celda-acciones">' +
                        '<button type="button" class="btn btn-ghost btn-sm" data-accion="ver" data-id="' + c.id + '">' +
                            '<span aria-hidden="true">👁️</span> Ver Talonario' +
                        '</button>' +
                        '<button type="button" class="btn btn-ghost btn-sm" data-accion="editar" data-id="' + c.id + '">' +
                            'Editar' +
                        '</button>' +
                        '<button type="button" class="btn btn-danger-outline btn-sm" data-accion="eliminar" data-id="' + c.id + '">' +
                            'Anular' +
                        '</button>' +
                    '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        slot.innerHTML = html;
    }

    function renderTablaPendiente(mensaje) {
        const slot = document.getElementById('tabla-contratos-slot');
        slot.innerHTML = '<p class="sin-datos">' + escapar(mensaje || 'Error al cargar contratos.') + '</p>';
    }

    /* ============================================================
       Hoja de nuevo contrato (talonario físico)
       ============================================================ */
    function renderHojaNuevo() {
        contenedor.innerHTML =
            '<div class="modulo-cabecera">' +
                '<h2 class="vista-titulo">Contrato de Venta</h2>' +
                '<button id="btn-volver-contratos" class="btn btn-ghost" type="button">' +
                    '<span aria-hidden="true">←</span> Volver a la lista' +
                '</button>' +
            '</div>' +
            '<p class="vista-subtitulo">Talonario de contratos - Óptica Bellavista</p>' +

            '<form id="form-contrato" class="contrato-hoja" novalidate>' +

                '<div class="contrato-membrete">' +
                    '<div class="contrato-logo">' +
                        '<div class="logo-badge" aria-hidden="true">' +
                            '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">' +
                                '<circle cx="8" cy="12" r="5.2"></circle>' +
                                '<circle cx="16" cy="12" r="5.2"></circle>' +
                                '<path d="M11 10.5c1.3-1 2.7-1 4 0"></path>' +
                            '</svg>' +
                        '</div>' +
                        '<div>' +
                            '<strong>Óptica Bellavista</strong>' +
                            '<span>Contrato de Venta y Servicio de Óptica</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="contrato-numero">' +
                        '<label for="ctr-numero">N.º Contrato</label>' +
                        '<input id="ctr-numero" name="numero_contrato" class="form-control contrato-numero-input" required>' +
                    '</div>' +
                '</div>' +

                '<section class="contrato-seccion">' +
                    '<h3>1. Datos del Cliente</h3>' +
                    '<div class="contrato-dos-col">' +
                        '<div>' +
                            '<div class="buscador">' +
                                '<svg class="buscador-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
                                    '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>' +
                                '</svg>' +
                                '<input id="ctr-buscar-paciente" class="form-control" type="search" ' +
                                    'placeholder="Buscar paciente por nombre o celular..." autocomplete="off">' +
                            '</div>' +
                            '<div id="ctr-picker-lista" class="picker-lista"></div>' +
                        '</div>' +
                        '<div id="ctr-paciente-resumen" class="contrato-resumen" hidden></div>' +
                    '</div>' +
                '</section>' +

                '<section class="contrato-seccion">' +
                    '<h3>2. Datos de la Montura</h3>' +
                    '<div class="contrato-dos-col">' +
                        '<div class="form-group">' +
                            '<label for="ctr-montura">Montura (del inventario)</label>' +
                            '<select id="ctr-montura" name="montura_id" class="form-control">' +
                                '<option value="">— Seleccionar montura —</option>' +
                            '</select>' +
                            '<p id="ctr-stock-info" class="contrato-ayuda" hidden></p>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label class="contrato-check-label">' +
                                '<input type="checkbox" id="ctr-montura-propia">' +
                                '<span>El cliente se trajo montura propia</span>' +
                            '</label>' +
                            '<p class="contrato-ayuda">Si marca esta opción, no se descontará stock del inventario.</p>' +
                        '</div>' +
                    '</div>' +
                '</section>' +

                '<section class="contrato-seccion">' +
                    '<h3>3. Detalle del Trabajo</h3>' +
                    '<div class="form-group">' +
                        '<label for="ctr-detalle">Descripción detallada del trabajo</label>' +
                        '<textarea id="ctr-detalle" name="detalle_contrato" class="form-control" rows="3" ' +
                            'placeholder="Lunas, tratamientos (anti-reflex, blue block), observaciones..."></textarea>' +
                    '</div>' +
                '</section>' +

                '<section class="contrato-seccion">' +
                    '<h3>4. Cálculo Económico</h3>' +
                    '<div class="contrato-calculo">' +
                        '<div class="form-group">' +
                            '<label for="ctr-total">Monto Total (S/)</label>' +
                            '<input id="ctr-total" name="monto_total" class="form-control" type="number" min="0" step="0.01" ' +
                                'inputmode="decimal" required>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="ctr-cuenta">Abono Inicial / A Cuenta (S/)</label>' +
                            '<input id="ctr-cuenta" name="monto_a_cuenta" class="form-control" type="number" min="0" step="0.01" ' +
                                'inputmode="decimal" required>' +
                        '</div>' +
                        '<div class="contrato-saldo" id="ctr-saldo">' +
                            '<span class="kpi-label">Saldo Pendiente</span>' +
                            '<strong class="saldo-valor" id="ctr-saldo-valor">S/ 0.00</strong>' +
                        '</div>' +
                    '</div>' +
                    '<p id="ctr-saldo-error" class="contrato-error" hidden>' +
                        'El abono inicial no puede ser mayor que el monto total.' +
                    '</p>' +
                '</section>' +

                '<section class="contrato-seccion contrato-dos-col">' +
                    '<div class="form-group">' +
                        '<label for="ctr-fecha-entrega">Fecha Estimada de Entrega</label>' +
                        '<input id="ctr-fecha-entrega" name="fecha_entrega" class="form-control" type="date">' +
                    '</div>' +
                    '<div class="form-group">' +
                        '<label for="ctr-comentarios">Observaciones / Comentarios</label>' +
                        '<input id="ctr-comentarios" name="comentarios" class="form-control" ' +
                            'placeholder="Notas adicionales del contrato..." autocomplete="off">' +
                    '</div>' +
                '</section>' +

                '<div class="fi-acciones">' +
                    '<button type="submit" id="btn-generar-contrato" class="btn btn-primary btn-lg">' +
                        'Generar Contrato' +
                    '</button>' +
                '</div>' +
            '</form>';

        crearModales();
        inicializar();

        document.getElementById('btn-volver-contratos').addEventListener('click', function () {
            window.App.ir('contratos');
        });

        document.getElementById('form-contrato').addEventListener('submit', generarContrato);

        const buscadorPaciente = document.getElementById('ctr-buscar-paciente');
        buscadorPaciente.addEventListener('input', function () {
            window.clearTimeout(debounceBusqueda);
            debounceBusqueda = window.setTimeout(function () {
                buscarPacientes(buscadorPaciente.value);
            }, 300);
        });

        const listaPacientes = document.getElementById('ctr-picker-lista');
        listaPacientes.addEventListener('click', function (event) {
            const item = event.target.closest('[data-id]');
            if (!item) {
                return;
            }
            const id = parseInt(item.dataset.id, 10);
            const paciente = pacientes.find(function (p) {
                return Number(p.id) === id;
            });
            if (paciente) {
                seleccionarPaciente(paciente);
            }
        });
        listaPacientes.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                const item = event.target.closest('[data-id]');
                if (item) {
                    event.preventDefault();
                    item.click();
                }
            }
        });

        const selectMontura = document.getElementById('ctr-montura');
        selectMontura.addEventListener('change', seleccionarMontura);

        const chkPropia = document.getElementById('ctr-montura-propia');
        chkPropia.addEventListener('change', function () {
            selectMontura.disabled = chkPropia.checked;
            if (chkPropia.checked) {
                selectMontura.value = '';
                monturaSeleccionada = null;
                ocultarStock();
            } else {
                actualizarStockInfo();
            }
        });

        document.getElementById('ctr-total').addEventListener('input', calcularSaldo);
        document.getElementById('ctr-cuenta').addEventListener('input', calcularSaldo);

        const fechaEntrega = document.getElementById('ctr-fecha-entrega');
        if (!fechaEntrega.value) {
            fechaEntrega.value = hoyIso();
        }

        calcularSaldo();
        cargarMonturas();
        buscarPacientes('');
        cargarSiguienteNumero();
    }

    /* ============================================================
       Paciente (selector/autocompletado)
       ============================================================ */
    async function buscarPacientes(termino) {
        const limpio = termino.trim();
        const url = 'api/pacientes.php' + (limpio === '' ? '' : '?q=' + encodeURIComponent(limpio));
        const slot = document.getElementById('ctr-picker-lista');

        try {
            const respuesta = await API.get(url);
            const lista = respuesta.status === 'success' ? (respuesta.data || []) : [];

            pacientes = lista;

            if (!lista.length) {
                slot.innerHTML = '<p class="sin-datos">No se encontraron pacientes.</p>';
                return;
            }

            let html = '<div class="picker-resultados" role="listbox">';
            lista.forEach(function (paciente) {
                html +=
                    '<button type="button" class="picker-item" data-id="' + paciente.id + '" role="option" tabindex="0">' +
                        '<strong>' + escapar(paciente.nombres_apellidos) + '</strong>' +
                        '<span>' + escapar(paciente.celular) + ' · ' +
                            (paciente.edad === null ? '—' : escapar(paciente.edad) + ' años') + '</span>' +
                    '</button>';
            });
            html += '</div>';
            slot.innerHTML = html;
        } catch (error) {
            slot.innerHTML = '<p class="sin-datos">' + escapar(error.message || 'Error al buscar.') + '</p>';
        }
    }

    function seleccionarPaciente(paciente) {
        pacienteSeleccionado = paciente;

        const resumen = document.getElementById('ctr-paciente-resumen');
        resumen.hidden = false;
        resumen.innerHTML =
            '<span class="kpi-label">Cliente seleccionado</span>' +
            '<strong>' + escapar(paciente.nombres_apellidos) + '</strong>' +
            '<span>Celular: ' + escapar(paciente.celular) + '</span>' +
            '<span>Edad: ' + (paciente.edad === null ? '—' : escapar(paciente.edad)) + ' años</span>' +
            '<span>Ocupación: ' + escapar(paciente.ocupacion) + '</span>';

        const slot = document.getElementById('ctr-picker-lista');
        slot.innerHTML = '<p class="sin-datos">✔ Cliente seleccionado.</p>';
    }

    /* ============================================================
       Montura (inventario)
       ============================================================ */
    async function cargarMonturas() {
        try {
            const respuesta = await API.get('api/inventario.php');
            if (respuesta.status === 'success') {
                monturas = respuesta.data || [];
            }
        } catch (error) {
            monturas = [];
        }

        const select = document.getElementById('ctr-montura');
        let html = '<option value="">— Seleccionar montura —</option>';

        monturas.forEach(function (m) {
            html += '<option value="' + m.id + '">' +
                escapar(m.codigo_montura) + ' · ' + escapar(m.marca) +
                ' (stock: ' + Number(m.stock) + ')</option>';
        });

        select.innerHTML = html;
    }

    function seleccionarMontura() {
        const select = document.getElementById('ctr-montura');
        const valor = select.value;
        monturaSeleccionada = monturas.find(function (m) {
            return Number(m.id) === Number(valor);
        }) || null;
        actualizarStockInfo();
    }

    function actualizarStockInfo() {
        const info = document.getElementById('ctr-stock-info');
        if (!monturaSeleccionada) {
            info.hidden = true;
            return;
        }
        info.hidden = false;
        info.textContent = 'Stock disponible: ' + Number(monturaSeleccionada.stock) +
            ' unidad(es). Se descontará 1 al generar el contrato.';
    }

    function ocultarStock() {
        document.getElementById('ctr-stock-info').hidden = true;
    }

    /* ============================================================
       Cálculo económico automático
       ============================================================ */
    function calcularSaldo() {
        const total = aNumero(document.getElementById('ctr-total').value);
        const cuenta = aNumero(document.getElementById('ctr-cuenta').value);
        const saldo = total - cuenta;

        document.getElementById('ctr-saldo-valor').textContent = 'S/ ' + Math.max(0, saldo).toFixed(2);
        document.getElementById('ctr-saldo-error').hidden = saldo >= 0;
    }

    /* ============================================================
       Número de contrato sugerido
       ============================================================ */
    async function cargarSiguienteNumero() {
        try {
            const respuesta = await API.get('api/contratos.php?siguiente=1');
            if (respuesta.status === 'success' && respuesta.data && respuesta.data.siguiente) {
                const campo = document.getElementById('ctr-numero');
                if (campo && !campo.value) {
                    campo.value = respuesta.data.siguiente;
                }
            }
        } catch (error) {
            // se deja el campo vacío; el backend exige el número
        }
    }

    /* ============================================================
       Generación de contrato
       ============================================================ */
    async function generarContrato(event) {
        event.preventDefault();

        if (guardando) {
            return;
        }

        if (!pacienteSeleccionado) {
            window.App.notificar('Seleccione un paciente para el contrato.', 'error');
            return;
        }

        const total = aNumero(document.getElementById('ctr-total').value);
        const cuenta = aNumero(document.getElementById('ctr-cuenta').value);

        if (cuenta > total) {
            window.App.notificar('El abono inicial no puede ser mayor que el monto total.', 'error');
            return;
        }

        const datos = {
            numero_contrato: document.getElementById('ctr-numero').value.trim(),
            paciente_id: String(pacienteSeleccionado.id),
            monto_total: String(total),
            monto_a_cuenta: String(cuenta),
            detalle_contrato: document.getElementById('ctr-detalle').value.trim(),
            fecha_entrega: document.getElementById('ctr-fecha-entrega').value,
            comentarios: document.getElementById('ctr-comentarios').value.trim()
        };

        datos.montura_id = monturaSeleccionada ? String(monturaSeleccionada.id) : '';

        const boton = document.getElementById('btn-generar-contrato');
        guardando = true;
        boton.disabled = true;

        try {
            const respuesta = await API.post('api/contratos.php', datos);

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo generar el contrato.');
            }

            window.App.notificar('Contrato generado correctamente.', 'ok');
            pendienteVista = Number(respuesta.data.id);
            window.App.ir('contratos');
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo generar el contrato.', 'error');
        } finally {
            guardando = false;
            boton.disabled = false;
        }
    }

    function inicializar() {
        if (inicializado) {
            return;
        }
        inicializado = true;

        document.addEventListener('click', function (event) {
            if (event.target.classList && event.target.classList.contains('modal-overlay') && !event.target.hidden) {
                cerrarModal(event.target);
            }

            const botonCierre = event.target.closest('[data-cerrar-modal]');
            if (botonCierre) {
                cerrarModal(botonCierre.closest('.modal-overlay'));
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

        document.getElementById('btn-imprimir-contrato').addEventListener('click', function () {
            window.print();
        });

        document.getElementById('form-contrato-editar').addEventListener('submit', guardarEdicionContrato);
    }

    /* ============================================================
       Modal de visualización / impresión de un contrato
       ============================================================ */
    function crearModales() {
        if (document.getElementById('modal-contrato-ver')) {
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'modal-contrato-ver';
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="modal modal-lg print-contrato" role="dialog" aria-modal="true" aria-labelledby="titulo-contrato">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-contrato">Contrato de Venta</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<div class="modal-body" id="contrato-ver-contenido"></div>' +
                '<div class="modal-actions">' +
                    '<button type="button" id="btn-imprimir-contrato" class="btn btn-primary">Imprimir</button>' +
                    '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cerrar</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        const overlayEditar = document.createElement('div');
        overlayEditar.className = 'modal-overlay';
        overlayEditar.id = 'modal-contrato-editar';
        overlayEditar.hidden = true;
        overlayEditar.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="titulo-contrato-editar">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-contrato-editar">Editar Contrato</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<form id="form-contrato-editar" novalidate>' +
                    '<div class="modal-body">' +
                        '<div class="form-group">' +
                            '<label for="ce-detalle">Descripción detallada del trabajo</label>' +
                            '<textarea id="ce-detalle" name="detalle_contrato" class="form-control" rows="3"></textarea>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="ce-fecha">Fecha Estimada de Entrega</label>' +
                            '<input id="ce-fecha" name="fecha_entrega" class="form-control" type="date">' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="ce-comentarios">Observaciones / Comentarios</label>' +
                            '<input id="ce-comentarios" name="comentarios" class="form-control" autocomplete="off">' +
                        '</div>' +
                        '<p class="sin-datos">Los importes, abonos y la montura no pueden modificarse una vez creado el contrato.</p>' +
                    '</div>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cancelar</button>' +
                        '<button type="submit" class="btn btn-primary">Guardar Cambios</button>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(overlayEditar);
    }

    function filaDetalle(clave, valor) {
        return '<tr><th>' + escapar(clave) + '</th><td>' +
            (valor === null || valor === undefined || valor === '' ? '—' : escapar(valor)) + '</td></tr>';
    }

    function abrirVistaContrato(id) {
        API.get('api/contratos.php?id=' + Math.floor(Number(id)))
            .then(function (respuesta) {
                if (respuesta.status !== 'success') {
                    throw new Error(respuesta.message || 'No se pudo cargar el contrato.');
                }

                const c = respuesta.data;
                const ingresos = (respuesta.movimientos || []).filter(function (m) {
                    return m.tipo_movimiento === 'INGRESO';
                });
                const abonoBase = Number(c.monto_a_cuenta || 0);
                const abonoTotal = abonoBase > 0
                    ? abonoBase
                    : ingresos.reduce(function (suma, m) {
                        return suma + Number(m.monto || 0);
                    }, 0);
                const saldo = Math.max(0, aNumero(c.monto_total) - abonoTotal);

                const monturaHtml = c.montura_id
                    ? (c.codigo_montura || '—') + ' · ' + (c.montura_marca || '—') + ' · ' + (c.montura_descripcion || '—')
                    : 'Montura propia del cliente';

                const html =
                    '<div class="contrato-imprimir-membrete">' +
                        '<strong>Óptica Bellavista</strong>' +
                        '<span>Contrato de Venta y Servicio de Óptica</span>' +
                    '</div>' +
                    '<table class="tabla"><tbody>' +
                        filaDetalle('N.º de Contrato', c.numero_contrato) +
                        filaDetalle('Fecha de registro', c.creado_en) +
                        filaDetalle('Cliente', c.paciente_nombre) +
                        filaDetalle('Celular', c.paciente_celular) +
                        filaDetalle('Montura', monturaHtml) +
                        filaDetalle('Detalle del trabajo', c.detalle_contrato) +
                        filaDetalle('Fecha estimada de entrega', c.fecha_entrega) +
                        filaDetalle('Comentarios', c.comentarios) +
                        filaDetalle('Estado', c.estado) +
                    '</tbody></table>' +
                    '<div class="contrato-totales">' +
                        '<p><span>Monto Total:</span><strong>' + moneda(c.monto_total) + '</strong></p>' +
                        '<p><span>Abono Inicial:</span><strong>' + moneda(abonoTotal) + '</strong></p>' +
                        '<p><span>Saldo Pendiente:</span><strong class="saldo-valor">' + moneda(saldo) + '</strong></p>' +
                    '</div>';

                document.getElementById('contrato-ver-contenido').innerHTML = html;

                const overlay = document.getElementById('modal-contrato-ver');
                overlay.hidden = false;
                document.body.classList.add('modal-abierto');
                overlay.querySelector('.modal-close').focus();
            })
            .catch(function (error) {
                window.App.notificar(error.message || 'No se pudo cargar el contrato.', 'error');
            });
    }

    function cerrarModal(modal) {
        modal.hidden = true;
        document.body.classList.remove('modal-abierto');
    }

    /* ============================================================
       Edición de campos textuales del contrato
       ============================================================ */
    function abrirEdicionContrato(id) {
        API.get('api/contratos.php?id=' + Math.floor(Number(id)))
            .then(function (respuesta) {
                if (respuesta.status !== 'success') {
                    throw new Error(respuesta.message || 'No se pudo cargar el contrato.');
                }

                const c = respuesta.data;
                contratoEditandoId = Number(c.id);

                document.getElementById('ce-detalle').value =
                    c.detalle_contrato === null || c.detalle_contrato === undefined ? '' : c.detalle_contrato;
                document.getElementById('ce-fecha').value =
                    c.fecha_entrega ? String(c.fecha_entrega).slice(0, 10) : '';
                document.getElementById('ce-comentarios').value =
                    c.comentarios === null || c.comentarios === undefined ? '' : c.comentarios;

                document.getElementById('modal-contrato-editar').hidden = false;
                document.body.classList.add('modal-abierto');
                document.getElementById('ce-detalle').focus();
            })
            .catch(function (error) {
                window.App.notificar(error.message || 'No se pudo cargar el contrato.', 'error');
            });
    }

    async function guardarEdicionContrato(event) {
        event.preventDefault();

        if (guardando || contratoEditandoId === null) {
            return;
        }

        const formulario = event.target;
        const boton = formulario.querySelector('button[type="submit"]');

        const datos = {
            id: contratoEditandoId,
            detalle_contrato: document.getElementById('ce-detalle').value.trim(),
            fecha_entrega: document.getElementById('ce-fecha').value,
            comentarios: document.getElementById('ce-comentarios').value.trim()
        };

        guardando = true;
        boton.disabled = true;

        try {
            const respuesta = await API.put('api/contratos.php', datos);

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo actualizar el contrato.');
            }

            window.App.notificar('Contrato actualizado correctamente.', 'ok');
            cerrarModal(document.getElementById('modal-contrato-editar'));
            contratoEditandoId = null;
            cargarContratos(busquedaActual);
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo actualizar el contrato.', 'error');
        } finally {
            guardando = false;
            boton.disabled = false;
        }
    }

    /* ============================================================
       Anulación de contrato (restituye stock y elimina caja)
       ============================================================ */
    async function anularContrato(id) {
        const confirmado = window.confirm(
            '¿Anular este contrato?\n' +
            'Se eliminará el contrato, se restituirá el stock de la montura y se anularán sus movimientos de caja.'
        );

        if (!confirmado) {
            return;
        }

        try {
            const respuesta = await API.delete('api/contratos.php?id=' + Math.floor(Number(id)));

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo anular el contrato.');
            }

            window.App.notificar(respuesta.message || 'Contrato anulado correctamente.', 'ok');
            cargarContratos(busquedaActual);
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo anular el contrato.', 'error');
        }
    }

    return { render: render };
})();