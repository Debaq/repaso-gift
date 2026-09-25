<?php
require __DIR__ . '/inc/bootstrap.php';

$set = obtener_set(get_int('id'));
if (!$set || (!$set['visible'] && !admin())) redirigir('index.php');
$sid = (int)$set['id'];

// Al navegador solo se envía la lista de preguntas (id, tipo, subtema), nunca las respuestas.
$lista = [];
foreach (preguntas_set($sid) as $q) {
    $lista[] = ['id' => $q['id'], 'tipo' => $q['tipo'], 'cat' => $q['categoria'], 'etq' => etiqueta_tipo($q['tipo'], $q['multiple'] ?? false)];
}
$datos = [
    'id'       => $sid,
    'titulo'   => $set['titulo'],
    'version'  => (int)$set['version'],
    'mezclar'  => (bool)$set['mezclar_preguntas'],
    'tema_id'  => (int)$set['tema_id'],
    'preguntas' => $lista,
    'urls'     => ['api' => url('api.php'), 'set' => url('set.php?id=' . $sid), 'tema' => url('tema.php?id=' . (int)$set['tema_id']), 'progreso' => url('progreso.php')],
];

cabecera($set['titulo'], 'temas', 'publico', ['practica.js']);
?>
<script type="application/json" id="datos-set"><?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div id="app" class="intento">
  <p class="muted centro">Cargando…</p>
  <noscript><p class="flash error">Esta página necesita JavaScript activado.</p></noscript>
</div>
<?php pie();
