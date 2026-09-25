<?php
require __DIR__ . '/inc/bootstrap.php';

$set = obtener_set(get_int('id'));
if (!$set || (!$set['visible'] && !admin())) redirigir('index.php');
$sid = (int)$set['id'];
$preguntas = preguntas_set($sid);
$total = count($preguntas);
$ids = ids_calificables([$sid])[$sid];

$categorias = [];
foreach ($preguntas as $q) {
    if ($q['categoria'] !== '') $categorias[$q['categoria']] = ($categorias[$q['categoria']] ?? 0) + 1;
}
ksort($categorias, SORT_NATURAL | SORT_FLAG_CASE);

cabecera($set['titulo'], 'temas');
?>
<p><a href="<?= h(url('tema.php?id=' . (int)$set['tema_id'])) ?>">← <?= h($set['tema'] ?? 'Otros') ?></a></p>

<div class="grid-2" data-progreso-set="<?= $sid ?>" data-version="<?= (int)$set['version'] ?>" data-ids="<?= h(implode(',', $ids)) ?>" data-titulo="<?= h($set['titulo']) ?>">
  <section class="tarjeta">
    <h1><?= h($set['titulo']) ?></h1>
    <?php if ($set['descripcion'] !== ''): ?><p class="muted"><?= nl2br(h($set['descripcion'])) ?></p><?php endif; ?>

    <div class="flash aviso" data-continuar-caja hidden>
      Tienes un intento sin terminar.
      <a class="btn chico" href="<?= h(url('practicar.php?id=' . $sid . '&continuar=1')) ?>">Continuar</a>
    </div>

    <form method="get" action="<?= h(url('practicar.php')) ?>" class="form" id="form-empezar">
      <input type="hidden" name="id" value="<?= $sid ?>">

      <fieldset class="modos">
        <legend>Modo</legend>
        <label class="modo"><input type="radio" name="modo" value="practica" checked>
          <span><strong>Práctica</strong><small>Ves si acertaste y la explicación después de cada pregunta.</small></span></label>
        <label class="modo"><input type="radio" name="modo" value="examen">
          <span><strong>Simulacro de examen</strong><small>Respondes todo y ves la corrección al final. Puedes poner tiempo.</small></span></label>
      </fieldset>

      <fieldset>
        <legend>Preguntas</legend>
        <label class="check"><input type="radio" name="fuente" value="todas" checked> Todas / al azar</label>
        <label class="check"><input type="radio" name="fuente" value="nuevas" data-fuente="nuevas"> Solo las que nunca he respondido <span class="chip suave" data-n-nuevas>…</span></label>
        <label class="check"><input type="radio" name="fuente" value="errores" data-fuente="errores"> Solo las que tengo malas <span class="chip suave" data-n-errores>…</span></label>
        <?php if (count($categorias) > 1): ?>
          <label>Subtema
            <select name="cat">
              <option value="">Todos</option>
              <?php foreach ($categorias as $c => $n): ?><option value="<?= h($c) ?>"><?= h($c) ?> (<?= $n ?>)</option><?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <label>Cantidad
          <select name="n">
            <?php foreach ([5, 10, 20, 30, 50] as $n): if ($n < $total): ?><option value="<?= $n ?>"<?= $n === 10 ? ' selected' : '' ?>><?= $n ?> preguntas</option><?php endif; endforeach; ?>
            <option value="0"<?= $total <= 10 ? ' selected' : '' ?>>Todas (<?= $total ?>)</option>
          </select>
        </label>
        <label data-solo-examen hidden>Tiempo límite
          <select name="min">
            <option value="0">Sin límite</option>
            <?php foreach ([5, 10, 15, 20, 30, 45, 60, 90] as $m): ?><option value="<?= $m ?>"><?= $m ?> minutos</option><?php endforeach; ?>
          </select>
        </label>
      </fieldset>
      <button class="btn grande">Comenzar</button>
    </form>
  </section>

  <section class="tarjeta">
    <h2>Mi progreso</h2>
    <div class="stats">
      <div><strong data-n-dominadas>0</strong><span>dominadas de <?= count($ids) ?></span></div>
      <div><strong data-n-errores>0</strong><span>por repasar</span></div>
      <div><strong data-n-nuevas><?= count($ids) ?></strong><span>sin ver</span></div>
    </div>
    <div data-barra></div>
    <div data-historial><p class="muted">Aún no has practicado este set.</p></div>
  </section>
</div>
<?php pie();
