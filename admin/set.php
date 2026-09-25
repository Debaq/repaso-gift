<?php
require __DIR__ . '/../inc/bootstrap.php';

requiere_admin();
$set = obtener_set(get_int('id'));
if (!$set) {
    flash('Set no encontrado.', 'error');
    redirigir('admin/index.php');
}
$id = (int)$set['id'];

if (isset($_GET['descargar'])) {
    $nombre = preg_replace('/[^\w.-]+/u', '_', $set['titulo']) . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    echo $set['fuente'];
    exit;
}

if (es_post()) {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'guardar') {
        $tema = (int)($_POST['tema_id'] ?? 0);
        db()->prepare('UPDATE sets SET tema_id = ?, titulo = ?, descripcion = ?, visible = ?, mezclar_preguntas = ?, mezclar_respuestas = ?, orden = ? WHERE id = ?')
            ->execute([
                ($tema && obtener_tema($tema)) ? $tema : null,
                mb_substr(post_str('titulo') ?: $set['titulo'], 0, 120),
                mb_substr(post_str('descripcion'), 0, 1000),
                isset($_POST['visible']) ? 1 : 0,
                isset($_POST['mezclar_preguntas']) ? 1 : 0,
                isset($_POST['mezclar_respuestas']) ? 1 : 0,
                (int)($_POST['orden'] ?? 0),
                $id,
            ]);
        flash('Cambios guardados.');
    } elseif ($accion === 'eliminar' && post_str('confirmar') === 'ELIMINAR') {
        db()->prepare('DELETE FROM sets WHERE id = ?')->execute([$id]);
        flash('Set eliminado.');
        redirigir('admin/index.php');
    } elseif ($accion === 'eliminar') {
        flash('Para eliminar escribe ELIMINAR en el cuadro.', 'error');
    } elseif ($accion === 'reiniciar_stats') {
        db()->prepare('DELETE FROM stats_pregunta WHERE pregunta_id IN (SELECT id FROM preguntas WHERE set_id = ?)')->execute([$id]);
        flash('Estadísticas anónimas reiniciadas.');
    }
    redirigir('admin/set.php?id=' . $id);
}

$preguntas = preguntas_set($id);
$categorias = array_count_values(array_filter(array_column($preguntas, 'categoria')));
$porTipo = [];
foreach ($preguntas as $q) {
    $k = etiqueta_tipo($q['tipo'], $q['multiple'] ?? false);
    $porTipo[$k] = ($porTipo[$k] ?? 0) + 1;
}

// Estadísticas anónimas (solo si están activadas en config.php)
$st = db()->prepare('SELECT s.pregunta_id, s.n, s.suma / s.n AS prom FROM stats_pregunta s
    JOIN preguntas p ON p.id = s.pregunta_id WHERE p.set_id = ? AND s.n > 0');
$st->execute([$id]);
$estPreg = [];
foreach ($st as $f) $estPreg[(int)$f['pregunta_id']] = $f;
$dificiles = array_filter($estPreg, function ($e) { return $e['n'] >= 3; });
uasort($dificiles, function ($a, $b) { return $a['prom'] <=> $b['prom']; });
$dificiles = array_slice($dificiles, 0, 8, true);

cabecera($set['titulo'], 'sets', 'admin');
?>
<p><a href="<?= h(url('admin/index.php')) ?>">← Sets</a></p>

<div class="grid-2">
  <section class="tarjeta">
    <h1><?= h($set['titulo']) ?></h1>
    <form method="post" class="form">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="guardar">
      <label>Título <input name="titulo" required maxlength="120" value="<?= h($set['titulo']) ?>"></label>
      <label>Tema
        <select name="tema_id">
          <option value="0">— Sin tema —</option>
          <?php foreach (temas_lista() as $tid => $nombre): ?><option value="<?= $tid ?>"<?= $tid === (int)$set['tema_id'] ? ' selected' : '' ?>><?= h($nombre) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Descripción <textarea name="descripcion" rows="2" maxlength="1000"><?= h($set['descripcion']) ?></textarea></label>
      <label>Orden dentro del tema (menor = primero) <input type="number" name="orden" value="<?= (int)$set['orden'] ?>" style="max-width:8rem"></label>
      <label class="check"><input type="checkbox" name="visible" <?= $set['visible'] ? 'checked' : '' ?>> Visible para estudiantes</label>
      <label class="check"><input type="checkbox" name="mezclar_preguntas" <?= $set['mezclar_preguntas'] ? 'checked' : '' ?>> Mezclar orden de preguntas</label>
      <label class="check"><input type="checkbox" name="mezclar_respuestas" <?= $set['mezclar_respuestas'] ? 'checked' : '' ?>> Mezclar orden de alternativas</label>
      <button class="btn">Guardar</button>
    </form>
    <p class="small">
      <a href="<?= h(url('admin/set.php?id=' . $id . '&descargar=1')) ?>">Descargar GIFT original</a> ·
      <a href="<?= h(url('set.php?id=' . $id)) ?>">Probar como estudiante</a>
    </p>
  </section>

  <section class="tarjeta">
    <h2>Contenido</h2>
    <div class="stats">
      <div><strong><?= count($preguntas) ?></strong><span>preguntas</span></div>
      <div><strong><?= count($categorias) ?: '—' ?></strong><span>subtemas</span></div>
      <div><strong>v<?= (int)$set['version'] ?></strong><span>versión</span></div>
    </div>
    <p><?php foreach ($porTipo as $t => $n): ?><span class="chip"><?= h($t) ?>: <?= $n ?></span> <?php endforeach; ?></p>
    <?php if ($categorias): ?>
      <p class="small">Subtemas ($CATEGORY): <?php foreach ($categorias as $c => $n): ?><span class="chip suave"><?= h($c) ?> (<?= $n ?>)</span> <?php endforeach; ?></p>
    <?php endif; ?>

    <?php if (cfg('estadisticas_anonimas')): ?>
      <h3>Preguntas más difíciles <small class="muted">(anónimo)</small></h3>
      <?php if ($dificiles): ?>
        <ol class="dificiles">
          <?php foreach ($dificiles as $qid => $e): $q = $preguntas[$qid]; ?>
            <li><a href="#p<?= $qid ?>"><?= h(mb_strimwidth(strip_tags($q['titulo'] ?: $q['texto']), 0, 70, '…')) ?></a>
              <?= barra_progreso((float)$e['prom'], clase_fraccion((float)$e['prom'])) ?>
              <span class="small muted"><?= round($e['prom'] * 100) ?>% acierto · <?= (int)$e['n'] ?> resp.</span></li>
          <?php endforeach; ?>
        </ol>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="reiniciar_stats">
          <button class="btn chico secundario" data-confirmar="¿Reiniciar los contadores de este set?">Reiniciar contadores</button></form>
      <?php else: ?>
        <p class="muted small">Aún no hay suficientes respuestas (mínimo 3 por pregunta).</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted small">No se registra ningún dato de los estudiantes. Si quieres saber qué preguntas cuestan más,
        activa <code>'estadisticas_anonimas' => true</code> en <code>config.php</code> (solo cuenta respuestas y % de acierto por pregunta, sin identificar a nadie).</p>
    <?php endif; ?>
  </section>
</div>

<section class="tarjeta">
  <h2>Reemplazar preguntas</h2>
  <p class="muted small">Sube una versión corregida del archivo. Se mostrará una vista previa antes de aplicar.</p>
  <form method="post" action="<?= h(url('admin/subir.php')) ?>" enctype="multipart/form-data" class="fila">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="previsualizar">
    <input type="hidden" name="reemplazar" value="<?= $id ?>">
    <input type="file" name="archivo" accept=".txt,.gift,text/plain" required>
    <button class="btn secundario">Previsualizar</button>
  </form>
</section>

<h2>Preguntas</h2>
<?php $n = 0; foreach ($preguntas as $qid => $q): $n++; $e = $estPreg[$qid] ?? null; ?>
  <details class="tarjeta pregunta vista" id="p<?= $qid ?>">
    <summary class="meta">
      <span>#<?= $n ?></span>
      <span class="chip"><?= h(etiqueta_tipo($q['tipo'], $q['multiple'] ?? false)) ?></span>
      <?php if ($q['categoria'] !== ''): ?><span class="chip suave"><?= h($q['categoria']) ?></span><?php endif; ?>
      <span class="resumen"><?= h(mb_strimwidth(strip_tags($q['titulo'] ?: $q['texto']), 0, 90, '…')) ?></span>
      <?php if ($e): ?><span class="chip <?= clase_fraccion((float)$e['prom']) ?>"><?= round($e['prom'] * 100) ?>% · <?= (int)$e['n'] ?></span><?php endif; ?>
    </summary>
    <?= render_pregunta($q, ['solucion' => true, 'mezclar' => false]) ?>
  </details>
<?php endforeach; ?>

<section class="tarjeta peligro">
  <h2>Zona de peligro</h2>
  <form method="post" class="fila">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="eliminar">
    <input name="confirmar" placeholder="Escribe ELIMINAR" autocomplete="off">
    <button class="btn peligro">Eliminar set</button>
  </form>
</section>
<?php pie('admin');
