<?php
require __DIR__ . '/../inc/bootstrap.php';

requiere_admin();

if (es_post() && ($_POST['accion'] ?? '') === 'visible') {
    db()->prepare('UPDATE sets SET visible = 1 - visible WHERE id = ?')->execute([(int)$_POST['id']]);
    redirigir('admin/index.php' . (get_int('tema') ? '?tema=' . get_int('tema') : ''));
}

$temas = temas_lista();
$filtro = get_int('tema');
$sql = 'SELECT s.id, s.titulo, s.visible, s.creado, s.archivo, s.tema_id,
        (SELECT COUNT(*) FROM preguntas p WHERE p.set_id = s.id) AS n_preg
    FROM sets s LEFT JOIN temas t ON t.id = s.tema_id';
if ($filtro) $sql .= ' WHERE s.tema_id = ' . $filtro;
$sets = db()->query($sql . ' ORDER BY t.orden IS NULL, t.orden, s.orden, s.titulo COLLATE NOCASE')->fetchAll();
$porTema = [];
foreach ($sets as $s) $porTema[(int)$s['tema_id']][] = $s;

cabecera('Sets', 'sets', 'admin');
?>
<div class="grid-2">
  <section class="tarjeta">
    <h1>Subir set GIFT</h1>
    <form method="post" action="<?= h(url('admin/subir.php')) ?>" enctype="multipart/form-data" class="form">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="previsualizar">
      <label class="soltar" data-soltar>
        <input type="file" name="archivo" accept=".txt,.gift,text/plain">
        <span>Arrastra aquí el archivo <strong>.txt</strong> en formato GIFT o haz clic para elegirlo</span>
      </label>
      <details>
        <summary>…o pega el texto GIFT directamente</summary>
        <textarea name="texto" rows="8" class="mono" placeholder="::P1:: ¿Capital de Chile? {=Santiago ~Lima ~Quito}"></textarea>
      </details>
      <label>Tema
        <select name="tema_id">
          <option value="0">— Sin tema —</option>
          <?php foreach ($temas as $tid => $nombre): ?><option value="<?= $tid ?>"<?= $tid === $filtro ? ' selected' : '' ?>><?= h($nombre) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php if (!$temas): ?><p class="small flash aviso">Aún no hay temas. <a href="<?= h(url('admin/temas.php')) ?>">Crea los temas</a> para que los estudiantes puedan elegir.</p><?php endif; ?>
      <label>Título del set (opcional, por defecto el nombre del archivo) <input name="titulo" maxlength="120"></label>
      <button class="btn">Revisar antes de guardar →</button>
    </form>
    <p class="muted small">Tipos soportados: opción múltiple, V/F, respuesta corta, numérica, emparejamiento, desarrollo, palabra faltante y descripción. <a href="<?= h(url('ejemplos/ejemplo.txt')) ?>" download>Descargar archivo de ejemplo</a>.</p>
  </section>

  <section class="tarjeta">
    <h2>Resumen</h2>
    <?php $tot = db()->query('SELECT (SELECT COUNT(*) FROM temas) AS temas, (SELECT COUNT(*) FROM sets) AS sets, (SELECT COUNT(*) FROM preguntas) AS preg')->fetch(); ?>
    <div class="stats">
      <div><strong><?= (int)$tot['temas'] ?></strong><span>temas</span></div>
      <div><strong><?= (int)$tot['sets'] ?></strong><span>sets</span></div>
      <div><strong><?= (int)$tot['preg'] ?></strong><span>preguntas</span></div>
    </div>
    <p class="small muted">Los estudiantes no tienen cuenta: su progreso y logros se guardan solo en su navegador.
      <?= cfg('estadisticas_anonimas') ? 'Las estadísticas anónimas por pregunta están <strong>activadas</strong>.' : 'No se guarda ningún dato de uso (puedes activar estadísticas anónimas por pregunta en <code>config.php</code>).' ?></p>
  </section>
</div>

<section class="tarjeta">
  <div class="fila entre">
    <h2>Sets cargados</h2>
    <form method="get" class="fila">
      <select name="tema" onchange="this.form.submit()">
        <option value="0">Todos los temas</option>
        <?php foreach ($temas as $tid => $nombre): ?><option value="<?= $tid ?>"<?= $tid === $filtro ? ' selected' : '' ?>><?= h($nombre) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php if (!$sets): ?>
    <p class="muted">No hay sets<?= $filtro ? ' en este tema' : '' ?>. Sube el primero arriba.</p>
  <?php endif; ?>
  <?php foreach ($porTema as $tid => $lista): ?>
    <h3><?= h($tid ? ($temas[$tid] ?? '?') : 'Sin tema (se muestran en «Otros»)') ?></h3>
    <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Set</th><th>Preguntas</th><th>Visible</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lista as $s): ?>
        <tr class="<?= $s['visible'] ? '' : 'apagado' ?>">
          <td><a href="<?= h(url('admin/set.php?id=' . $s['id'])) ?>"><strong><?= h($s['titulo']) ?></strong></a>
            <div class="muted small"><?= h($s['archivo']) ?> · <?= fecha($s['creado']) ?></div></td>
          <td><?= (int)$s['n_preg'] ?></td>
          <td>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="visible"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="interruptor <?= $s['visible'] ? 'on' : '' ?>" title="Mostrar/ocultar a estudiantes"><?= $s['visible'] ? 'Sí' : 'No' ?></button>
            </form>
          </td>
          <td><a class="btn chico" href="<?= h(url('admin/set.php?id=' . $s['id'])) ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endforeach; ?>
</section>
<?php pie('admin');
