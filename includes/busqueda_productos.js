/**
 * busqueda_productos.js — utilidades compartidas para los buscadores de productos
 * (Nueva venta, Ventas a domicilio, Compras, Entradas, Salidas, Transferencias, Paquetes).
 *
 * 1) Relevancia: antes cada buscador solo filtraba con "contiene" sobre código o nombre y se
 *    quedaba con los primeros N en el orden del catálogo, así que buscar "1" mostraba productos
 *    cuyo NOMBRE contenía un 1 y dejaba fuera (o hasta el final) al producto cuyo CÓDIGO es
 *    exactamente "1". Ahora se ordena por qué tan bien coincide:
 *        0  el código es exactamente lo escrito
 *        1  el código empieza con lo escrito
 *        2  el nombre empieza con lo escrito
 *        3  el código contiene lo escrito
 *        4  el nombre contiene lo escrito
 *    A igual relevancia se conserva el orden original de la lista (el sort es estable).
 *
 * 2) Captura rápida "cantidad*código": 4*1 = 4 piezas del producto con código 1.
 *
 * Todo se compara sin mayúsculas ni acentos (igual que el resto de los buscadores).
 */
(function () {
    function norm(s) {
        return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }

    // Puntaje de coincidencia (menor = mejor). q ya debe venir normalizado. Infinity = no coincide.
    function puntaje(codigo, nombre, q) {
        var c = norm(codigo), n = norm(nombre);
        if (q === '') return 5;
        if (c === q) return 0;
        if (c.indexOf(q) === 0) return 1;
        if (n.indexOf(q) === 0) return 2;
        if (c.indexOf(q) !== -1) return 3;
        if (n.indexOf(q) !== -1) return 4;
        return Infinity;
    }

    // Filtra y ordena por relevancia. fnCodigo / fnNombre extraen los campos de cada elemento.
    function ordenar(lista, q, fnCodigo, fnNombre) {
        var qn = norm(q);
        var puntuados = [];
        for (var i = 0; i < lista.length; i++) {
            var p = puntaje(fnCodigo(lista[i]), fnNombre(lista[i]), qn);
            if (p !== Infinity) puntuados.push({ item: lista[i], p: p, i: i });
        }
        puntuados.sort(function (a, b) { return a.p - b.p || a.i - b.i; });
        return puntuados.map(function (x) { return x.item; });
    }

    // Primer elemento cuyo código es EXACTAMENTE el texto (sin mayúsculas ni acentos), o null.
    function buscarExacto(lista, codigo, fnCodigo) {
        var qn = norm(codigo);
        if (qn === '') return null;
        for (var i = 0; i < lista.length; i++) {
            if (norm(fnCodigo(lista[i])) === qn) return lista[i];
        }
        return null;
    }

    // "4*1" -> { cantidad: 4, codigo: '1' }. Acepta espacios y coma decimal ("2,5 * 7"). null si no aplica.
    function parsearCantidadPorCodigo(texto) {
        var m = /^\s*(\d+(?:[.,]\d+)?)\s*\*\s*(\S.*?)\s*$/.exec(String(texto == null ? '' : texto));
        if (!m) return null;
        var cantidad = parseFloat(m[1].replace(',', '.'));
        if (!(cantidad > 0) || !isFinite(cantidad)) return null;
        return { cantidad: cantidad, codigo: m[2] };
    }

    // ¿Hay un resultado resaltado con las flechas? (dropdown_keynav.js lo marca con box-shadow inline)
    function hayResaltado(drop) {
        if (!drop) return false;
        for (var i = 0; i < drop.children.length; i++) {
            if (drop.children[i].style && drop.children[i].style.boxShadow) return true;
        }
        return false;
    }

    // Resuelve lo escrito como captura rapida. buscarFn(codigo) devuelve el elemento con ESE codigo exacto
    // (o null). Devuelve:
    //   null                              -> no es captura rapida (se sigue con el comportamiento normal)
    //   { noExiste: 'cod' }               -> era "cantidad*codigo" pero ese codigo no existe
    //   { item, cantidad, explicita }     -> el texto completo es un codigo (cantidad 1, explicita=false)
    //                                        o "cantidad*codigo" (explicita=true)
    // El texto completo manda: si hay un producto con codigo "4*1" literal, no se interpreta como cantidad.
    function resolverRapido(texto, buscarFn) {
        var hit = buscarFn(texto);
        if (hit) return { item: hit, cantidad: 1, explicita: false };
        var r = parsearCantidadPorCodigo(texto);
        if (!r) return null;
        hit = buscarFn(r.codigo);
        if (!hit) return { noExiste: r.codigo };
        return { item: hit, cantidad: r.cantidad, explicita: true };
    }

    // Aviso flotante no bloqueante (para las pantallas que no tienen su propio aviso temporal).
    function aviso(texto, esError, ms) {
        var d = document.createElement('div');
        d.setAttribute('role', 'status');
        d.textContent = texto;
        d.style.cssText = 'position:fixed;top:16px;right:16px;z-index:100000;max-width:340px;padding:10px 16px;' +
            'border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 14px rgba(0,0,0,.2);' +
            (esError ? 'background:#fdecea;color:#c0392b;border-left:4px solid #c0392b;'
                     : 'background:#e8f5e9;color:#2e7d32;border-left:4px solid #2e7d32;');
        document.body.appendChild(d);
        setTimeout(function () { if (d.parentNode) d.parentNode.removeChild(d); }, ms || 3000);
    }

    // Renglon informativo para el dropdown cuando se escribe "4*codigo" (sin onclick: dropdown_keynav lo ignora).
    function htmlAvisoCantidad(cantidad, verbo) {
        return '<div style="padding:7px 12px;background:#e8f5e9;color:#2e7d32;font-size:12px;border-bottom:1px solid #c8e6c9;">' +
            (verbo || 'Se precargarán') + ' <strong>' + cantidad + '</strong> al elegir (o presiona Enter con el código exacto)</div>';
    }

    window.BusquedaProductos = {
        resolverRapido: resolverRapido,
        aviso: aviso,
        htmlAvisoCantidad: htmlAvisoCantidad,
        norm: norm,
        puntaje: puntaje,
        ordenar: ordenar,
        buscarExacto: buscarExacto,
        parsearCantidadPorCodigo: parsearCantidadPorCodigo,
        hayResaltado: hayResaltado
    };
})();
