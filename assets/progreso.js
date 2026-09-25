/*
 * Progreso del estudiante guardado SOLO en el navegador (localStorage).
 * El servidor no guarda nada del estudiante.
 *
 * Estructura:
 * {
 *   version: 1,
 *   sets: { [setId]: { v, titulo, tema, q: { [preguntaId]: [fraccion, ts] }, intentos: [...], actual: {...}|null } },
 *   dias: ["2026-09-25", ...],   // días con práctica (racha)
 *   logros: { [clave]: ts },
 *   total: n.º de respuestas, bien: n.º de correctas
 * }
 */
(function () {
  'use strict';

  var CLAVE = 'giftprac.v1';
  var MAX_INTENTOS = 30;      // intentos guardados por set
  var MAX_CON_DETALLE = 10;   // de esos, cuántos guardan respuestas para revisarlos

  function vacio() { return { version: 1, sets: {}, dias: [], logros: {}, total: 0, bien: 0 }; }

  function cargar() {
    try {
      var d = JSON.parse(localStorage.getItem(CLAVE));
      if (valido(d)) return normalizar(d);
    } catch (e) { /* sin acceso o JSON dañado */ }
    return vacio();
  }

  function valido(d) { return !!d && d.version === 1 && !!d.sets && typeof d.sets === 'object'; }

  /** Completa campos faltantes (copias importadas o datos a medias) para que nada falle al leerlos. */
  function normalizar(d) {
    if (!Array.isArray(d.dias)) d.dias = [];
    if (!d.logros || typeof d.logros !== 'object') d.logros = {};
    d.total = +d.total || 0;
    d.bien = +d.bien || 0;
    Object.keys(d.sets).forEach(function (k) {
      var s = d.sets[k];
      if (!s || typeof s !== 'object') { delete d.sets[k]; return; }
      if (!s.q || typeof s.q !== 'object') s.q = {};
      if (!Array.isArray(s.intentos)) s.intentos = [];
      if (!s.actual || !Array.isArray(s.actual.ids)) s.actual = null;
    });
    return d;
  }

  function guardar(d) {
    try {
      localStorage.setItem(CLAVE, JSON.stringify(d));
      return true;
    } catch (e) {
      toast('No se pudo guardar tu progreso en este navegador (¿modo incógnito o sin espacio?).', 'error');
      return false;
    }
  }

  /** Datos de un set; si el docente reemplazó las preguntas (versión distinta) se reinicia su dominio. */
  function set(d, id, version, titulo, tema) {
    var s = d.sets[id];
    if (!s) s = d.sets[id] = { v: version || 0, titulo: titulo || '', tema: tema || 0, q: {}, intentos: [], actual: null };
    if (version && s.v !== version) {
      s.q = {};
      s.actual = null;
      s.v = version;
    }
    if (titulo) s.titulo = titulo;
    if (tema !== undefined && tema !== null) s.tema = tema;
    return s;
  }

  function hoy() {
    var f = new Date();
    return f.getFullYear() + '-' + String(f.getMonth() + 1).padStart(2, '0') + '-' + String(f.getDate()).padStart(2, '0');
  }

  function registrarRespuesta(d, setId, qid, fraccion) {
    var s = d.sets[setId];
    if (fraccion !== null && fraccion !== undefined && s) {
      s.q[qid] = [Math.round(fraccion * 1000) / 1000, Date.now()];
      d.total++;
      if (fraccion >= 0.999) d.bien++;
    }
    var h = hoy();
    if (d.dias.indexOf(h) < 0) {
      d.dias.push(h);
      if (d.dias.length > 400) d.dias = d.dias.slice(-400);
    }
  }

  /** Cuenta dominadas / errores / nuevas sobre la lista de ids calificables del set. */
  function conteo(s, ids) {
    var r = { dominadas: 0, errores: 0, nuevas: 0, total: ids.length };
    ids.forEach(function (id) {
      var e = s && s.q[id];
      if (!e) r.nuevas++;
      else if (e[0] >= 0.999) r.dominadas++;
      else r.errores++;
    });
    return r;
  }

  function racha(d) {
    var dias = {};
    d.dias.forEach(function (x) { dias[x] = 1; });
    var f = new Date();
    var clave = function () { return f.getFullYear() + '-' + String(f.getMonth() + 1).padStart(2, '0') + '-' + String(f.getDate()).padStart(2, '0'); };
    if (!dias[clave()]) f.setDate(f.getDate() - 1);
    var n = 0;
    while (dias[clave()]) { n++; f.setDate(f.getDate() - 1); }
    return n;
  }

  /** Guarda el intento terminado y devuelve los logros nuevos. */
  function registrarIntento(d, setId, intento, idsCalificables) {
    var s = d.sets[setId];
    var previos = s.intentos.filter(function (i) { return i.maximo > 0; });
    var mejorPrevio = previos.length ? Math.max.apply(null, previos.map(function (i) { return i.puntaje / i.maximo; })) : null;
    s.intentos.unshift(intento);
    s.intentos = s.intentos.slice(0, MAX_INTENTOS);
    s.intentos.forEach(function (i, n) { if (n >= MAX_CON_DETALLE) delete i.items; });
    s.actual = null;
    return evaluarLogros(d, { set: setId, intento: intento, ids: idsCalificables, mejorPrevio: mejorPrevio });
  }

  // ------------------------------------------------------------------ logros

  var LOGROS = [
    { id: 'primer_intento', icono: '🎯', nombre: 'Primer paso', desc: 'Termina tu primer intento.',
      ok: function (d) { return totalIntentos(d) >= 1; } },
    { id: 'diez_intentos', icono: '💪', nombre: 'Constancia', desc: 'Termina 10 intentos.',
      ok: function (d) { return totalIntentos(d) >= 10; } },
    { id: 'perfecto', icono: '⭐', nombre: 'Perfecto', desc: '100 % en un intento de 5 preguntas o más.',
      ok: function (d, c) { return c && c.intento.maximo >= 5 && c.intento.puntaje >= c.intento.maximo - 0.001; } },
    { id: 'examen_aprobado', icono: '🎓', nombre: 'Aprobado', desc: '60 % o más en un simulacro de 10 preguntas o más.',
      ok: function (d, c) { return c && c.intento.modo === 'examen' && c.intento.maximo >= 10 && c.intento.puntaje / c.intento.maximo >= 0.6; } },
    { id: 'examen_perfecto', icono: '🏆', nombre: 'Examen perfecto', desc: '100 % en un simulacro de 10 preguntas o más.',
      ok: function (d, c) { return c && c.intento.modo === 'examen' && c.intento.maximo >= 10 && c.intento.puntaje >= c.intento.maximo - 0.001; } },
    { id: 'contrarreloj', icono: '⏱️', nombre: 'Contrarreloj', desc: '80 % o más en un simulacro con tiempo límite.',
      ok: function (d, c) { return c && c.intento.modo === 'examen' && c.intento.limite > 0 && c.intento.maximo >= 5 && c.intento.puntaje / c.intento.maximo >= 0.8; } },
    { id: 'aprender_errores', icono: '🔁', nombre: 'Aprender de los errores', desc: '100 % en un repaso de errores.',
      ok: function (d, c) { return c && c.intento.modo === 'errores' && c.intento.maximo >= 3 && c.intento.puntaje >= c.intento.maximo - 0.001; } },
    { id: 'superacion', icono: '📈', nombre: 'Superación', desc: 'Mejora tu mejor puntaje anterior en un set.',
      ok: function (d, c) { return c && c.mejorPrevio !== null && c.intento.maximo > 0 && c.intento.puntaje / c.intento.maximo > c.mejorPrevio + 0.001; } },
    { id: 'dominio_set', icono: '👑', nombre: 'Dominio total', desc: 'Domina todas las preguntas de un set.',
      ok: function (d, c) { if (!c || !c.ids || !c.ids.length) return false; var k = conteo(d.sets[c.set], c.ids); return k.dominadas === k.total; } },
    { id: 'explorador', icono: '🧭', nombre: 'Explorador', desc: 'Practica sets de 3 temas distintos.',
      ok: function (d) { var t = {}; Object.keys(d.sets).forEach(function (k) { if (d.sets[k].intentos.length) t[d.sets[k].tema || 0] = 1; }); return Object.keys(t).length >= 3; } },
    { id: 'racha_3', icono: '🔥', nombre: 'En racha', desc: 'Practica 3 días seguidos.',
      ok: function (d) { return racha(d) >= 3; } },
    { id: 'racha_7', icono: '🌋', nombre: 'Semana completa', desc: 'Practica 7 días seguidos.',
      ok: function (d) { return racha(d) >= 7; } },
    { id: 'resp_50', icono: '📚', nombre: 'Lector', desc: 'Responde 50 preguntas.',
      ok: function (d) { return d.total >= 50; } },
    { id: 'resp_200', icono: '🧠', nombre: 'Estudioso', desc: 'Responde 200 preguntas.',
      ok: function (d) { return d.total >= 200; } },
    { id: 'resp_1000', icono: '🦉', nombre: 'Sabio', desc: 'Responde 1000 preguntas.',
      ok: function (d) { return d.total >= 1000; } }
  ];

  function totalIntentos(d) {
    return Object.keys(d.sets).reduce(function (a, k) { return a + d.sets[k].intentos.length; }, 0);
  }

  function evaluarLogros(d, ctx) {
    var nuevos = [];
    LOGROS.forEach(function (l) {
      if (d.logros[l.id]) return;
      var ok = false;
      try { ok = l.ok(d, ctx); } catch (e) { ok = false; }
      if (ok) { d.logros[l.id] = Date.now(); nuevos.push(l); }
    });
    return nuevos;
  }

  // ------------------------------------------------------------------ utilidades de interfaz

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function url(ruta) { return (window.GP_BASE || '') + '/' + ruta.replace(/^\//, ''); }

  function fecha(ts) {
    var f = new Date(ts);
    return f.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' +
      f.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
  }

  function clase(f) {
    if (f === null || f === undefined) return 'neutra';
    if (f >= 0.999) return 'bien';
    if (f > 0) return 'parcial';
    return 'mal';
  }

  /** Nota chilena 1,0–7,0 con exigencia 60 % (referencial). */
  function nota(frac) {
    var n = frac < 0.6 ? 1 + 3 * frac / 0.6 : 4 + 3 * (frac - 0.6) / 0.4;
    return n.toFixed(1).replace('.', ',');
  }

  function barra(frac, cls) {
    var p = Math.max(0, Math.min(100, Math.round(frac * 100)));
    return '<div class="progreso ' + (cls || '') + '" title="' + p + '%"><div style="width:' + p + '%"></div></div>';
  }

  function nombreModo(m) { return { practica: 'Práctica', examen: 'Examen', errores: 'Repaso de errores' }[m] || m; }

  function toast(msg, tipo, html) {
    var cont = document.getElementById('toasts');
    if (!cont) return;
    var t = document.createElement('div');
    t.className = 'toast ' + (tipo || '');
    if (html) t.innerHTML = msg; else t.textContent = msg;
    cont.appendChild(t);
    setTimeout(function () { t.classList.add('fuera'); }, 4500);
    setTimeout(function () { t.remove(); }, 5000);
  }

  function logroHtml(l, ts) {
    return '<div class="logro ' + (ts ? 'ganado' : '') + '" title="' + esc(l.desc) + '">' +
      '<span class="icono">' + (ts ? l.icono : '🔒') + '</span>' +
      '<strong>' + esc(l.nombre) + '</strong><small>' + esc(l.desc) + '</small>' +
      (ts ? '<small class="cuando">' + new Date(ts).toLocaleDateString('es-CL') + '</small>' : '') + '</div>';
  }

  window.GP = {
    cargar: cargar, guardar: guardar, set: set, registrarRespuesta: registrarRespuesta, registrarIntento: registrarIntento,
    conteo: conteo, racha: racha, LOGROS: LOGROS, evaluarLogros: evaluarLogros, totalIntentos: totalIntentos,
    esc: esc, url: url, fecha: fecha, clase: clase, nota: nota, barra: barra, nombreModo: nombreModo, toast: toast, logroHtml: logroHtml
  };

  // ------------------------------------------------------------------ mejoras de las páginas públicas

  function ids(el) { return (el.getAttribute('data-ids') || '').split(',').filter(Boolean).map(Number); }

  document.addEventListener('DOMContentLoaded', function () {
    var d = cargar();
    var cambios = false;

    // Racha en la barra superior
    var r = racha(d);
    document.querySelectorAll('[data-racha]').forEach(function (el) {
      if (r > 0) { el.hidden = false; el.textContent = '🔥 ' + r + (r === 1 ? ' día' : ' días'); el.title = 'Días seguidos practicando'; }
    });

    // Tarjetas / página de un set
    document.querySelectorAll('[data-progreso-set]').forEach(function (el) {
      var id = el.getAttribute('data-progreso-set');
      var v = parseInt(el.getAttribute('data-version'), 10);
      var s = d.sets[id];
      if (s && v && s.v !== v) { set(d, id, v); cambios = true; }
      var k = conteo(s, ids(el));
      var frac = k.total ? k.dominadas / k.total : 0;
      var q = function (sel) { return el.querySelectorAll(sel); };
      q('[data-barra]').forEach(function (b) {
        b.innerHTML = barra(frac, 'bien') + '<span class="small muted">Dominas ' + k.dominadas + ' de ' + k.total + '</span>';
      });
      q('[data-n-dominadas]').forEach(function (x) { x.textContent = k.dominadas; });
      q('[data-n-errores]').forEach(function (x) { x.textContent = k.errores; });
      q('[data-n-nuevas]').forEach(function (x) { x.textContent = k.nuevas; });
      q('[data-fuente=errores]').forEach(function (x) { x.disabled = !k.errores; });
      q('[data-fuente=nuevas]').forEach(function (x) { x.disabled = !k.nuevas; });
      if (s && s.actual) {
        q('[data-continuar-set], [data-continuar-caja]').forEach(function (x) { x.hidden = false; });
        // Empezar otro intento descarta el pendiente: pedir confirmación
        var descartar = function (e) {
          if (!window.confirm('Tienes un intento sin terminar en este set. Si empiezas uno nuevo se descartará. ¿Seguir?')) e.preventDefault();
        };
        q('form').forEach(function (f) { f.addEventListener('submit', descartar); });
        q('[data-errores]').forEach(function (x) { x.addEventListener('click', descartar); });
      }
      q('[data-errores]').forEach(function (x) { x.hidden = !k.errores; x.textContent = 'Repasar errores (' + k.errores + ')'; });
      if (s && s.intentos.length) {
        var mejor = Math.max.apply(null, s.intentos.map(function (i) { return i.maximo ? i.puntaje / i.maximo : 0; }));
        q('[data-resumen]').forEach(function (x) { x.textContent = '· ' + s.intentos.length + ' intento' + (s.intentos.length > 1 ? 's' : '') + ' · mejor ' + Math.round(mejor * 100) + '%'; });
        q('[data-historial]').forEach(function (x) { x.innerHTML = historialHtml(id, s); });
      }
    });

    // Tarjetas de tema: dominio agregado de sus sets
    document.querySelectorAll('[data-progreso-tema]').forEach(function (el) {
      var mapa = {};
      try { mapa = JSON.parse(el.getAttribute('data-progreso-tema')); } catch (e) { return; }
      var tot = 0, dom = 0;
      Object.keys(mapa).forEach(function (sid) { var k = conteo(d.sets[sid], mapa[sid]); tot += k.total; dom += k.dominadas; });
      var b = el.querySelector('[data-barra]');
      if (b && dom) b.innerHTML = barra(tot ? dom / tot : 0, 'bien') + '<span class="small muted">Dominas ' + dom + ' de ' + tot + '</span>';
    });

    // Portada: intentos sin terminar
    document.querySelectorAll('[data-continuar]').forEach(function (el) {
      var pend = Object.keys(d.sets).filter(function (k) { return d.sets[k].actual; });
      el.innerHTML = pend.map(function (k) {
        return '<a class="btn secundario" href="' + esc(url('practicar.php?id=' + k + '&continuar=1')) + '">Continuar: ' + esc(d.sets[k].titulo) + '</a>';
      }).join(' ');
    });

    if (document.querySelector('[data-logros]')) paginaProgreso(d);
    if (cambios) guardar(d);
  });

  function historialHtml(id, s) {
    var ult = s.intentos.slice(0, 12);
    var graf = '<div class="grafico" title="Evolución de tus puntajes">' + ult.slice().reverse().map(function (i) {
      var f = i.maximo ? i.puntaje / i.maximo : 0;
      return '<span class="barra-v ' + clase(f) + '" style="height:' + Math.max(4, Math.round(f * 100)) + '%" title="' + esc(fecha(i.fin)) + ' · ' + Math.round(f * 100) + '%"></span>';
    }).join('') + '</div>';
    var filas = ult.map(function (i) {
      var f = i.maximo ? i.puntaje / i.maximo : null;
      return '<tr><td>' + esc(fecha(i.fin)) + '</td><td>' + esc(nombreModo(i.modo)) + '</td>' +
        '<td><span class="chip ' + clase(f) + '">' + (f === null ? '—' : Math.round(f * 100) + '%') + '</span></td>' +
        '<td>' + (i.items ? '<a href="' + esc(url('practicar.php?id=' + id + '&revisar=' + i.id)) + '">Revisar</a>' : '') + '</td></tr>';
    }).join('');
    return '<h3>Últimos intentos</h3>' + graf + '<table class="tabla compacta">' + filas + '</table>';
  }

  function paginaProgreso(d) {
    var cat = {};
    try { cat = JSON.parse(document.getElementById('catalogo').textContent); } catch (e) { /* vacío */ }
    var r = racha(d);
    var nLogros = Object.keys(d.logros).length;
    document.querySelector('[data-resumen-global]').innerHTML =
      '<div><strong>' + totalIntentos(d) + '</strong><span>intentos</span></div>' +
      '<div><strong>' + d.total + '</strong><span>respuestas</span></div>' +
      '<div><strong>' + (d.total ? Math.round(100 * d.bien / d.total) + '%' : '—') + '</strong><span>aciertos</span></div>' +
      '<div><strong>' + r + (r ? ' 🔥' : '') + '</strong><span>días seguidos</span></div>' +
      '<div><strong>' + nLogros + '/' + LOGROS.length + '</strong><span>logros</span></div>';

    document.querySelector('[data-logros]').innerHTML = LOGROS.map(function (l) { return logroHtml(l, d.logros[l.id]); }).join('');

    // Sets practicados (y los que siguen existiendo)
    var filas = Object.keys(d.sets).filter(function (k) { return d.sets[k].intentos.length || Object.keys(d.sets[k].q).length; }).map(function (k) {
      var s = d.sets[k], c = cat[k];
      var k2 = c ? conteo(s, c.ids) : null;
      var mejor = s.intentos.length ? Math.max.apply(null, s.intentos.map(function (i) { return i.maximo ? i.puntaje / i.maximo : 0; })) : null;
      return '<tr><td>' + (c ? '<a href="' + esc(url('set.php?id=' + k)) + '">' + esc(c.titulo) + '</a><div class="small muted">' + esc(c.tema) + '</div>'
        : esc(s.titulo) + ' <span class="chip suave">ya no disponible</span>') + '</td>' +
        '<td style="min-width:140px">' + (k2 ? barra(k2.total ? k2.dominadas / k2.total : 0, 'bien') + '<span class="small muted">' + k2.dominadas + '/' + k2.total + ' dominadas</span>' : '') + '</td>' +
        '<td>' + s.intentos.length + '</td><td>' + (mejor === null ? '—' : Math.round(mejor * 100) + '%') + '</td></tr>';
    });
    if (filas.length) {
      document.querySelector('[data-sets]').innerHTML = '<div class="tabla-scroll"><table class="tabla"><thead><tr><th>Set</th><th>Dominio</th><th>Intentos</th><th>Mejor</th></tr></thead><tbody>' + filas.join('') + '</tbody></table></div>';
    }

    var todos = [];
    Object.keys(d.sets).forEach(function (k) { d.sets[k].intentos.forEach(function (i) { todos.push([k, i]); }); });
    todos.sort(function (a, b) { return b[1].fin - a[1].fin; });
    if (todos.length) {
      document.querySelector('[data-intentos]').innerHTML = '<div class="tabla-scroll"><table class="tabla"><thead><tr><th>Fecha</th><th>Set</th><th>Modo</th><th>Resultado</th><th></th></tr></thead><tbody>' +
        todos.slice(0, 30).map(function (x) {
          var i = x[1], f = i.maximo ? i.puntaje / i.maximo : null;
          return '<tr><td>' + esc(fecha(i.fin)) + '</td><td>' + esc(d.sets[x[0]].titulo) + '</td><td>' + esc(nombreModo(i.modo)) + '</td>' +
            '<td><span class="chip ' + clase(f) + '">' + (f === null ? '—' : Math.round(f * 100) + '%') + '</span></td>' +
            '<td>' + (i.items && cat[x[0]] ? '<a class="btn chico secundario" href="' + esc(url('practicar.php?id=' + x[0] + '&revisar=' + i.id)) + '">Revisar</a>' : '') + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    }

    document.querySelector('[data-exportar]').addEventListener('click', function () {
      var blob = new Blob([JSON.stringify(cargar())], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'mi-progreso-' + new Date().toISOString().slice(0, 10) + '.json';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    });

    document.querySelector('[data-importar-btn]').addEventListener('click', function () {
      document.querySelector('[data-importar]').click();
    });

    document.querySelector('[data-importar]').addEventListener('change', function () {
      var f = this.files[0];
      if (!f) return;
      var lector = new FileReader();
      lector.onload = function () {
        try {
          var nuevo = JSON.parse(lector.result);
          if (!valido(nuevo)) throw new Error('formato');
          if (!window.confirm('¿Reemplazar el progreso de este navegador por el del archivo?')) return;
          if (guardar(normalizar(nuevo))) location.reload();
        } catch (e) {
          toast('El archivo no es una copia de progreso válida.', 'error');
        }
      };
      lector.readAsText(f);
    });

    document.querySelector('[data-borrar]').addEventListener('click', function () {
      if (!window.confirm('¿Borrar TODO tu progreso, historial y logros de este navegador? No se puede deshacer.')) return;
      try { localStorage.removeItem(CLAVE); } catch (e) { /* nada */ }
      location.reload();
    });
  }
})();
