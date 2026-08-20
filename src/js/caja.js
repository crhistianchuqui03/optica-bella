/**
 * Módulo de Cuaderno de Ventas (Caja): consolida ingresos, egresos,
 * liquidación de saldos y cálculo de ganancia neta por fecha.
 */
window.Modulos = window.Modulos || {};

Modulos.caja = (function () {
    let contenedor = null;
    let fechaActual = null;
    let debounceHistorico = null;
    let debounceContrato = null;
    let guardando = false;
    let inicializado = false;
    let egresoContratoSeleccionado = null;
    let contratosEgresoCache = [];

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
    function render(container) {
        contenedor = container;
        fechaActual = hoyIso();

        contenedor.innerHTML =
            '<h2 class="vista-titulo">Cuaderno de Ventas (Caja)</h2>' +

            '<div class="caja-filtros">' +
                '<div class="form-group">' +
                    '<label for="caja-fecha">Fecha del Balance</label>' +
                    '<div class="caja-fecha-nav">' +
                        '<input id="caja-fecha" class="form-control" type="date" value="' + fechaActual + '">' +
                        '<div class="caja-fecha-nav-botones">' +
                            '<button id="btn-dia-anterior" class="btn btn-ghost btn-sm" type="button" ' +
                                'title="Día anterior" aria-label="Día anterior">' +
                                '<span aria-hidden="true">◀</span> Día Anterior' +
                            '</button>' +
                            '<button id="btn-dia-siguiente" class="btn btn-ghost btn-sm" type="button" ' +
                                'title="Día siguiente" aria-label="Día siguiente">' +
                                'Día Siguiente <span aria-hidden="true">▶</span>' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="buscador">' +
                    '<svg class="buscador-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
                        '<circle cx="11" cy="11" r="7"></circle>' +
                        '<path d="m20 20-3.5-3.5"></path>' +
                    '</svg>' +
                    '<input id="caja-buscar-hist" class="form-control" type="search" ' +
                        'placeholder="Buscador histórico (cliente, contrato o boleta lab)..." autocomplete="off">' +
                '</div>' +
            '</div>' +

            '<div class="kpi-grid">' +
                '<div class="kpi-card kpi-card--ingresos">' +
                    '<span class="kpi-label">Total Ingresos del Día</span>' +
                    '<span class="kpi-valor kpi-valor--ingresos" id="kpi-ingresos">S/ 0.00</span>' +
                '</div>' +
                '<div class="kpi-card kpi-card--egresos">' +
                    '<span class="kpi-label">Total Egresos del Día</span>' +
                    '<span class="kpi-valor kpi-valor--egresos" id="kpi-egresos">S/ 0.00</span>' +
                '</div>' +
                '<div class="kpi-card kpi-card--ganancia">' +
                    '<span class="kpi-label">Ganancia Neta del Día</span>' +
                    '<span class="kpi-valor kpi-valor--ganancia" id="kpi-ganancia">S/ 0.00</span>' +
                '</div>' +
            '</div>' +

            '<div class="panel">' +
                '<h3>Sección A - Ingresos del Día</h3>' +
                '<div id="tabla-ingresos-slot">Cargando...</div>' +
            '</div>' +

            '<div class="panel">' +
                '<div class="modulo-cabecera caja-cabecera-egresos">' +
                    '<h3>Sección B - Egresos del Día</h3>' +
                    '<button id="btn-nuevo-egreso" class="btn btn-primary" type="button">' +
                        '<span aria-hidden="true">+</span> Registrar Egreso / Gastos Lab' +
                    '</button>' +
                '</div>' +
                '<div id="tabla-egresos-slot">Cargando...</div>' +
            '</div>' +

            '<div class="panel" id="panel-historico" hidden>' +
                '<h3>Resultados del Buscador Histórico</h3>' +
                '<div id="tabla-historico-slot"></div>' +
            '</div>';

        crearModales();
        inicializar();

        document.getElementById('caja-fecha').addEventListener('change', function () {
            fechaActual = this.value || hoyIso();
            refrescarBotonSiguiente();
            buscarHistorico('');
            cargarFecha();
        });

        document.getElementById('btn-dia-anterior').addEventListener('click', function () {
            ajustarFecha(-1);
        });

        document.getElementById('btn-dia-siguiente').addEventListener('click', function () {
            ajustarFecha(1);
        });

        refrescarBotonSiguiente();

        document.getElementById('caja-buscar-hist').addEventListener('input', function () {
            window.clearTimeout(debounceHistorico);
            debounceHistorico = window.setTimeout(function () {
                buscarHistorico(this.value);
            }.bind(this), 300);
        });

        document.getElementById('tabla-ingresos-slot').addEventListener('click', function (event) {
            const boton = event.target.closest('[data-abonar]');
            if (boton) {
                abrirAbonoSaldo(boton.dataset);
            }
        });

        document.getElementById('btn-nuevo-egreso').addEventListener('click', abrirNuevoEgreso);

        cargarFecha();
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

        document.getElementById('form-abono').addEventListener('submit', registrarAbono);
        document.getElementById('form-egreso').addEventListener('submit', registrarEgreso);

        document.getElementById('eg-buscar-contrato').addEventListener('input', function () {
            window.clearTimeout(debounceContrato);
            debounceContrato = window.setTimeout(function () {
                buscarContratosEgreso(this.value);
            }.bind(this), 300);
        });

        document.getElementById('eg-picker-lista').addEventListener('click', function (event) {
            const item = event.target.closest('[data-id]');
            if (item) {
                seleccionarContratoEgreso(Number(item.dataset.id));
            }
        });

        document.getElementById('eg-contrato-limpiar').addEventListener('click', limpiarContratoEgreso);
    }

    /* ============================================================
       Navegación por fechas
       ============================================================ */
    function sumarDias(fechaIso, dias) {
        const partes = fechaIso.split('-').map(Number);
        const fecha = new Date(partes[0], partes[1] - 1, partes[2]);
        fecha.setDate(fecha.getDate() + dias);
        const mes = String(fecha.getMonth() + 1).padStart(2, '0');
        const dia = String(fecha.getDate()).padStart(2, '0');
        return fecha.getFullYear() + '-' + mes + '-' + dia;
    }

    function refrescarBotonSiguiente() {
        const boton = document.getElementById('btn-dia-siguiente');
        if (boton) {
            boton.disabled = fechaActual >= hoyIso();
        }
    }

    function ajustarFecha(dias) {
        fechaActual = sumarDias(fechaActual, dias);
        document.getElementById('caja-fecha').value = fechaActual;
        refrescarBotonSiguiente();
        buscarHistorico('');
        cargarFecha();
    }

    /* ============================================================
       Carga por fecha y KPIs
       ============================================================ */
    async function cargarFecha() {
        try {
            const respuesta = await API.get('api/caja.php?fecha=' + encodeURIComponent(fechaActual));

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo cargar el balance.');
            }

            const datos = respuesta.data;

            document.getElementById('kpi-ingresos').textContent = moneda(datos.total_ingresos);
            document.getElementById('kpi-egresos').textContent = moneda(datos.total_egresos);
            document.getElementById('kpi-ganancia').textContent = moneda(datos.ganancia_neta);

            renderIngresos(datos.ingresos || []);
            renderEgresos(datos.egresos || []);
        } catch (error) {
            document.getElementById('tabla-ingresos-slot').innerHTML =
                '<p class="sin-datos">' + escapar(error.message || 'Error al cargar.') + '</p>';
        }
    }

    /* ============================================================
       Tabla de Ingresos
       ============================================================ */
    function renderIngresos(lista) {
        const slot = document.getElementById('tabla-ingresos-slot');

        if (!lista.length) {
            slot.innerHTML = '<p class="sin-datos">Sin ingresos registrados en la fecha seleccionada.</p>';
            return;
        }

        let html =
            '<table class="tabla">' +
                '<thead><tr>' +
                    '<th>Fecha</th>' +
                    '<th>N.º Contrato</th>' +
                    '<th>Paciente</th>' +
                    '<th>Género</th>' +
                    '<th>Código Montura</th>' +
                    '<th>Descripción de la Venta</th>' +
                    '<th>Monto Total</th>' +
                    '<th>Abonado Hoy</th>' +
                    '<th>Saldo</th>' +
                    '<th>Estado</th>' +
                    '<th>Acciones</th>' +
                '</tr></thead><tbody>';

        lista.forEach(function (r) {
            const montoTotal = aNumero(r.monto_total_contrato);
            const saldo = aNumero(r.saldo_pendiente_contrato);
            const abonadoHoy = aNumero(r.monto_abonado_hoy);
            const cancelado = r.estado_contrato === 'CANCELADO';
            const tipoAbono = r.tipo_abono === 'INICIAL' ? 'Abono Inicial' : 'Abono a Saldo';
            const descripcion = r.numero_contrato
                ? 'Contrato #' + r.numero_contrato + ' (' + tipoAbono + ')'
                : '—';
            const saldoHtml = cancelado ? '<span class="saldo-tachado">' + moneda(saldo) + '</span>' : moneda(saldo);
            const estadoHtml = cancelado
                ? '<span class="badge badge-cancelado">CANCELADO</span>'
                : '<span class="badge badge-pendiente">PENDIENTE</span>';

            let accionesHtml = '';
            if (!cancelado && saldo > 0.005) {
                accionesHtml =
                    '<button type="button" class="btn btn-primary btn-sm" data-abonar data-contrato-id="' +
                        r.contrato_id + '" data-saldo="' + saldo.toFixed(2) + '">+ Abonar Saldo</button>';
            }

            html +=
                '<tr>' +
                    '<td>' + escapar(r.fecha_registro) + '</td>' +
                    '<td><strong>' + escapar(r.numero_contrato || '—') + '</strong></td>' +
                    '<td>' + escapar(r.paciente_nombre || '—') + '</td>' +
                    '<td>' + (r.genero_paciente ? r.genero_paciente.charAt(0) + r.genero_paciente.slice(1).toLowerCase() : '—') + '</td>' +
                    '<td>' + escapar(r.codigo_montura || 'Propia') + '</td>' +
                    '<td>' + descripcion + '</td>' +
                    '<td>' + moneda(montoTotal) + '</td>' +
                    '<td>' + moneda(abonadoHoy) + '</td>' +
                    '<td>' + saldoHtml + '</td>' +
                    '<td>' + estadoHtml + '</td>' +
                    '<td class="celda-acciones">' + accionesHtml + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        slot.innerHTML = html;
    }

    /* ============================================================
       Tabla de Egresos
       ============================================================ */
    function renderEgresos(lista) {
        const slot = document.getElementById('tabla-egresos-slot');

        if (!lista.length) {
            slot.innerHTML = '<p class="sin-datos">Sin egresos registrados en la fecha seleccionada.</p>';
            return;
        }

        let html =
            '<table class="tabla">' +
                '<thead><tr>' +
                    '<th>Fecha</th>' +
                    '<th>Concepto / Gastos</th>' +
                    '<th>Descripción</th>' +
                    '<th>Paciente</th>' +
                    '<th>N.º Boleta/Factura Lab</th>' +
                    '<th>N.º Contrato Asociado</th>' +
                '</tr></thead><tbody>';

        lista.forEach(function (r) {
            html +=
                '<tr>' +
                    '<td>' + escapar(r.fecha_registro) + '</td>' +
                    '<td>' + escapar(r.concepto_descripcion || '—') + '</td>' +
                    '<td class="monto-egreso">' + moneda(r.monto) + '</td>' +
                    '<td>' + escapar(r.paciente_nombre || '—') + '</td>' +
                    '<td>' + escapar(r.nro_comprobante_lab || '—') + '</td>' +
                    '<td>' + escapar(r.numero_contrato || '—') + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        slot.innerHTML = html;
    }

    /* ============================================================
       Buscador histórico
       ============================================================ */
    async function buscarHistorico(termino) {
        const limpio = termino.trim();
        const panel = document.getElementById('panel-historico');

        if (limpio === '') {
            panel.hidden = true;
            return;
        }

        panel.hidden = false;

        try {
            const respuesta = await API.get('api/caja.php?q=' + encodeURIComponent(limpio));
            const lista = respuesta.status === 'success' ? (respuesta.data || []) : [];
            const slot = document.getElementById('tabla-historico-slot');

            if (!lista.length) {
                slot.innerHTML = '<p class="sin-datos">Sin movimientos que coincidan con la búsqueda.</p>';
                return;
            }

            let html =
                '<table class="tabla">' +
                    '<thead><tr>' +
                        '<th>Fecha</th>' +
                        '<th>Tipo</th>' +
                        '<th>Concepto</th>' +
                        '<th>Monto</th>' +
                        '<th>Contrato</th>' +
                        '<th>Paciente</th>' +
                        '<th>N.º Comprobante Lab</th>' +
                    '</tr></thead><tbody>';

            lista.forEach(function (r) {
                const esIngreso = r.tipo_movimiento === 'INGRESO';
                const badge = esIngreso
                    ? '<span class="badge badge-ingreso">INGRESO</span>'
                    : '<span class="badge badge-egreso">EGRESO</span>';
                const monto = esIngreso ? 'monto-ingreso' : 'monto-egreso';

                html +=
                    '<tr>' +
                        '<td>' + escapar(r.fecha_registro) + '</td>' +
                        '<td>' + badge + '</td>' +
                        '<td>' + escapar(r.concepto_descripcion || '—') + '</td>' +
                        '<td class="' + monto + '">' + moneda(r.monto) + '</td>' +
                        '<td>' + escapar(r.numero_contrato || '—') + '</td>' +
                        '<td>' + escapar(r.paciente_nombre || '—') + '</td>' +
                        '<td>' + escapar(r.nro_comprobante_lab || '—') + '</td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            slot.innerHTML = html;
        } catch (error) {
            document.getElementById('tabla-historico-slot').innerHTML =
                '<p class="sin-datos">' + escapar(error.message || 'Error en la búsqueda.') + '</p>';
        }
    }

    /* ============================================================
       Modal: abonar saldo
       ============================================================ */
    function abrirAbonoSaldo(datos) {
        const saldo = aNumero(datos.saldo);
        const campoContrato = document.getElementById('abono-contrato-id');

        campoContrato.value = datos.contratoId;
        campoContrato.dataset.saldo = saldo.toFixed(2);
        document.getElementById('abono-saldo-info').textContent = 'Saldo pendiente: ' + moneda(saldo);
        document.getElementById('abono-monto').value = '';
        document.getElementById('abono-comentarios').value = '';
        document.getElementById('abono-monto').max = String(saldo);

        abrirModal('modal-abono', 'abono-monto');
    }

    async function registrarAbono(event) {
        event.preventDefault();

        if (guardando) {
            return;
        }

        const contratoId = document.getElementById('abono-contrato-id').value;
        const monto = aNumero(document.getElementById('abono-monto').value);
        const saldo = aNumero(document.getElementById('abono-contrato-id').dataset.saldo);

        if (!(monto > 0)) {
            window.App.notificar('Ingrese un monto válido para el abono.', 'error');
            return;
        }

        if (monto > saldo + 0.001) {
            window.App.notificar('El abono no puede exceder el saldo pendiente.', 'error');
            return;
        }

        const boton = document.getElementById('btn-guardar-abono');
        guardando = true;
        boton.disabled = true;

        try {
            const respuesta = await API.post('api/caja.php', {
                accion: 'abono_saldo',
                contrato_id: Number(contratoId),
                monto_abono: String(monto),
                comentarios: document.getElementById('abono-comentarios').value.trim()
            });

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo registrar el abono.');
            }

            window.App.notificar('Abono registrado correctamente.', 'ok');
            cerrarModal(document.getElementById('modal-abono'));
            cargarFecha();
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo registrar el abono.', 'error');
        } finally {
            guardando = false;
            boton.disabled = false;
        }
    }

    /* ============================================================
       Modal: registrar egreso / gastos lab
       ============================================================ */
    function abrirNuevoEgreso() {
        const formulario = document.getElementById('form-egreso');
        formulario.reset();
        limpiarContratoEgreso();
        abrirModal('modal-egreso', 'eg-concepto');
    }

    async function buscarContratosEgreso(termino) {
        const limpio = termino.trim();
        const slot = document.getElementById('eg-picker-lista');

        if (limpio === '') {
            slot.innerHTML = '';
            return;
        }

        try {
            const respuesta = await API.get('api/contratos.php?q=' + encodeURIComponent(limpio));
            const lista = respuesta.status === 'success' ? (respuesta.data || []) : [];

            contratosEgresoCache = lista;

            if (!lista.length) {
                slot.innerHTML = '<p class="sin-datos">Sin contratos que coincidan.</p>';
                return;
            }

            let html = '<div class="picker-resultados" role="listbox">';
            lista.forEach(function (c) {
                html +=
                    '<button type="button" class="picker-item" data-id="' + c.id + '" role="option" tabindex="0">' +
                        '<strong>#' + escapar(c.numero_contrato) + '</strong>' +
                        '<span>' + escapar(c.paciente_nombre || '—') + ' · Monto: ' + moneda(c.monto_total) + '</span>' +
                    '</button>';
            });
            html += '</div>';
            slot.innerHTML = html;
        } catch (error) {
            slot.innerHTML = '<p class="sin-datos">' + escapar(error.message || 'Error al buscar.') + '</p>';
        }
    }

    function seleccionarContratoEgreso(id) {
        const contrato = contratosEgresoCache.find(function (c) {
            return Number(c.id) === id;
        });
        if (!contrato) {
            return;
        }

        egresoContratoSeleccionado = contrato;

        document.getElementById('eg-picker-lista').innerHTML = '';
        document.getElementById('eg-buscar-contrato').value = '';
        document.getElementById('eg-contrato-seleccionado').hidden = false;
        document.getElementById('eg-contrato-seleccionado').textContent =
            'Contrato #' + contrato.numero_contrato + ' - ' + (contrato.paciente_nombre || 'Paciente sin registrar');
        document.getElementById('eg-contrato-limpiar').hidden = false;
    }

    function limpiarContratoEgreso() {
        egresoContratoSeleccionado = null;

        document.getElementById('eg-picker-lista').innerHTML = '';
        document.getElementById('eg-buscar-contrato').value = '';
        document.getElementById('eg-contrato-seleccionado').hidden = true;
        document.getElementById('eg-contrato-limpiar').hidden = true;
    }

    async function registrarEgreso(event) {
        event.preventDefault();

        if (guardando) {
            return;
        }

        const concepto = document.getElementById('eg-concepto').value.trim();
        const monto = aNumero(document.getElementById('eg-monto').value);
        const nroComprobante = document.getElementById('eg-comprobante').value.trim();

        if (!concepto) {
            window.App.notificar('El concepto es obligatorio.', 'error');
            return;
        }

        if (!(monto > 0)) {
            window.App.notificar('Ingrese un monto válido.', 'error');
            return;
        }

        const contratoId = egresoContratoSeleccionado ? String(egresoContratoSeleccionado.id) : null;

        const boton = document.getElementById('btn-guardar-egreso');
        guardando = true;
        boton.disabled = true;

        try {
            const respuesta = await API.post('api/caja.php', {
                accion: 'registrar_egreso',
                concepto_descripcion: concepto,
                monto: String(monto),
                nro_comprobante_lab: nroComprobante,
                contrato_id: contratoId
            });

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo registrar el egreso.');
            }

            window.App.notificar('Egreso registrado correctamente.', 'ok');
            cerrarModal(document.getElementById('modal-egreso'));
            cargarFecha();
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo registrar el egreso.', 'error');
        } finally {
            guardando = false;
            boton.disabled = false;
        }
    }

    /* ============================================================
       Modales
       ============================================================ */
    function crearModales() {
        if (document.getElementById('modal-abono')) {
            return;
        }

        const overlayAbono = document.createElement('div');
        overlayAbono.className = 'modal-overlay';
        overlayAbono.id = 'modal-abono';
        overlayAbono.hidden = true;
        overlayAbono.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="titulo-abono">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-abono">Abonar Saldo</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<form id="form-abono" novalidate>' +
                    '<div class="modal-body">' +
                        '<input type="hidden" id="abono-contrato-id">' +
                        '<p id="abono-saldo-info" class="contrato-ayuda"></p>' +
                        '<div class="form-group">' +
                            '<label for="abono-monto">Monto a Abonar (S/) *</label>' +
                            '<input id="abono-monto" class="form-control" type="number" min="0.01" step="0.01" ' +
                                'inputmode="decimal" required>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="abono-comentarios">Comentarios (opcional)</label>' +
                            '<input id="abono-comentarios" class="form-control" placeholder="Nota del abono..." autocomplete="off">' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cancelar</button>' +
                        '<button type="submit" id="btn-guardar-abono" class="btn btn-primary">Registrar Abono</button>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(overlayAbono);

        const overlayEgreso = document.createElement('div');
        overlayEgreso.className = 'modal-overlay';
        overlayEgreso.id = 'modal-egreso';
        overlayEgreso.hidden = true;
        overlayEgreso.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="titulo-egreso">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-egreso">Registrar Egreso / Gastos Lab</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<form id="form-egreso" novalidate>' +
                    '<div class="modal-body">' +
                        '<div class="form-group">' +
                            '<label for="eg-concepto">Concepto / Descripción *</label>' +
                            '<input id="eg-concepto" name="concepto_descripcion" class="form-control" required ' +
                                'placeholder="Ej: Lunentes de laboratorio, pulido, biselado..." autocomplete="off">' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="eg-monto">Monto (S/) *</label>' +
                            '<input id="eg-monto" class="form-control" type="number" min="0.01" step="0.01" ' +
                                'inputmode="decimal" required>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="eg-comprobante">N.º Boleta/Factura Lab (opcional)</label>' +
                            '<input id="eg-comprobante" class="form-control" placeholder="Ej: L-00123" autocomplete="off">' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="eg-buscar-contrato">Contrato Asociado (opcional)</label>' +
                            '<div class="buscador">' +
                                '<svg class="buscador-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
                                    '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>' +
                                '</svg>' +
                                '<input id="eg-buscar-contrato" class="form-control" type="search" ' +
                                    'placeholder="Buscar por paciente o N.º de contrato..." autocomplete="off">' +
                            '</div>' +
                            '<div id="eg-picker-lista" class="picker-lista"></div>' +
                            '<p id="eg-contrato-seleccionado" class="contrato-ayuda" hidden></p>' +
                            '<button id="eg-contrato-limpiar" class="btn btn-ghost btn-sm" type="button" hidden>' +
                                'Quitar contrato asociado' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cancelar</button>' +
                        '<button type="submit" id="btn-guardar-egreso" class="btn btn-primary">Guardar Egreso</button>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(overlayEgreso);
    }

    function abrirModal(id, enfoque) {
        const modal = document.getElementById(id);
        if (!modal) {
            return;
        }
        modal.hidden = false;
        document.body.classList.add('modal-abierto');
        const campo = document.getElementById(enfoque);
        if (campo) {
            campo.focus();
        }
    }

    function cerrarModal(modal) {
        modal.hidden = true;
        document.body.classList.remove('modal-abierto');
    }

    return { render: render };
})();