<?php
/**
 * API sin estado para la práctica. No guarda nada del estudiante.
 *
 * POST JSON:
 *   {accion: "preguntas", set, ids: [..], semilla}         → {html: {id: "<html>"}}
 *   {accion: "calificar", set, id, semilla, r}              → {fraccion, calificable, html}
 *   {accion: "revisar",   set, semilla, items: [{id, r}]}   → {items: {id: {fraccion, calificable, html}}}
 */
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!es_post()) responder(['error' => 'Usa POST'], 405);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) responder(['error' => 'JSON inválido'], 400);

$set = obtener_set((int)($in['set'] ?? 0));
if (!$set || (!$set['visible'] && !admin())) responder(['error' => 'Set no disponible'], 404);
$preguntas = preguntas_set((int)$set['id']);
$semilla = (int)($in['semilla'] ?? 1);
$mezclar = (bool)$set['mezclar_respuestas'];

function semilla_pregunta(int $base, int $qid): int
{
    return ($base + $qid * 7919) % 2147483647;
}

function titulo_html(array $q): string
{
    return ($q['titulo'] !== '' && $q['titulo'] !== $q['texto']) ? '<h2 class="titulo-preg">' . h($q['titulo']) . '</h2>' : '';
}

/** Califica y devuelve la vista corregida de una pregunta. */
function corregir(array $q, $r, int $semilla, bool $mezclar): array
{
    $r = normalizar_respuesta($q, $r);
    $res = calificar($q, $r);
    return [
        'fraccion'    => $res['fraccion'],
        'calificable' => es_calificable($q),
        'html'        => titulo_html($q) . render_pregunta($q, [
            'semilla'   => semilla_pregunta($semilla, $q['id']),
            'mezclar'   => $mezclar,
            'resp'      => $r,
            'revisar'   => true,
            'resultado' => $res,
        ]),
    ];
}

switch ($in['accion'] ?? '') {
    case 'preguntas':
        $out = [];
        foreach (array_slice((array)($in['ids'] ?? []), 0, 500) as $id) {
            $q = $preguntas[(int)$id] ?? null;
            if (!$q) continue;
            $out[$q['id']] = titulo_html($q) . render_pregunta($q, ['semilla' => semilla_pregunta($semilla, $q['id']), 'mezclar' => $mezclar]);
        }
        responder(['html' => $out]);

    case 'calificar':
        $q = $preguntas[(int)($in['id'] ?? 0)] ?? null;
        if (!$q) responder(['error' => 'Pregunta no encontrada'], 404);
        $c = corregir($q, $in['r'] ?? null, $semilla, $mezclar);
        if (cfg('estadisticas_anonimas') && $c['fraccion'] !== null) {
            // Solo un contador por pregunta: sin IP, sin cookie, sin identificador
            db()->prepare('INSERT OR IGNORE INTO stats_pregunta (pregunta_id) VALUES (?)')->execute([$q['id']]);
            db()->prepare('UPDATE stats_pregunta SET n = n + 1, suma = suma + ? WHERE pregunta_id = ?')->execute([$c['fraccion'], $q['id']]);
        }
        responder($c);

    case 'revisar':
        $out = [];
        foreach (array_slice((array)($in['items'] ?? []), 0, 500) as $it) {
            $q = $preguntas[(int)($it['id'] ?? 0)] ?? null;
            if ($q) $out[$q['id']] = corregir($q, $it['r'] ?? null, $semilla, $mezclar);
        }
        responder(['items' => $out]);
}
responder(['error' => 'Acción desconocida'], 400);
