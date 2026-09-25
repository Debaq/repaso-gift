<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        http_response_code(500);
        exit('Falta la extensión pdo_sqlite de PHP. Actívala en el servidor (php.ini: extension=pdo_sqlite).');
    }
    $ruta = (string)cfg('db');
    $dir = dirname($ruta);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_writable($dir)) {
        http_response_code(500);
        exit('La carpeta de datos no tiene permisos de escritura: ' . h($dir));
    }

    $pdo = new PDO('sqlite:' . $ruta, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrar($pdo);
    return $pdo;
}

function migrar(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 1) return;

    // Solo se guardan cuentas de administrador y el contenido (temas, sets, preguntas).
    // Nada del estudiante: su avance vive en el localStorage de su navegador.
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS usuarios (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    username  TEXT NOT NULL UNIQUE COLLATE NOCASE,
    nombre    TEXT NOT NULL,
    pass      TEXT NOT NULL,
    creado    INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS temas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre      TEXT NOT NULL,
    descripcion TEXT NOT NULL DEFAULT '',
    orden       INTEGER NOT NULL DEFAULT 0,
    visible     INTEGER NOT NULL DEFAULT 1,
    creado      INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS sets (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    tema_id             INTEGER REFERENCES temas(id) ON DELETE SET NULL,
    titulo              TEXT NOT NULL,
    descripcion         TEXT NOT NULL DEFAULT '',
    archivo             TEXT NOT NULL DEFAULT '',
    fuente              TEXT NOT NULL,
    visible             INTEGER NOT NULL DEFAULT 1,
    mezclar_preguntas   INTEGER NOT NULL DEFAULT 1,
    mezclar_respuestas  INTEGER NOT NULL DEFAULT 1,
    orden               INTEGER NOT NULL DEFAULT 0,
    version             INTEGER NOT NULL,
    creado              INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_sets_tema ON sets(tema_id, orden);
CREATE TABLE IF NOT EXISTS preguntas (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    set_id     INTEGER NOT NULL REFERENCES sets(id) ON DELETE CASCADE,
    pos        INTEGER NOT NULL,
    tipo       TEXT NOT NULL,
    categoria  TEXT NOT NULL DEFAULT '',
    datos      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_preguntas_set ON preguntas(set_id, pos);
CREATE TABLE IF NOT EXISTS stats_pregunta (
    pregunta_id INTEGER PRIMARY KEY REFERENCES preguntas(id) ON DELETE CASCADE,
    n           INTEGER NOT NULL DEFAULT 0,
    suma        REAL NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS fallos_login (
    ip TEXT NOT NULL,
    ts INTEGER NOT NULL
);
PRAGMA user_version = 1;
SQL);
}

function hay_admin(): bool
{
    return (bool)db()->query('SELECT 1 FROM usuarios LIMIT 1')->fetchColumn();
}

function obtener_set(int $id): ?array
{
    $st = db()->prepare('SELECT s.*, t.nombre AS tema FROM sets s LEFT JOIN temas t ON t.id = s.tema_id WHERE s.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function obtener_tema(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM temas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** [id => nombre] de todos los temas, en su orden. */
function temas_lista(): array
{
    return db()->query('SELECT id, nombre FROM temas ORDER BY orden, nombre COLLATE NOCASE')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Preguntas de un set indexadas por id, con 'datos' ya decodificado. */
function preguntas_set(int $set_id): array
{
    $st = db()->prepare('SELECT id, tipo, categoria, datos FROM preguntas WHERE set_id = ? ORDER BY pos');
    $st->execute([$set_id]);
    $out = [];
    foreach ($st as $f) {
        $q = json_decode($f['datos'], true);
        $q['id'] = (int)$f['id'];
        $out[(int)$f['id']] = $q;
    }
    return $out;
}

/** Inserta las preguntas de un set (dentro de la transacción del llamador). */
function insertar_preguntas(int $set_id, array $preguntas): void
{
    $ins = db()->prepare('INSERT INTO preguntas (set_id, pos, tipo, categoria, datos) VALUES (?, ?, ?, ?, ?)');
    foreach ($preguntas as $i => $q) {
        $ins->execute([$set_id, $i, $q['tipo'], $q['categoria'], json_encode($q, JSON_UNESCAPED_UNICODE)]);
    }
}

/** Ids de preguntas calificables por set visible: [set_id => [ids]]. Para calcular el progreso en el navegador. */
function ids_calificables(array $set_ids): array
{
    if (!$set_ids) return [];
    $marcas = implode(',', array_fill(0, count($set_ids), '?'));
    $st = db()->prepare("SELECT set_id, id FROM preguntas WHERE set_id IN ($marcas) AND tipo NOT IN ('essay', 'description') ORDER BY pos");
    $st->execute(array_values($set_ids));
    $out = array_fill_keys($set_ids, []);
    foreach ($st as $f) $out[(int)$f['set_id']][] = (int)$f['id'];
    return $out;
}
