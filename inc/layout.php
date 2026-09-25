<?php
declare(strict_types=1);

/**
 * Cabecera HTML. $zona = 'publico' (estudiantes) o 'admin'.
 * $scripts: JS extra de assets/ a cargar (ej: ['progreso.js']).
 */
function cabecera(string $titulo, string $activo = '', string $zona = 'publico', array $scripts = []): void
{
    $adm = admin();
    $sitio = (string)cfg('nombre_sitio', 'Repaso GIFT');
    $nav = $zona === 'admin'
        ? ['sets' => ['admin/index.php', 'Sets'], 'temas' => ['admin/temas.php', 'Temas'], 'sitio' => ['index.php', 'Ver sitio ↗']]
        : ['temas' => ['index.php', 'Temas'], 'progreso' => ['progreso.php', 'Mi progreso']];
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> · <?= h($sitio) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<script>window.GP_BASE = <?= json_encode(base_url()) ?>;</script>
<?php foreach (array_merge(['progreso.js'], $scripts, ['app.js']) as $js): ?>
<script src="<?= h(url('assets/' . $js)) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="zona-<?= h($zona) ?>">
<header class="barra">
  <a class="marca" href="<?= h(url($zona === 'admin' ? 'admin/index.php' : 'index.php')) ?>"><?= h($sitio) ?></a>
  <nav>
    <?php foreach ($nav as $k => [$href, $txt]): ?>
      <a href="<?= h(url($href)) ?>"<?= $k === $activo ? ' class="activo"' : '' ?>><?= h($txt) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($adm && $zona === 'admin'): ?>
    <div class="quien">
      <span><?= h($adm['nombre']) ?></span>
      <a href="<?= h(url('cuenta.php')) ?>">Cuenta</a>
      <form method="post" action="<?= h(url('logout.php')) ?>"><?= csrf_campo() ?><button class="link">Salir</button></form>
    </div>
  <?php elseif ($adm): ?>
    <div class="quien"><a href="<?= h(url('admin/index.php')) ?>">Panel docente</a></div>
  <?php else: ?>
    <div class="quien racha-mini" data-racha hidden></div>
  <?php endif; ?>
</header>
<main>
<?php foreach (tomar_flashes() as [$tipo, $msg]): ?>
  <div class="flash <?= h($tipo) ?>"><?= h($msg) ?></div>
<?php endforeach;
}

function pie(string $zona = 'publico'): void
{
    ?>
</main>
<?php if ($zona === 'publico'): ?>
<footer class="pie">
  <span>Tu progreso se guarda solo en este navegador.</span>
  <a href="<?= h(url('login.php')) ?>">Acceso docente</a>
</footer>
<?php endif; ?>
<div id="toasts" aria-live="polite"></div>
</body>
</html>
<?php
}

function barra_progreso(float $frac, string $clase = ''): string
{
    $p = max(0, min(100, (int)round($frac * 100)));
    return '<div class="progreso ' . h($clase) . '" title="' . $p . '%"><div style="width:' . $p . '%"></div></div>';
}
