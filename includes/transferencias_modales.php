<?php
// Ventanas compartidas de Transferencias (admin/inventario_transferencias.php y cajeroInventario/transferencias.php):
//   - Formulario de RECEPCION: por producto, cantidad recibida / merma / no enviado (recibido + merma + no enviado = enviado).
//   - DETALLE / historial de un pedido (folio, quien, cuando, cantidades recibidas, observaciones, bitacora).
// La pagina que lo incluye define antes: $transfPagina (nombre del archivo, p. ej. 'transferencias.php').
?>
<style>
    .tf-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9998; align-items: center; justify-content: center; padding: 14px; }
    .tf-modal { background: #fff; border-radius: 10px; padding: 20px 22px; width: 780px; max-width: 100%; max-height: 92vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,.3); color: #333; font-size: 13px; }
    .tf-modal-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 8px; }
    .tf-titulo { font-size: 15px; font-weight: 700; color: #1d2733; }
    .tf-folio { display: inline-block; margin-left: 6px; padding: 2px 10px; border-radius: 99px; background: #e3f2fd; color: #1565c0; font-size: 12px; font-weight: 700; vertical-align: 1px; }
    .tf-x { background: none; border: none; font-size: 24px; line-height: 1; color: #9aa5b1; cursor: pointer; padding: 0 4px; }
    .tf-x:hover { color: #333; }
    .tf-sub { font-size: 12px; color: #6b7785; margin-bottom: 10px; line-height: 1.5; }
    .tf-aviso { font-size: 12px; color: #5d4a00; background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 9px 12px; margin-bottom: 12px; line-height: 1.5; }
    .tf-campo { margin-top: 12px; }
    .tf-campo label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 4px; }
    .tf-campo textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; resize: vertical; }
    .tf-campo textarea:focus { outline: none; border-color: #14ace7; }
    .tf-botones { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 16px; padding-top: 14px; border-top: 1px solid #eee; }
    .tf-btn { border: none; border-radius: 6px; padding: 10px 16px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .tf-btn-ok { background: #1f9d55; color: #fff; }
    .tf-btn-ok:hover { background: #17824a; }
    .tf-btn-ok:disabled { background: #b9c3cc; cursor: not-allowed; }
    .tf-btn-sec { background: #f0f2f4; color: #555; font-weight: 600; }
    .tf-btn-sec:hover { background: #e4e8ec; }
    .tf-btn-azul { background: #14ace7; color: #fff; }
    .tf-btn-azul:hover { background: #0e93c9; }

    /* Formulario de recepcion */
    .rcp-fila { display: grid; grid-template-columns: minmax(0, 1fr) 86px 96px 96px 96px; gap: 8px; align-items: center; padding: 9px 0; border-bottom: 1px solid #f0f0f0; }
    .rcp-cab { font-size: 10.5px; font-weight: 700; color: #8a96a3; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #ddd; padding-bottom: 6px; }
    .rcp-cab div:not(:first-child) { text-align: right; }
    .rcp-prod strong { display: block; font-size: 13px; color: #1d2733; }
    .rcp-cod { font-size: 10.5px; color: #9aa5b1; }
    .rcp-env { text-align: right; font-weight: 700; color: #1565c0; font-variant-numeric: tabular-nums; }
    .rcp-env small { font-weight: 400; color: #8a96a3; }
    .rcp-in { width: 100%; padding: 7px 8px; border: 1px solid #cfd6dd; border-radius: 6px; font-size: 14px; text-align: right; font-family: inherit; font-variant-numeric: tabular-nums; }
    .rcp-in:focus { outline: none; border-color: #14ace7; }
    .rcp-mal .rcp-in { border-color: #e6a5a0; background: #fff7f6; }
    .rcp-estado { grid-column: 1 / -1; font-size: 11.5px; color: #c0392b; font-weight: 600; text-align: right; display: none; }
    .rcp-mal .rcp-estado { display: block; }
    .rcp-resumen { margin-top: 12px; font-size: 12px; color: #4a5560; background: #f6f8fa; border-radius: 6px; padding: 9px 12px; line-height: 1.6; }
    .rcp-resumen b { color: #1d2733; }

    /* Detalle del pedido */
    .det-fila-estado { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
    .det-tipo { font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 99px; background: #fff3e0; color: #c25b00; }
    .det-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 18px; margin-bottom: 14px; }
    .det-grid label { display: block; font-size: 10.5px; font-weight: 700; color: #9aa5b1; text-transform: uppercase; letter-spacing: .04em; }
    .det-grid span { font-size: 13px; color: #1d2733; }
    .det-tabla { width: 100%; min-width: 440px; border-collapse: collapse; margin-bottom: 12px; }
    .det-tabla th { font-size: 10.5px; padding: 7px 8px; background: #f9f9f9; }
    .det-tabla td { font-size: 12.5px; padding: 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
    .det-tabla .n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .det-tabla th.n { text-align: right; }
    .det-tabla .merma { color: #c25b00; font-weight: 700; }
    .det-tabla .noenv { color: #c0392b; font-weight: 700; }
    .det-tabla tfoot td { font-weight: 700; background: #fafbfc; border-bottom: none; }
    .det-obs { background: #f3f8fc; border-left: 3px solid #14ace7; border-radius: 4px; padding: 9px 12px; margin-bottom: 12px; font-size: 12.5px; white-space: pre-wrap; word-break: break-word; }
    .det-obs b { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: #6b7785; margin-bottom: 2px; }
    .det-hist h4 { font-size: 11px; font-weight: 700; color: #6b7785; text-transform: uppercase; letter-spacing: .04em; margin: 4px 0 6px; }
    .det-hist ul { list-style: none; margin: 0; padding: 0; }
    .det-hist li { font-size: 12px; color: #444; padding: 4px 0 4px 12px; border-left: 2px solid #d5dde5; margin-bottom: 4px; line-height: 1.45; word-break: break-word; }
    .tf-cargando { padding: 28px; text-align: center; color: #9aa5b1; }
    @media (max-width: 768px) {
        .tf-modal { padding: 16px 14px; }
        .rcp-cab { display: none; }
        .rcp-fila { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .rcp-prod { grid-column: 1 / -1; }
        .rcp-env { grid-column: 1 / -1; text-align: left; }
        .rcp-env::before { content: 'Enviado: '; font-weight: 400; color: #8a96a3; }
        .rcp-fila [data-et]::before { content: attr(data-et); display: block; font-size: 10px; font-weight: 700; color: #8a96a3; text-transform: uppercase; margin-bottom: 2px; }
        .rcp-in { font-size: 16px; }
        .det-grid { grid-template-columns: 1fr; }
        .det-tabla { font-size: 11.5px; }
    }
</style>

<!-- Confirmar la recepcion de un pedido: se captura lo que de verdad llego -->
<div id="modalRecepcion" class="tf-overlay" aria-hidden="true">
    <form id="formRecibirPedido" class="tf-modal" method="POST" action="<?= htmlspecialchars($transfPagina) ?>" onsubmit="return confirmarRecepcionPedido()">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="recibir_pedido">
        <input type="hidden" name="id" id="recPedidoId">
        <div class="tf-modal-head">
            <span class="tf-titulo">Confirmar recepción <span class="tf-folio" id="recFolio"></span></span>
            <button type="button" class="tf-x" onclick="cerrarModalRecepcion()" aria-label="Cerrar">×</button>
        </div>
        <div class="tf-sub" id="recSub"></div>
        <div class="tf-aviso">
            Captura lo que realmente llegó de cada producto. <strong>Recibido + Merma + No enviado</strong> debe ser igual a lo <strong>Enviado</strong>.
            La <strong>merma</strong> entra a tu stock (después la das de baja desde <em>Salidas y mermas</em>); lo <strong>no enviado</strong> regresa al stock de la sucursal origen.
        </div>
        <div>
            <div class="rcp-fila rcp-cab"><div>Producto</div><div>Enviado</div><div>Recibido</div><div>Merma</div><div>No enviado</div></div>
            <div id="recLista"></div>
        </div>
        <div class="rcp-resumen" id="recResumen"></div>
        <div class="tf-campo">
            <label for="recObs">Observaciones (opcional)</label>
            <textarea id="recObs" name="observaciones" rows="2" maxlength="1000" placeholder="Ej. Una caja llegó golpeada; faltó el pedido de tornillos..."></textarea>
        </div>
        <div class="tf-botones">
            <button type="button" class="tf-btn tf-btn-sec" onclick="recepcionCompleta()">Todo llegó completo</button>
            <span style="flex:1;"></span>
            <button type="button" class="tf-btn tf-btn-sec" onclick="cerrarModalRecepcion()">Cancelar</button>
            <button type="submit" class="tf-btn tf-btn-ok" id="recBtnConfirmar">Confirmar recepción</button>
        </div>
    </form>
</div>

<!-- Detalle / historial de un pedido -->
<div id="modalDetallePedido" class="tf-overlay" aria-hidden="true">
    <div class="tf-modal">
        <div class="tf-modal-head">
            <span class="tf-titulo">Detalle del pedido <span class="tf-folio" id="detFolio"></span></span>
            <button type="button" class="tf-x" onclick="cerrarDetallePedido()" aria-label="Cerrar">×</button>
        </div>
        <div id="detCuerpo"><div class="tf-cargando">Cargando…</div></div>
        <div class="tf-botones">
            <button type="button" class="tf-btn tf-btn-azul" id="detBtnTicket" style="display:none;" onclick="verTicketDesdeDetalle()">Ver ticket</button>
            <span style="flex:1;"></span>
            <button type="button" class="tf-btn tf-btn-sec" onclick="cerrarDetallePedido()">Cerrar</button>
        </div>
    </div>
</div>

<script>
const TRANSF_PAGINA = <?= json_encode($transfPagina) ?>;
function _tfEsc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function _tfFmt(n, suelto) {
    const v = parseFloat(n) || 0;
    if (!suelto && Number.isInteger(v)) return String(v);
    return String(parseFloat(v.toFixed(3)));
}
function _tfR3(n) { return Math.round(n * 1000) / 1000; }

// ── Formulario de recepcion ──────────────────────────────────────────────────────────────────
function abrirModalRecepcion(btn) {
    let filas = [];
    try { filas = JSON.parse(btn.getAttribute('data-filas') || '[]'); } catch (e) { filas = []; }
    if (!filas.length) return;
    document.getElementById('recPedidoId').value = btn.getAttribute('data-id');
    document.getElementById('recFolio').textContent = btn.getAttribute('data-folio') ? 'Folio ' + btn.getAttribute('data-folio') : '';
    document.getElementById('recSub').textContent = 'Enviado por ' + (btn.getAttribute('data-origen') || 'la sucursal origen') + ' · ' + filas.length + ' producto' + (filas.length === 1 ? '' : 's') + '.';
    document.getElementById('recObs').value = '';
    const lista = document.getElementById('recLista');
    lista.innerHTML = filas.map(f => {
        const st = f.suelto ? '0.001' : '1';
        const mode = f.suelto ? 'decimal' : 'numeric';
        const campo = (cls, nom, val, et) => `<div data-et="${et}"><input type="number" class="rcp-in ${cls}" name="${nom}[${f.id}]" value="${_tfFmt(val, f.suelto)}" min="0" max="${f.cantidad}" step="${st}" inputmode="${mode}" autocomplete="off"></div>`;
        return `<div class="rcp-fila" data-env="${f.cantidad}" data-suelto="${f.suelto ? 1 : 0}" data-nombre="${_tfEsc(f.nombre)}">
            <div class="rcp-prod"><strong>${_tfEsc(f.nombre)}</strong><span class="rcp-cod">${_tfEsc(f.codigo)}</span></div>
            <div class="rcp-env">${_tfFmt(f.cantidad, f.suelto)} <small>${_tfEsc(f.unidad || '')}</small></div>
            ${campo('rcp-rec', 'rec', f.cantidad, 'Recibido')}
            ${campo('rcp-mer', 'mer', 0, 'Merma')}
            ${campo('rcp-noe', 'noe', 0, 'No enviado')}
            <div class="rcp-estado"></div>
        </div>`;
    }).join('');
    const m = document.getElementById('modalRecepcion');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
    evaluarRecepcion();
    const primero = lista.querySelector('.rcp-rec');
    if (primero) setTimeout(() => { primero.focus(); primero.select(); }, 80);
}

function cerrarModalRecepcion() {
    const m = document.getElementById('modalRecepcion');
    m.style.display = 'none';
    m.setAttribute('aria-hidden', 'true');
}

function recepcionCompleta() {
    document.querySelectorAll('#recLista .rcp-fila').forEach(fila => {
        const suelto = fila.dataset.suelto === '1';
        fila.querySelector('.rcp-rec').value = _tfFmt(fila.dataset.env, suelto);
        fila.querySelector('.rcp-mer').value = '0';
        fila.querySelector('.rcp-noe').value = '0';
        delete fila.dataset.noeManual;
    });
    evaluarRecepcion();
}

// Evalua una fila: devuelve true si recibido + merma + no enviado == enviado (y los numeros son validos).
function _evaluarFilaRecepcion(fila) {
    const suelto = fila.dataset.suelto === '1';
    const env = parseFloat(fila.dataset.env) || 0;
    const leer = sel => { const v = fila.querySelector(sel).value.trim(); return v === '' ? NaN : Number(v); };
    const r = leer('.rcp-rec'), m = leer('.rcp-mer'), n = leer('.rcp-noe');
    const est = fila.querySelector('.rcp-estado');
    let msg = '';
    if ([r, m, n].some(x => !isFinite(x) || x < 0)) {
        msg = 'Captura una cantidad válida (0 o mayor) en las tres columnas.';
    } else if (!suelto && [r, m, n].some(x => Math.abs(x - Math.round(x)) > 0.0005)) {
        msg = 'Este producto no es granel: usa números enteros.';
    } else {
        const diff = _tfR3(env - (r + m + n));
        if (diff > 0.0005) msg = 'Faltan ' + _tfFmt(diff, suelto) + ' por ubicar: súmalos a Recibido, Merma o No enviado.';
        else if (diff < -0.0005) msg = 'Sobran ' + _tfFmt(-diff, suelto) + ': Recibido + Merma + No enviado no puede pasar de lo Enviado.';
    }
    est.textContent = msg;
    fila.classList.toggle('rcp-mal', msg !== '');
    return msg === '';
}

function evaluarRecepcion() {
    let todoBien = true, tRec = 0, tMer = 0, tNoe = 0;
    document.querySelectorAll('#recLista .rcp-fila').forEach(fila => {
        if (!_evaluarFilaRecepcion(fila)) todoBien = false;
        tRec += parseFloat(fila.querySelector('.rcp-rec').value) || 0;
        tMer += parseFloat(fila.querySelector('.rcp-mer').value) || 0;
        tNoe += parseFloat(fila.querySelector('.rcp-noe').value) || 0;
    });
    const res = document.getElementById('recResumen');
    let h = 'Resumen: recibido <b>' + _tfFmt(tRec, true) + '</b> · merma <b>' + _tfFmt(tMer, true) + '</b> · no enviado <b>' + _tfFmt(tNoe, true) + '</b> (suma de unidades).';
    if (tMer > 0) h += '<br>La merma entra a tu stock; recuerda darla de baja desde <b>Salidas y mermas</b>.';
    if (tNoe > 0) h += '<br>Lo no enviado regresa al stock de la sucursal origen.';
    res.innerHTML = h;
    document.getElementById('recBtnConfirmar').disabled = !todoBien;
    return todoBien;
}

document.addEventListener('input', function (e) {
    const fila = e.target.closest ? e.target.closest('#recLista .rcp-fila') : null;
    if (!fila) return;
    if (e.target.classList.contains('rcp-noe')) {
        fila.dataset.noeManual = '1';              // lo capturo la persona: ya no se recalcula solo
    } else if (!fila.dataset.noeManual) {
        // Al cambiar Recibido o Merma, "No enviado" se ajusta solo con lo que falta (nunca menos de 0).
        const env = parseFloat(fila.dataset.env) || 0;
        const r = parseFloat(fila.querySelector('.rcp-rec').value) || 0;
        const m = parseFloat(fila.querySelector('.rcp-mer').value) || 0;
        fila.querySelector('.rcp-noe').value = _tfFmt(Math.max(0, _tfR3(env - r - m)), fila.dataset.suelto === '1');
    }
    evaluarRecepcion();
});

function confirmarRecepcionPedido() {
    if (!evaluarRecepcion()) { alert('Revisa las cantidades marcadas en rojo antes de confirmar.'); return false; }
    return confirm('¿Confirmar la recepción? El stock de tu sucursal (y el de la sucursal origen si algo no llegó) se actualizará de inmediato y ya no se podrá cambiar.');
}

// ── Detalle / historial ──────────────────────────────────────────────────────────────────────
let _detPedidoId = 0;
function abrirDetallePedido(id) {
    _detPedidoId = id;
    document.getElementById('detFolio').textContent = '';
    document.getElementById('detCuerpo').innerHTML = '<div class="tf-cargando">Cargando…</div>';
    document.getElementById('detBtnTicket').style.display = 'none';
    const m = document.getElementById('modalDetallePedido');
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
    fetch(TRANSF_PAGINA + '?detalle_pedido=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (!d || d.error) { document.getElementById('detCuerpo').innerHTML = '<div class="tf-cargando">' + _tfEsc((d && d.error) || 'No se encontró el pedido o no tienes acceso a él.') + '</div>'; return; }
            renderDetallePedido(d);
        })
        .catch(() => { document.getElementById('detCuerpo').innerHTML = '<div class="tf-cargando">No se pudo cargar el detalle. Intenta de nuevo.</div>'; });
}

function renderDetallePedido(d) {
    document.getElementById('detFolio').textContent = d.folio ? 'Folio ' + d.folio + (d.periodo ? ' · ' + d.periodo : '') : '#' + d.id;
    const directo = d.tipo === 'Directo';
    const badgeEst = { 'Pendiente':'#e3f2fd;color:#1565c0', 'Aprobada':'#e8f5e9;color:#2e7d32', 'Modificada':'#e8eaf6;color:#283593', 'En tránsito':'#fff8e1;color:#e65100', 'Entregada':'#f0f0f0;color:#666', 'Rechazada':'#fdecea;color:#c0392b', 'Cancelada':'#fdecea;color:#c0392b' };
    const dato = (et, v) => v ? `<div><label>${et}</label><span>${_tfEsc(v)}</span></div>` : '';
    const etq = (directo && d.estado === 'Aprobada') ? 'En preparación' : d.estado;   // envio directo que aun no sale
    let h = `<div class="det-fila-estado"><span style="display:inline-block;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700;background:${badgeEst[d.estado] || '#eee'};">${_tfEsc(etq)}</span>`
          + (directo ? '<span class="det-tipo">Envío directo</span>' : '<span class="det-tipo" style="background:#e3f2fd;color:#1565c0;">Solicitud</span>') + '</div>';
    h += '<div class="det-grid">'
       + dato('Origen', d.origen) + dato('Destino', d.destino)
       + dato(directo ? 'Preparó el envío' : 'Pidió', (d.solicito || '—') + ' · ' + d.creado)
       + (directo ? '' : dato('Aceptó', d.autorizo))
       + dato('Enviado', d.fecha_envio ? ((d.envio ? d.envio + ' · ' : '') + d.fecha_envio) : '')
       + dato('Recepción confirmada', d.fecha_recepcion ? ((d.recibio ? d.recibio + ' · ' : '') + d.fecha_recepcion) : '')
       + '</div>';

    const hayCaptura = d.items.some(i => i.recibida !== null);
    h += '<div style="overflow-x:auto;"><table class="det-tabla"><thead><tr><th>Producto</th><th class="n">Enviado</th>'
       + '<th class="n">Recibido</th><th class="n">Merma</th><th class="n">No enviado</th><th>Estado</th></tr></thead><tbody>';
    d.items.forEach(i => {
        const f = x => x === null ? '<span style="color:#b4bcc5;">—</span>' : _tfFmt(x, true);
        h += `<tr><td>${_tfEsc(i.nombre)}<div style="font-size:10.5px;color:#9aa5b1;">${_tfEsc(i.codigo)}</div></td>`
           + `<td class="n"><strong>${_tfFmt(i.cantidad, true)}</strong> <small style="color:#8a96a3;">${_tfEsc(i.unidad || '')}</small></td>`
           + `<td class="n">${f(i.recibida)}</td>`
           + `<td class="n ${i.merma > 0 ? 'merma' : ''}">${f(i.merma)}</td>`
           + `<td class="n ${i.no_enviada > 0 ? 'noenv' : ''}">${f(i.no_enviada)}</td>`
           + `<td>${_tfEsc(directo && i.estado === 'Aprobada' ? 'En preparación' : i.estado)}</td></tr>`;
    });
    h += '</tbody>';
    if (hayCaptura) {
        const t = d.totales;
        h += `<tfoot><tr><td>Total</td><td class="n">${_tfFmt(t.enviado, true)}</td><td class="n">${_tfFmt(t.recibida, true)}</td><td class="n">${_tfFmt(t.merma, true)}</td><td class="n">${_tfFmt(t.no_enviada, true)}</td><td></td></tr></tfoot>`;
    }
    h += '</table></div>';
    if (hayCaptura && d.totales.merma > 0) h += '<div class="tf-sub">La merma ya cuenta en el stock de la sucursal destino; ella la da de baja desde Salidas y mermas.</div>';
    if (d.observaciones) h += `<div class="det-obs"><b>Observaciones de la recepción</b>${_tfEsc(d.observaciones)}</div>`;
    if (d.historial && d.historial.length) {
        h += '<div class="det-hist"><h4>Historial</h4><ul>' + d.historial.map(l => `<li>${_tfEsc(l)}</li>`).join('') + '</ul></div>';
    }
    document.getElementById('detCuerpo').innerHTML = h;
    document.getElementById('detBtnTicket').style.display = d.hay_envio ? '' : 'none';
}

function cerrarDetallePedido() {
    const m = document.getElementById('modalDetallePedido');
    m.style.display = 'none';
    m.setAttribute('aria-hidden', 'true');
}

function verTicketDesdeDetalle() {
    if (typeof abrirTicketEnvio === 'function' && _detPedidoId) abrirTicketEnvio(_detPedidoId);
}

['modalRecepcion', 'modalDetallePedido'].forEach(function (id) {
    document.getElementById(id).addEventListener('mousedown', function (e) {
        if (e.target !== this) return;
        if (id === 'modalDetallePedido') cerrarDetallePedido();   // el de recepcion NO se cierra por clic fuera (se perderia lo capturado)
    });
});
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('modalDetallePedido').style.display === 'flex') cerrarDetallePedido();
});
</script>
