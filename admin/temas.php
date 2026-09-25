<?php
require __DIR__ . '/../inc/bootstrap.php';

requiere_admin();

if (es_post()) {
    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($accion === 'crear' && post_str('nombre') !== '') {
        $orden = (int)db()->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM temas')->fetchColumn();
        db()->prepare('INSERT INTO temas (nombre, descripcion, orden, creado) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr(post_str('nombre'), 0, 100), mb_substr(post_str('descripcion'), 0, 500), $orden, time()]);
        flash('Tema creado.');
    } elseif ($accion === 'editar' && $id) {
        db()->prepare('UPDATE temas SET nombre = ?, descripcion = ?, visible = ? WHERE id = ?')
            ->execute([mb_substr(post_str('nombre'), 0, 100) ?: 'Sin nombre', mb_substr(post_str('descripcion'), 0, 500), isset($_POST['visible']) ? 1 : 0, $id]);
        flash('Tema actualizado.');
    } elseif (($accion === 'subir' || $accion === 'bajar') && $id) {
        // Reordena intercambiando con el vecino
        $ids = array_keys(temas_lista());
        $i = array_search($id, $ids, true);
        $j = $accion === 'subir' ? $i - 1 : $i + 1;
        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            $up = db()->prepare('UPDATE temas SET orden = ? WHERE id = ?');
            foreach ($ids as $n => $tid) $up->execute([$n + 1, $tid]);
        }
    } elseif ($accion === 'eliminar' && $id) {
        db()->prepare('DELETE FROM temas WHERE id = ?')->execute([$id]);
        flash('Tema eliminado. Sus sets quedaron en «Otros» (sin tema).');
    }
    redirigir('admin/temas.php');
}

$temas = db()->query('SELECT t.*, (SELECT COUNT(*) FROM sets s WHERE s.tema_id = t.id) AS n_sets
    FROM temas t ORDER BY t.orden, t.nombre COLLATE NOCASE')->fetchAll();

cabecera('Temas', 'temas', 'admin');
?>
<div class="grid-2">
  <section class="tarjeta">
    <h1>Temas</h1>
    <p class="muted">Los estudiantes eligen primero un tema y luego un set dentro de él. Cada set pertenece a un tema.</p>
  </section>
  <section class="tarjeta">
    <h2>Nuevo tema</h2>
    <form method="post" class="form">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="crear">
      <label>Nombre <input name="nombre" required maxlength="100" placeholder="Ej: Unidad 1 · Célula"></label>
      <label>Descripción (opcional) <input name="descripcion" maxlength="500"></label>
      <button class="btn">Crear tema</button>
    </form>
  </section>
</div>

<?php if (!$temas): ?>
  <p class="tarjeta muted">Aún no hay temas. Crea el primero.</p>
<?php endif; ?>
<?php foreach ($temas as $n => $t): ?>
  <section class="tarjeta <?= $t['visible'] ? '' : 'apagado' ?>">
    <form method="post" class="fila">
      <?= csrf_campo() ?>
      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <input type="hidden" name="accion" value="editar">
      <input name="nombre" value="<?= h($t['nombre']) ?>" required maxlength="100" aria-label="Nombre">
      <input name="descripcion" value="<?= h($t['descripcion']) ?>" maxlength="500" placeholder="Descripción" aria-label="Descripción">
      <label class="check"><input type="checkbox" name="visible" <?= $t['visible'] ? 'checked' : '' ?>> Visible</label>
      <button class="btn chico">Guardar</button>
    </form>
    <div class="fila">
      <a href="<?= h(url('admin/index.php?tema=' . $t['id'])) ?>"><?= (int)$t['n_sets'] ?> set<?= $t['n_sets'] == 1 ? '' : 's' ?></a>
      <span class="muted">·</span>
      <?php foreach (['subir' => '↑', 'bajar' => '↓'] as $acc => $flecha): if (($acc === 'subir' && $n === 0) || ($acc === 'bajar' && $n === count($temas) - 1)) continue; ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="accion" value="<?= $acc ?>">
          <button class="btn chico secundario" title="Mover <?= $acc === 'subir' ? 'arriba' : 'abajo' ?>"><?= $flecha ?></button></form>
      <?php endforeach; ?>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="accion" value="eliminar">
        <button class="btn chico peligro" data-confirmar="¿Eliminar el tema «<?= h($t['nombre']) ?>»? Sus sets no se borran, quedan sin tema.">Eliminar</button></form>
    </div>
  </section>
<?php endforeach; ?>
<?php pie('admin');
