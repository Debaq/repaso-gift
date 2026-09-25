<?php
require __DIR__ . '/inc/bootstrap.php';

if (!hay_admin()) redirigir('instalar.php');

// Temas visibles con sus sets visibles
$temas = db()->query('SELECT id, nombre, descripcion FROM temas WHERE visible = 1 ORDER BY orden, nombre COLLATE NOCASE')->fetchAll();
$sets = db()->query("SELECT s.id, s.tema_id, s.titulo,
        (SELECT COUNT(*) FROM preguntas p WHERE p.set_id = s.id AND p.tipo <> 'description') AS n_preg
    FROM sets s WHERE s.visible = 1 ORDER BY s.orden, s.titulo COLLATE NOCASE")->fetchAll();
$porTema = [];
foreach ($sets as $s) $porTema[(int)$s['tema_id']][] = $s;
$ids = ids_calificables(array_map('intval', array_column($sets, 'id')));
// Los sets sin tema van al final como "Otros"
if (!empty($porTema[0])) $temas[] = ['id' => 0, 'nombre' => 'Otros', 'descripcion' => 'Sets sin tema'];

cabecera('Temas', 'temas');
?>
<section class="portada">
  <h1>¿Qué quieres practicar hoy?</h1>
  <p class="muted">Elige un tema. No necesitas cuenta: tu avance y tus logros se guardan en este navegador.</p>
  <div data-continuar></div>
</section>

<?php if (!$sets): ?>
  <p class="tarjeta muted">Todavía no hay temas publicados. Vuelve más tarde.</p>
<?php endif; ?>

<div class="tarjetas">
<?php foreach ($temas as $t): $lista = $porTema[(int)$t['id']] ?? []; if (!$lista) continue;
    $idsTema = [];
    foreach ($lista as $s) $idsTema[$s['id']] = $ids[(int)$s['id']];
?>
  <a class="tarjeta tema" href="<?= h(url('tema.php?id=' . $t['id'])) ?>" data-progreso-tema='<?= h(json_encode($idsTema)) ?>'>
    <h2><?= h($t['nombre']) ?></h2>
    <?php if ($t['descripcion'] !== ''): ?><p class="muted"><?= h($t['descripcion']) ?></p><?php endif; ?>
    <p class="small"><?= count($lista) ?> set<?= count($lista) > 1 ? 's' : '' ?> · <?= array_sum(array_column($lista, 'n_preg')) ?> preguntas</p>
    <div class="dominio" data-barra></div>
  </a>
<?php endforeach; ?>
</div>
<?php pie();
