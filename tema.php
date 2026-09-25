<?php
require __DIR__ . '/inc/bootstrap.php';

$tid = get_int('id');
if ($tid) {
    $tema = obtener_tema($tid);
    if (!$tema || !$tema['visible']) redirigir('index.php');
    $st = db()->prepare("SELECT id, titulo, descripcion, version,
            (SELECT COUNT(*) FROM preguntas p WHERE p.set_id = s.id) AS n_preg
        FROM sets s WHERE tema_id = ? AND visible = 1 ORDER BY orden, titulo COLLATE NOCASE");
    $st->execute([$tid]);
} else {
    $tema = ['nombre' => 'Otros', 'descripcion' => ''];
    $st = db()->query("SELECT id, titulo, descripcion, version,
            (SELECT COUNT(*) FROM preguntas p WHERE p.set_id = s.id) AS n_preg
        FROM sets s WHERE tema_id IS NULL AND visible = 1 ORDER BY orden, titulo COLLATE NOCASE");
}
$sets = $st->fetchAll();
$ids = ids_calificables(array_map('intval', array_column($sets, 'id')));

cabecera($tema['nombre'], 'temas');
?>
<p><a href="<?= h(url('index.php')) ?>">← Temas</a></p>
<h1><?= h($tema['nombre']) ?></h1>
<?php if ($tema['descripcion'] !== ''): ?><p class="muted"><?= nl2br(h($tema['descripcion'])) ?></p><?php endif; ?>

<?php if (!$sets): ?>
  <p class="tarjeta muted">Este tema aún no tiene sets.</p>
<?php endif; ?>

<div class="tarjetas">
<?php foreach ($sets as $s): $sid = (int)$s['id']; ?>
  <article class="tarjeta set" data-progreso-set="<?= $sid ?>" data-version="<?= (int)$s['version'] ?>" data-ids="<?= h(implode(',', $ids[$sid])) ?>">
    <h2><a href="<?= h(url('set.php?id=' . $sid)) ?>"><?= h($s['titulo']) ?></a></h2>
    <?php if ($s['descripcion'] !== ''): ?><p class="muted"><?= nl2br(h($s['descripcion'])) ?></p><?php endif; ?>
    <p class="small"><?= (int)$s['n_preg'] ?> preguntas <span data-resumen></span></p>
    <div class="dominio" data-barra></div>
    <div class="fila">
      <a class="btn" href="<?= h(url('set.php?id=' . $sid)) ?>">Practicar</a>
      <a class="btn secundario" data-continuar-set hidden href="<?= h(url('practicar.php?id=' . $sid . '&continuar=1')) ?>">Continuar intento</a>
      <a class="btn secundario" data-errores hidden href="<?= h(url('practicar.php?id=' . $sid . '&fuente=errores&n=0')) ?>">Repasar errores</a>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php pie();
