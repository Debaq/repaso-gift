<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$GLOBALS['CFG'] = require APP_ROOT . '/config.php';

date_default_timezone_set(cfg('zona_horaria', 'UTC'));
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gift.php';
require_once __DIR__ . '/calificar.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/layout.php';

session_name('giftprac');
// Buffer de salida: permite abrir la sesión (cookie) aunque ya se haya empezado a imprimir HTML
ob_start();

// ---------------------------------------------------------------- utilidades

function cfg(string $clave, $defecto = null)
{
    return $GLOBALS['CFG'][$clave] ?? $defecto;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Ruta base de la app en la URL (sin barra final), detectada desde el script actual. */
function base_url(): string
{
    static $base = null;
    if ($base !== null) return $base;
    if (cfg('base_url') !== null) return $base = rtrim((string)cfg('base_url'), '/');
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $raiz = realpath(APP_ROOT) ?: APP_ROOT;
    $rel = str_replace('\\', '/', substr($script, strlen($raiz)));
    $nombre = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = ($rel !== '' && substr($nombre, -strlen($rel)) === $rel) ? substr($nombre, 0, -strlen($rel)) : '';
    return $base = rtrim($base, '/');
}

function url(string $ruta = ''): string
{
    return base_url() . '/' . ltrim($ruta, '/');
}

function redirigir(string $ruta): void
{
    header('Location: ' . (preg_match('#^https?://#', $ruta) ? $ruta : url($ruta)));
    exit;
}

function es_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post_str(string $k): string
{
    return trim((string)($_POST[$k] ?? ''));
}

function get_int(string $k): int
{
    return (int)($_GET[$k] ?? 0);
}

function fecha($ts): string
{
    return $ts ? date('d-m-Y H:i', (int)$ts) : '—';
}

// ---------------------------------------------------------------- sesión (solo admin)
// Los estudiantes no inician sesión: la sesión se abre únicamente al entrar como admin,
// así a los visitantes no se les crea ninguna cookie.

function sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_url() . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function hay_sesion(): bool
{
    return session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[session_name()]);
}

function flash(string $msg, string $tipo = 'ok'): void
{
    sesion();
    $_SESSION['flash'][] = [$tipo, $msg];
}

function tomar_flashes(): array
{
    if (!hay_sesion()) return [];
    sesion();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    sesion();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_verificar(): void
{
    if (!es_post()) return;
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sesión expirada o formulario inválido. Vuelve atrás y recarga la página.');
    }
}

/** Admin conectado, o null. */
function admin(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    if (!hay_sesion()) return $u = null;
    sesion();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return $u = null;
    $st = db()->prepare('SELECT id, username, nombre FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    return $u = ($st->fetch() ?: null);
}

function requiere_admin(): array
{
    $u = admin();
    if (!$u) {
        flash('Inicia sesión para continuar.', 'aviso');
        redirigir('login.php');
    }
    csrf_verificar();
    return $u;
}

function iniciar_sesion(int $uid): void
{
    sesion();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
}

/** Bloqueo simple por IP tras muchos intentos fallidos de login. */
function login_bloqueado(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM fallos_login WHERE ip = ? AND ts > ?');
    $st->execute([$_SERVER['REMOTE_ADDR'] ?? '', time() - 600]);
    return (int)$st->fetchColumn() >= 8;
}

function registrar_fallo_login(): void
{
    db()->prepare('INSERT INTO fallos_login (ip, ts) VALUES (?, ?)')->execute([$_SERVER['REMOTE_ADDR'] ?? '', time()]);
    db()->prepare('DELETE FROM fallos_login WHERE ts < ?')->execute([time() - 86400]);
}
