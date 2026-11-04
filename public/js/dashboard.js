/**
 * Panel de inicio: KPIs de ingreso, egreso y ganancia neta del día,
 * más resumen con las últimas 5 transacciones registradas hoy.
 */
window.Modulos = window.Modulos || {};

Modulos.dashboard = (function () {
    function formatearMoneda(valor) {
        const numero = Number(valor);
        if (Number.isNaN(numero)) {
            return 'S/ 0.00';
        }
        return 'S/ ' + numero.toFixed(2);
    }

    function escapar(texto) {
        const div = document.createElement('div');
        div.textContent = String(texto === null || texto === undefined ? '' : texto);
        return div.innerHTML;
    }

    function asignarKpi(id, valor, sufijo) {
        const elemento = document.getElementById(id);
        if (!elemento || valor === null || valor === undefined) {
            return;
        }
        elemento.textContent = formatearMoneda(valor) + (sufijo || '');
    }

    function construirTransacciones(datos) {
        const transacciones = [];

        (datos.ingresos || []).forEach(function (ingreso) {
            transacciones.push({
                tipo: 'INGRESO',
                detalle: 'Abono contrato ' + escapar(ingreso.numero_contrato || '—'),
                monto: ingreso.monto_abonado_hoy
            });
        });

        (datos.egresos || []).forEach(function (egreso) {
            transacciones.push({
                tipo: 'EGRESO',
                detalle: escapar(egreso.concepto_descripcion) +
                    (egreso.nro_comprobante_lab ? ' (' + escapar(egreso.nro_comprobante_lab) + ')' : ''),
                monto: egreso.monto
            });
        });

        return transacciones.reverse().slice(0, 5);
    }

    function renderUltimasTransacciones(datos, contenedor) {
        const slot = document.getElementById('ultimas-transacciones');
        const transacciones = construirTransacciones(datos);

        if (transacciones.length === 0) {
            slot.innerHTML = '<p class="sin-datos">No hay transacciones registradas hoy.</p>';
            return;
        }

        let html =
            '<table class="tabla">' +
                '<thead><tr><th>Tipo</th><th>Detalle</th><th>Monto</th></tr></thead>' +
                '<tbody>';

        transacciones.forEach(function (t) {
            const signo = t.tipo === 'INGRESO' ? '+' : '-';
            html +=
                '<tr>' +
                    '<td><span class="badge badge-' + t.tipo.toLowerCase() + '">' + t.tipo + '</span></td>' +
                    '<td>' + t.detalle + '</td>' +
                    '<td class="monto-' + t.tipo.toLowerCase() + '">' + signo + ' ' + formatearMoneda(t.monto) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        slot.innerHTML = html;
    }

    async function render(contenedor) {
        contenedor.innerHTML =
            '<h2 class="vista-titulo">Panel de Inicio</h2>' +
            '<div class="kpi-grid">' +
                '<div class="kpi-card kpi-card--ingresos">' +
                    '<span class="kpi-label">Total Ingresos del Día</span>' +
                    '<span class="kpi-valor kpi-valor--ingresos" id="kpi-ingresos">&mdash;</span>' +
                '</div>' +
                '<div class="kpi-card kpi-card--egresos">' +
                    '<span class="kpi-label">Total Egresos del Día</span>' +
                    '<span class="kpi-valor kpi-valor--egresos" id="kpi-egresos">&mdash;</span>' +
                '</div>' +
                '<div class="kpi-card kpi-card--ganancia">' +
                    '<span class="kpi-label">Ganancia Neta del Día</span>' +
                    '<span class="kpi-valor kpi-valor--ganancia" id="kpi-ganancia">&mdash;</span>' +
                '</div>' +
            '</div>' +
            '<div class="panel">' +
                '<h3>Resumen Rápido de Caja &mdash; Últimas transacciones de hoy</h3>' +
                '<div id="ultimas-transacciones">Cargando...</div>' +
            '</div>';

        try {
            const respuesta = await API.get('api/caja.php');

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo obtener la información de caja.');
            }

            const datos = respuesta.data || {};

            asignarKpi('kpi-ingresos', datos.total_ingresos);
            asignarKpi('kpi-egresos', datos.total_egresos);
            asignarKpi('kpi-ganancia', datos.ganancia_neta);
            renderUltimasTransacciones(datos);
        } catch (error) {
            const slot = document.getElementById('ultimas-transacciones');
            if (slot) {
                slot.innerHTML =
                    '<p class="sin-datos">' + escapar(error.message || 'Error al cargar los indicadores.') + '</p>';
            }
        }
    }

    return { render: render };
})();