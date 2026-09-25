<?php
require __DIR__ . '/inc/bootstrap.php';

// Catálogo de sets visibles para mostrar nombres y calcular el dominio (el progreso en sí está en el navegador)
$sets = db()->query('SELECT s.id, s.titulo, s.version, s.tema_id, t.nombre AS tema FROM sets s
    LEFT JOIN temas t ON t.id = s.tema_id WHERE s.visible = 1 ORDER BY t.orden, t.nombre, s.orden, s.titulo')->fetchAll();
$ids = ids_calificables(array_map('intval', array_column($sets, 'id')));
$catalogo = [];
foreach ($sets as $s) {
    $catalogo[$s['id']] = ['titulo' => $s['titulo'], 'tema' => $s['tema'] ?? 'Otros', 'tema_id' => (int)$s['tema_id'],
        'version' => (int)$s['version'], 'ids' => $ids[(int)$s['id']]];
}

cabecera('Mi progreso', 'progreso');
?>
<script type="application/json" id="catalogo"><?= json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<section class="tarjeta">
  <h1>Mi progreso</h1>
  <div class="stats" data-resumen-global></div>
</section>

<section class="tarjeta">
  <h2>Logros</h2>
  <div class="logros" data-logros></div>
</section>

<section class="tarjeta">
  <h2>Por set</h2>
  <div data-sets><p class="muted">Aún no has practicado ningún set. <a href="<?= h(url('index.php')) ?>">¡Empieza!</a></p></div>
</section>

<section class="tarjeta">
  <h2>Últimos intentos</h2>
  <div data-intentos><p class="muted">Sin intentos todavía.</p></div>
</section>

<section class="tarjeta">
  <h2>Mis datos</h2>
  <p class="muted small">Todo tu avance está guardado <strong>solo en este navegador</strong>. Si borras los datos del navegador,
    usas modo incógnito o cambias de computador, no estará. Descarga una copia para llevarla a otro equipo.</p>
  <div class="fila">
    <button class="btn secundario" data-exportar>Descargar copia (.json)</button>
    <label class="btn secundario">Cargar copia <input type="file" accept=".json,application/json" data-importar hidden></label>
    <button class="btn peligro" data-borrar>Borrar mi progreso</button>
  </div>
</section>
<?php pie();
