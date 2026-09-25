<?php
require __DIR__ . '/../inc/bootstrap.php';

requiere_admin();
if (!es_post()) redirigir('admin/index.php');

$accion = $_POST['accion'] ?? '';
$reemplazar = (int)($_POST['reemplazar'] ?? 0);
$setDestino = $reemplazar ? obtener_set($reemplazar) : null;

// ---------------------------------------------------------------- guardar (tras previsualizar)
if ($accion === 'guardar') {
    $tok = (string)($_POST['token'] ?? '');
    $sub = $_SESSION['subidas'][$tok] ?? null;
    if (!$sub) {
        flash('La previsualización expiró. Vuelve a subir el archivo.', 'error');
        redirigir('admin/index.php');
    }
    unset($_SESSION['subidas'][$tok]);
    $res = Gift::parsear($sub['fuente']);
    if (!$res['preguntas']) redirigir('admin/index.php');

    $titulo = mb_substr(post_str('titulo') ?: $sub['titulo'], 0, 120);
    $descripcion = mb_substr(post_str('descripcion'), 0, 1000);
    $tema = (int)($_POST['tema_id'] ?? 0);
    $tema = ($tema && obtener_tema($tema)) ? $tema : null;

    $pdo = db();
    $pdo->beginTransaction();
    if ($setDestino) {
        // La versión cambia: el progreso guardado en los navegadores para este set se reinicia solo
        $id = (int)$setDestino['id'];
        $pdo->prepare('DELETE FROM preguntas WHERE set_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE sets SET tema_id = ?, titulo = ?, descripcion = ?, archivo = ?, fuente = ?, version = version + 1 WHERE id = ?')
            ->execute([$tema, $titulo, $descripcion, $sub['archivo'], $sub['fuente'], $id]);
        flash('Preguntas del set reemplazadas (' . count($res['preguntas']) . ').');
    } else {
        $pdo->prepare('INSERT INTO sets (tema_id, titulo, descripcion, archivo, fuente, version, creado) VALUES (?, ?, ?, ?, ?, 1, ?)')
            ->execute([$tema, $titulo, $descripcion, $sub['archivo'], $sub['fuente'], time()]);
        $id = (int)$pdo->lastInsertId();
        flash('Set «' . $titulo . '» guardado con ' . count($res['preguntas']) . ' preguntas.');
    }
    insertar_preguntas($id, $res['preguntas']);
    $pdo->commit();
    redirigir('admin/set.php?id=' . $id);
}

// ---------------------------------------------------------------- previsualizar
$fuente = '';
$archivo = '';
$maxBytes = (int)cfg('max_upload_mb', 2) * 1024 * 1024;

if (!empty($_FILES['archivo']['name'])) {
    $f = $_FILES['archivo'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        flash('No se pudo subir el archivo (código ' . $f['error'] . '). ¿Es demasiado grande?', 'error');
        redirigir($setDestino ? 'admin/set.php?id=' . $setDestino['id'] : 'admin/index.php');
    }
    if ($f['size'] > $maxBytes) {
        flash('El archivo supera el máximo de ' . cfg('max_upload_mb') . ' MB.', 'error');
        redirigir('admin/index.php');
    }
    $fuente = (string)file_get_contents($f['tmp_name']);
    $archivo = basename((string)$f['name']);
} elseif (post_str('texto') !== '') {
    $fuente = (string)$_POST['texto'];
    $archivo = 'texto pegado';
}
if (trim($fuente) === '') {
    flash('Sube un archivo o pega el texto GIFT.', 'error');
    redirigir($setDestino ? 'admin/set.php?id=' . $setDestino['id'] : 'admin/index.php');
}
if (strpos($fuente, "\0") !== false) {
    flash('El archivo no parece ser de texto.', 'error');
    redirigir('admin/index.php');
}

$res = Gift::parsear($fuente);
$tituloSugerido = post_str('titulo');
if ($tituloSugerido === '') {
    $tituloSugerido = $setDestino['titulo'] ?? (preg_replace('/\.(txt|gift)$/i', '', $archivo) ?: 'Set sin título');
    $tituloSugerido = str_replace(['_', '-'], ' ', $tituloSugerido);
}

// Guardamos la fuente en sesión hasta que el admin confirme (se guardan máximo 3 previsualizaciones)
$token = bin2hex(random_bytes(8));
$_SESSION['subidas'] = array_slice($_SESSION['subidas'] ?? [], -2, null, true);
$_SESSION['subidas'][$token] = ['fuente' => $fuente, 'archivo' => $archivo, 'titulo' => $tituloSugerido];
$temaSel = $setDestino ? (int)$setDestino['tema_id'] : (int)($_POST['tema_id'] ?? 0);

$porTipo = [];
$categorias = [];
foreach ($res['preguntas'] as $q) {
    $clave = etiqueta_tipo($q['tipo'], $q['multiple'] ?? false);
    $porTipo[$clave] = ($porTipo[$clave] ?? 0) + 1;
    if ($q['categoria'] !== '') $categorias[$q['categoria']] = ($categorias[$q['categoria']] ?? 0) + 1;
}

cabecera('Revisar set', 'sets', 'admin');
?>
<p><a href="<?= h(url($setDestino ? 'admin/set.php?id=' . $setDestino['id'] : 'admin/index.php')) ?>">← Volver</a></p>

<section class="tarjeta">
  <h1><?= $setDestino ? 'Reemplazar preguntas de «' . h($setDestino['titulo']) . '»' : 'Revisar set antes de guardar' ?></h1>
  <p class="muted">Archivo: <strong><?= h($archivo) ?></strong></p>

  <div class="stats">
    <div><strong><?= count($res['preguntas']) ?></strong><span>preguntas válidas</span></div>
    <div class="<?= $res['errores'] ? 'mal' : '' ?>"><strong><?= count($res['errores']) ?></strong><span>con error (se omiten)</span></div>
    <div class="<?= $res['avisos'] ? 'parcial' : '' ?>"><strong><?= count($res['avisos']) ?></strong><span>advertencias</span></div>
  </div>

  <?php if ($porTipo): ?>
    <p><?php foreach ($porTipo as $t => $n): ?><span class="chip"><?= h($t) ?>: <?= $n ?></span> <?php endforeach; ?></p>
  <?php endif; ?>
  <?php if ($categorias): ?>
    <p class="small">Categorías: <?php foreach ($categorias as $c => $n): ?><span class="chip suave"><?= h($c) ?> (<?= $n ?>)</span> <?php endforeach; ?></p>
  <?php endif; ?>

  <?php if ($res['errores']): ?>
    <div class="flash error"><strong>Errores</strong><ul><?php foreach ($res['errores'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($res['avisos']): ?>
    <div class="flash aviso"><strong>Advertencias</strong><ul><?php foreach ($res['avisos'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if ($res['preguntas']): ?>
    <form method="post" class="form">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <input type="hidden" name="reemplazar" value="<?= (int)$reemplazar ?>">
      <label>Título <input name="titulo" required maxlength="120" value="<?= h($tituloSugerido) ?>"></label>
      <label>Tema
        <select name="tema_id">
          <option value="0">— Sin tema —</option>
          <?php foreach (temas_lista() as $tid => $nombre): ?><option value="<?= $tid ?>"<?= $tid === $temaSel ? ' selected' : '' ?>><?= h($nombre) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Descripción para los estudiantes (opcional)
        <textarea name="descripcion" rows="2" maxlength="1000"><?= h($setDestino['descripcion'] ?? '') ?></textarea></label>
      <?php if ($setDestino): ?>
        <p class="flash aviso">Se reemplazarán todas las preguntas. El dominio y el repaso de errores que los estudiantes tengan en su navegador para este set se reiniciarán (su historial de puntajes se mantiene).</p>
      <?php endif; ?>
      <button class="btn"><?= $setDestino ? 'Reemplazar preguntas' : 'Guardar set' ?> (<?= count($res['preguntas']) ?> preguntas)</button>
    </form>
  <?php else: ?>
    <p class="flash error">No se encontró ninguna pregunta válida. Revisa el formato del archivo.</p>
  <?php endif; ?>
</section>

<?php foreach ($res['preguntas'] as $n => $q): ?>
  <article class="tarjeta pregunta vista">
    <div class="meta">
      <span>#<?= $n + 1 ?> · línea <?= (int)$q['linea'] ?></span>
      <span class="chip"><?= h(etiqueta_tipo($q['tipo'], $q['multiple'] ?? false)) ?></span>
      <?php if ($q['categoria'] !== ''): ?><span class="chip suave"><?= h($q['categoria']) ?></span><?php endif; ?>
      <?php if ($q['titulo'] !== ''): ?><strong><?= h($q['titulo']) ?></strong><?php endif; ?>
    </div>
    <?= render_pregunta($q, ['solucion' => true, 'mezclar' => false]) ?>
  </article>
<?php endforeach; ?>
<?php pie('admin');
