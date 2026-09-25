/*
 * Motor de práctica. El estado del intento vive en localStorage (vía GP de progreso.js);
 * el servidor solo entrega el HTML de las preguntas y corrige las respuestas, sin guardar nada.
 */
(function () {
  'use strict';

  var app, M, P, d, s, a, tipos = {}, htmlPreg = {}, htmlRev = {}, ocupado = false, reloj = null;
  var GP = window.GP;
  var esc = GP.esc;

  document.addEventListener('DOMContentLoaded', iniciar);

  function iniciar() {
    app = document.getElementById('app');
    M = JSON.parse(document.getElementById('datos-set').textContent);
    P = new URLSearchParams(location.search);
    M.preguntas.forEach(function (p) { tipos[p.id] = p; });

    d = GP.cargar();
    s = GP.set(d, M.id, M.version, M.titulo, M.tema_id);
    GP.guardar(d);

    if (P.has('revisar')) {
      var intento = s.intentos.filter(function (i) { return String(i.id) === P.get('revisar'); })[0];
      if (!intento) return mensaje('No se encontró ese intento en este navegador.');
      return resultado(intento, []);
    }
    if (P.get('continuar') && s.actual) {
      a = s.actual;
    } else if (P.get('continuar')) {
      return mensaje('No tienes un intento pendiente en este set.');
    } else {
      a = nuevoIntento();
      if (!a) return mensaje('No hay preguntas que cumplan esos filtros. Prueba otra combinación.');
      s.actual = a;
      GP.guardar(d);
      // Si se recarga la página, se continúa este intento en vez de crear otro
      history.replaceState(null, '', '?id=' + M.id + '&continuar=1');
    }
    cargarPreguntas().then(pintar).catch(errorRed);
  }

  function calificable(id) {
    return tipos[id] && tipos[id].tipo !== 'essay' && tipos[id].tipo !== 'description';
  }

  function mezclar(arr) {
    for (var i = arr.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = arr[i]; arr[i] = arr[j]; arr[j] = t;
    }
    return arr;
  }

  function nuevoIntento() {
    var fuente = P.get('fuente') || 'todas';
    var modo = P.get('modo') === 'examen' ? 'examen' : 'practica';
    var cat = P.get('cat') || '';
    var n = parseInt(P.get('n') || '0', 10) || 0;
    var min = modo === 'examen' ? (parseInt(P.get('min') || '0', 10) || 0) : 0;
    var lista = M.preguntas.slice();

    if (cat) lista = lista.filter(function (p) { return p.cat === cat; });
    if (fuente === 'errores') {
      lista = lista.filter(function (p) { return calificable(p.id) && s.q[p.id] && s.q[p.id][0] < 0.999; });
    } else if (fuente === 'nuevas') {
      lista = lista.filter(function (p) { return calificable(p.id) && !s.q[p.id]; });
    } else if (fuente === 'intento') {
      var origen = s.intentos.filter(function (i) { return String(i.id) === P.get('origen'); })[0];
      var malas = {};
      if (origen && origen.items) origen.items.forEach(function (it) { if (calificable(it.id) && !(it.f >= 0.999)) malas[it.id] = 1; });
      lista = lista.filter(function (p) { return malas[p.id]; });
    }
    if ((fuente === 'errores' || fuente === 'intento') && modo === 'practica') modo = 'errores';

    var ids = lista.map(function (p) { return p.id; });
    if (M.mezclar || n) {
      // Al mezclar se omiten las "descripciones": fuera de su contexto no aportan
      ids = ids.filter(function (id) { return tipos[id].tipo !== 'description'; });
      var orden = ids.slice();
      mezclar(ids);
      if (n) ids = ids.slice(0, n);
      if (!M.mezclar) { var sel = {}; ids.forEach(function (i) { sel[i] = 1; }); ids = orden.filter(function (i) { return sel[i]; }); }
    }
    if (!ids.length) return null;
    return {
      id: Date.now(), modo: modo, ids: ids, idx: 0,
      semilla: 1 + Math.floor(Math.random() * 1e9),
      inicio: Date.now(), limite: min * 60, items: {}
    };
  }

  // ------------------------------------------------------------------ red

  function api(datos) {
    return fetch(M.urls.api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ set: M.id }, datos)),
      credentials: 'same-origin'
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  function cargarPreguntas() {
    return api({ accion: 'preguntas', ids: a.ids, semilla: a.semilla }).then(function (r) {
      htmlPreg = r.html || {};
      // Si el docente borró preguntas mientras tanto, se descartan
      a.ids = a.ids.filter(function (id) { return htmlPreg[id] !== undefined; });
      // En práctica, la pregunta actual puede estar ya respondida (recarga): pedir su corrección
      var qid = a.ids[a.idx];
      if (qid && a.items[qid] && a.modo !== 'examen') {
        return api({ accion: 'revisar', semilla: a.semilla, items: [{ id: qid, r: a.items[qid].r }] }).then(function (rv) {
          if (rv.items[qid]) htmlRev[qid] = rv.items[qid].html;
        });
      }
    });
  }

  function errorRed(e) {
    ocupado = false;
    console.error(e);
    GP.toast('No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.', 'error');
  }

  function mensaje(txt) {
    app.innerHTML = '<div class="tarjeta"><p>' + esc(txt) + '</p><a class="btn" href="' + esc(M.urls.set) + '">Volver al set</a></div>';
  }

  function persistir() {
    s.actual = a;
    GP.guardar(d);
  }

  // ------------------------------------------------------------------ pintar pregunta

  function pintar() {
    if (a.idx >= a.ids.length) return terminar(false);
    ocupado = false;
    var qid = a.ids[a.idx];
    var meta = tipos[qid];
    var feedback = a.modo !== 'examen';
    var item = a.items[qid];
    var respondida = !!item && feedback;
    var n = a.ids.length;
    var ultimo = a.idx + 1 >= n;

    var bien = 0, calif = 0;
    Object.keys(a.items).forEach(function (k) { var f = a.items[k].f; if (f !== null && f !== undefined) { calif++; bien += f; } });

    var botones;
    if (meta.tipo === 'description' || respondida) {
      botones = '<button class="btn grande" value="siguiente" data-atajo>' + (ultimo ? 'Ver resultado' : 'Siguiente →') + '</button>';
    } else {
      botones = '<button class="btn grande" value="responder" data-atajo>' + (feedback ? 'Comprobar' : (ultimo ? 'Responder y terminar' : 'Responder →')) + '</button>' +
        (feedback ? '<button class="btn secundario" value="nose" formnovalidate>No sé / ver respuesta</button>'
                  : '<button class="btn secundario" value="saltar" formnovalidate>Saltar</button>');
    }
    var claseCard = respondida ? 'respondida ' + GP.clase(item.f) : '';

    app.innerHTML =
      '<div class="intento-cab"><div><strong>' + esc(M.titulo) + '</strong> <span class="chip suave">' + esc(GP.nombreModo(a.modo)) + '</span></div>' +
      '<div class="derecha">' + (feedback && calif ? '<span class="small muted">Aciertos: ' + (Math.round(bien * 10) / 10) + '/' + calif + '</span>' : '') +
      (a.limite ? '<span class="reloj">--:--</span>' : '') + '</div></div>' +
      GP.barra((a.idx + (respondida ? 1 : 0)) / n) +
      '<p class="small muted">Pregunta ' + (a.idx + 1) + ' de ' + n + ' · ' + esc(meta.etq) + (meta.cat ? ' · ' + esc(meta.cat) : '') + '</p>' +
      '<form class="tarjeta pregunta ' + claseCard + '" id="form-pregunta" autocomplete="off" novalidate>' +
      (respondida ? (htmlRev[qid] || '') : htmlPreg[qid]) +
      '<div class="acciones-preg">' + botones +
      '<button class="btn link" value="terminar" formnovalidate>Terminar</button></div></form>' +
      '<p class="small muted centro atajos">Atajos: teclas <kbd>1</kbd>–<kbd>9</kbd> eligen alternativa · <kbd>Enter</kbd> comprueba / avanza</p>';

    var form = document.getElementById('form-pregunta');
    form.addEventListener('submit', enviar);
    var foco = form.querySelector('input:not([type=hidden]):not(:disabled), textarea:not(:disabled), select:not(:disabled)');
    if (foco && (foco.type === 'text' || foco.tagName === 'TEXTAREA')) foco.focus();
    else { var b = form.querySelector('[data-atajo]'); if (b) b.focus({ preventScroll: true }); }
    iniciarReloj();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /** Convierte el formulario en la respuesta: "x" | ["1","2"] | {"0":"2","1":"0"} | null */
  function serializar(form) {
    var r = null;
    new FormData(form).forEach(function (v, k) {
      if (k === 'r') r = v;
      else if (k === 'r[]') { r = Array.isArray(r) ? r : []; r.push(v); }
      else {
        var m = k.match(/^r\[(\d+)\]$/);
        if (m) { r = (r && typeof r === 'object' && !Array.isArray(r)) ? r : {}; r[m[1]] = v; }
      }
    });
    return r;
  }

  function vacia(r) {
    if (r === null || r === undefined) return true;
    if (typeof r === 'string') return r.trim() === '';
    if (Array.isArray(r)) return r.length === 0;
    return Object.keys(r).some(function (k) { return r[k] === ''; });
  }

  function enviar(e) {
    e.preventDefault();
    if (ocupado) return;
    var form = e.target;
    var accion = (e.submitter && e.submitter.value) || (form.querySelector('[data-atajo]') || {}).value;
    var qid = a.ids[a.idx];

    if (accion === 'terminar') {
      if (window.confirm('¿Terminar ahora? Las preguntas que falten cuentan como incorrectas.')) terminar(false);
      return;
    }
    if (accion === 'siguiente' || accion === 'saltar') {
      a.idx++;
      persistir();
      return pintar();
    }
    var r = null;
    if (accion === 'responder') {
      r = serializar(form);
      if (vacia(r)) {
        GP.toast(tipos[qid].tipo === 'matching' ? 'Completa todas las parejas.' : 'Primero elige o escribe una respuesta.', 'aviso');
        return;
      }
    }
    ocupado = true;
    api({ accion: 'calificar', id: qid, semilla: a.semilla, r: r }).then(function (res) {
      a.items[qid] = { r: r, f: res.fraccion };
      htmlRev[qid] = res.html;
      GP.registrarRespuesta(d, M.id, qid, res.fraccion);
      if (a.modo === 'examen') a.idx++;
      persistir();
      pintar();
    }).catch(errorRed);
  }

  // ------------------------------------------------------------------ reloj (examen)

  function iniciarReloj() {
    if (reloj) clearInterval(reloj);
    if (!a.limite) return;
    var el = app.querySelector('.reloj');
    var tick = function () {
      var resta = Math.max(0, Math.round((a.inicio + a.limite * 1000 - Date.now()) / 1000));
      if (el) {
        el.textContent = Math.floor(resta / 60) + ':' + String(resta % 60).padStart(2, '0');
        el.classList.toggle('urgente', resta <= 60);
      }
      if (resta <= 0) {
        clearInterval(reloj);
        GP.toast('Se acabó el tiempo.', 'aviso');
        terminar(true);
      }
    };
    reloj = setInterval(tick, 1000);
    tick();
  }

  // ------------------------------------------------------------------ fin del intento

  function terminar() {
    if (reloj) clearInterval(reloj);
    var puntaje = 0, maximo = 0;
    a.ids.forEach(function (id) {
      if (!calificable(id)) return;
      maximo++;
      var it = a.items[id];
      if (it && it.f !== null && it.f !== undefined) puntaje += it.f;
    });
    var intento = {
      id: a.id, modo: a.modo, inicio: a.inicio, fin: Date.now(), limite: a.limite,
      puntaje: Math.round(puntaje * 1000) / 1000, maximo: maximo, semilla: a.semilla,
      items: a.ids.filter(function (id) { return tipos[id].tipo !== 'description'; }).map(function (id) {
        var it = a.items[id];
        return { id: id, r: it ? it.r : null, f: it ? it.f : null };
      })
    };
    var nuevos = GP.registrarIntento(d, M.id, intento, M.preguntas.filter(function (p) { return calificable(p.id); }).map(function (p) { return p.id; }));
    GP.guardar(d);
    history.replaceState(null, '', '?id=' + M.id + '&revisar=' + intento.id);
    resultado(intento, nuevos);
  }

  function resultado(intento, nuevos) {
    var frac = intento.maximo ? intento.puntaje / intento.maximo : null;
    var malas = (intento.items || []).filter(function (it) { return calificable(it.id) && !(it.f >= 0.999); }).length;
    var sinResp = (intento.items || []).filter(function (it) { return calificable(it.id) && it.r === null; }).length;
    var dur = Math.max(1, Math.round((intento.fin - intento.inicio) / 60000));
    var msj = frac === null ? '' : frac >= 0.9 ? '¡Excelente! Dominas este contenido.' : frac >= 0.7 ? 'Buen trabajo. Repasa los errores para afianzar.'
      : frac >= 0.5 ? 'Vas bien, pero conviene repasar.' : 'Sigue practicando: revisa las explicaciones de cada pregunta.';

    app.classList.remove('intento');
    app.innerHTML =
      '<section class="tarjeta resultado"><div class="resultado-cab">' +
      '<div class="anillo ' + GP.clase(frac) + '" style="--p:' + (frac === null ? 0 : Math.round(frac * 100)) + '"><span>' + (frac === null ? '—' : Math.round(frac * 100) + '%') + '</span></div>' +
      '<div><h1>' + esc(M.titulo) + '</h1>' +
      '<p class="muted">' + esc(GP.nombreModo(intento.modo)) + ' · ' + esc(GP.fecha(intento.fin)) + ' · ' + dur + ' min</p>' +
      '<p><strong>' + (Math.round(intento.puntaje * 100) / 100) + '</strong> de ' + intento.maximo + ' puntos' +
      (frac !== null ? ' · nota referencial <strong>' + GP.nota(frac) + '</strong>' : '') +
      (sinResp ? ' · ' + sinResp + ' sin responder' : '') + '</p>' +
      (msj ? '<p class="mensaje">' + msj + '</p>' : '') + '</div></div>' +
      (nuevos.length ? '<div class="logros nuevos"><h3>🎉 ¡Nuevo' + (nuevos.length > 1 ? 's logros' : ' logro') + '!</h3>' +
        nuevos.map(function (l) { return GP.logroHtml(l, Date.now()); }).join('') + '</div>' : '') +
      '<div class="fila">' +
      (malas && intento.items ? '<a class="btn" href="?id=' + M.id + '&fuente=intento&origen=' + intento.id + '">Repetir las ' + malas + ' que fallé</a>' : '') +
      '<a class="btn secundario" href="' + esc(M.urls.set) + '">Nuevo intento</a>' +
      '<a class="btn secundario" href="' + esc(M.urls.tema) + '">Otros sets</a>' +
      '<a class="btn secundario" href="' + esc(M.urls.progreso) + '">Mi progreso</a></div></section>' +
      '<div class="fila entre"><h2>Revisión</h2><label class="check small"><input type="checkbox" id="solo-malas"> Ver solo las incorrectas</label></div>' +
      '<div id="revision"><p class="muted">Cargando corrección…</p></div>';

    nuevos.forEach(function (l, i) { setTimeout(function () { GP.toast(l.icono + ' Logro desbloqueado: <strong>' + esc(l.nombre) + '</strong>', 'logro', true); }, 400 * i); });

    var cont = document.getElementById('revision');
    if (!intento.items) { cont.innerHTML = '<p class="muted">El detalle de este intento ya no está guardado (solo se conservan los últimos).</p>'; return; }
    var items = intento.items.filter(function (it) { return tipos[it.id]; });
    api({ accion: 'revisar', semilla: intento.semilla, items: items }).then(function (r) {
      cont.innerHTML = items.map(function (it, n) {
        var rv = r.items[it.id];
        if (!rv) return '';
        var meta = tipos[it.id];
        return '<article class="tarjeta pregunta respondida ' + GP.clase(rv.fraccion) + '" data-ok="' + (rv.fraccion === null || rv.fraccion >= 0.999 ? 1 : 0) + '">' +
          '<div class="meta"><span>#' + (n + 1) + '</span><span class="chip">' + esc(meta.etq) + '</span>' + (meta.cat ? '<span class="chip suave">' + esc(meta.cat) + '</span>' : '') + '</div>' +
          rv.html + '</article>';
      }).join('');
    }).catch(function (e) { cont.innerHTML = '<p class="flash error">No se pudo cargar la corrección.</p>'; console.error(e); });

    document.getElementById('solo-malas').addEventListener('change', function () {
      var solo = this.checked;
      cont.querySelectorAll('article').forEach(function (el) { el.hidden = solo && el.getAttribute('data-ok') === '1'; });
    });
  }

  // ------------------------------------------------------------------ atajos de teclado

  document.addEventListener('keydown', function (e) {
    var form = document.getElementById('form-pregunta');
    if (!form || e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target;
    if (t.matches && t.matches('textarea, select, input[type=text], input:not([type])')) return;
    if (/^[1-9]$/.test(e.key)) {
      var op = form.querySelectorAll('.opciones input:not(:disabled)')[parseInt(e.key, 10) - 1];
      if (op) {
        op.checked = op.type === 'checkbox' ? !op.checked : true;
        op.focus();
        e.preventDefault();
      }
    } else if (e.key === 'Enter') {
      var b = form.querySelector('[data-atajo]');
      if (b) { e.preventDefault(); b.click(); }
    }
  });
})();
