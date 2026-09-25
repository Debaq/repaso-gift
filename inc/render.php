<?php
declare(strict_types=1);

const ETIQUETAS_PERMITIDAS = '<b><strong><i><em><u><s><sub><sup><br><p><div><span><ul><ol><li><pre><code><blockquote><table><thead><tbody><tr><th><td><img><a><h3><h4><h5><hr><small><mark>';

/** Limpia HTML escrito en el GIFT: solo etiquetas seguras, sin eventos on* ni javascript:. */
function limpiar_html(string $html): string
{
    $html = strip_tags($html, ETIQUETAS_PERMITIDAS);
    $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/(href|src)\s*=\s*(["\']?)\s*(javascript|vbscript|data):[^"\'>\s]*\2/i', '$1="#"', $html);
    return $html;
}

/** Markdown mínimo: bloques ``` , `código`, **negrita**, *cursiva*, listas con - y saltos de línea. */
function markdown_basico(string $t): string
{
    $bloques = [];
    $t = preg_replace_callback('/```(?:\w+)?\n?(.*?)```/s', function ($m) use (&$bloques) {
        $bloques[] = '<pre><code>' . h(trim($m[1], "\n")) . '</code></pre>';
        return "\u{E100}" . (count($bloques) - 1) . "\u{E101}";
    }, $t);
    $t = h($t);
    $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $t);
    $t = preg_replace('/^[-*] (.+)$/m', '• $1', $t);
    $t = nl2br($t, false);
    return preg_replace_callback("/\u{E100}(\d+)\u{E101}/u", function ($m) use ($bloques) { return $bloques[(int)$m[1]]; }, $t);
}

/** Convierte texto GIFT a HTML según su formato. */
function fmt(string $t, string $formato = 'moodle'): string
{
    switch ($formato) {
        case 'plain':
            return nl2br(h($t), false);
        case 'markdown':
            return markdown_basico($t);
        case 'html':
            return limpiar_html($t);
        default: // moodle: HTML permitido + saltos de línea
            return nl2br(limpiar_html($t), false);
    }
}

/** Permutación determinista de 0..n-1 (misma en cada recarga del mismo intento). */
function permutacion(int $n, int $semilla, bool $mezclar = true): array
{
    $orden = range(0, max(0, $n - 1));
    if ($n === 0) return [];
    if (!$mezclar) return $orden;
    mt_srand($semilla);
    shuffle($orden);
    mt_srand();
    return $orden;
}

function texto_enunciado(array $q, string $hueco = '<span class="hueco">______</span>'): string
{
    $html = fmt($q['texto'], $q['formato']);
    if ($q['texto_post'] !== '') $html .= ' ' . $hueco . ' ' . fmt($q['texto_post'], $q['formato']);
    return $html;
}

function etiqueta_tipo(string $tipo, bool $multiple = false): string
{
    $t = [
        'multichoice' => $multiple ? 'Selección múltiple (varias correctas)' : 'Selección única',
        'truefalse'   => 'Verdadero / Falso',
        'shortanswer' => 'Respuesta corta',
        'numerical'   => 'Numérica',
        'matching'    => 'Emparejamiento',
        'essay'       => 'Desarrollo',
        'description' => 'Lectura',
    ];
    return $t[$tipo] ?? $tipo;
}

function clase_fraccion(?float $f): string
{
    if ($f === null) return 'neutra';
    if ($f >= 0.999) return 'bien';
    if ($f > 0) return 'parcial';
    return 'mal';
}

/**
 * Dibuja una pregunta.
 * $o = [
 *   'semilla'   => int,       // para mezclar opciones de forma estable
 *   'mezclar'   => bool,
 *   'resp'      => mixed,     // respuesta del estudiante (null si aún no responde)
 *   'revisar'   => bool,      // muestra corrección y retroalimentación
 *   'solucion'  => bool,      // vista admin: marca las correctas sin respuesta del estudiante
 *   'resultado' => array|null // salida de calificar()
 * ]
 */
function render_pregunta(array $q, array $o = []): string
{
    $o += ['semilla' => 1, 'mezclar' => true, 'resp' => null, 'revisar' => false, 'solucion' => false, 'resultado' => null];
    $bloqueado = $o['revisar'] || $o['solucion'];
    $dis = $bloqueado ? ' disabled' : '';
    $r = $o['resp'];
    $f = $q['formato'];
    $html = '';

    $inline = in_array($q['tipo'], ['shortanswer', 'numerical'], true) && $q['texto_post'] !== '';
    if ($inline) {
        $valor = $o['solucion'] ? respuesta_correcta_texto($q) : (string)$r;
        $campo = '<input type="text" name="r" class="inline" autocomplete="off" value="' . h($valor) . '"' . $dis . ($bloqueado ? '' : ' autofocus') . '>';
        $html .= '<div class="enunciado">' . fmt($q['texto'], $f) . ' ' . $campo . ' ' . fmt($q['texto_post'], $f) . '</div>';
    } else {
        $html .= '<div class="enunciado">' . texto_enunciado($q) . '</div>';
    }

    switch ($q['tipo']) {
        case 'truefalse':
            $html .= '<div class="opciones">';
            foreach (['true' => 'Verdadero', 'false' => 'Falso'] as $v => $lbl) {
                $esCorrecta = ($v === 'true') === $q['correcta'];
                $marcada = $r === $v;
                $cls = 'opcion';
                if ($o['revisar'] || $o['solucion']) $cls .= $esCorrecta ? ' correcta' : ($marcada ? ' incorrecta' : '');
                $html .= '<label class="' . $cls . '"><input type="radio" name="r" value="' . $v . '"' . ($marcada ? ' checked' : '') . $dis . ' required> ' . $lbl . '</label>';
            }
            $html .= '</div>';
            break;

        case 'multichoice':
            $html .= '<div class="opciones">';
            $letras = range('a', 'z');
            foreach (permutacion(count($q['opciones']), $o['semilla'], $o['mezclar']) as $n => $i) {
                $op = $q['opciones'][$i];
                $marcada = $q['multiple'] ? in_array($i, (array)$r, true) : $r === $i;
                $cls = 'opcion';
                if ($o['revisar'] || $o['solucion']) {
                    if ($op['fraccion'] > 0) $cls .= $op['fraccion'] >= 1 || $q['multiple'] ? ' correcta' : ' parcial';
                    elseif ($marcada) $cls .= ' incorrecta';
                }
                $tipo = $q['multiple'] ? 'checkbox' : 'radio';
                $nombre = $q['multiple'] ? 'r[]' : 'r';
                $html .= '<label class="' . $cls . '"><input type="' . $tipo . '" name="' . $nombre . '" value="' . $i . '"' . ($marcada ? ' checked' : '') . $dis . ($q['multiple'] ? '' : ' required') . '>'
                    . '<span class="letra">' . ($letras[$n] ?? '') . ')</span> <span class="txt">' . fmt($op['texto'], $f) . '</span>';
                if (($o['revisar'] && $marcada || $o['solucion']) && $op['fb'] !== '') {
                    $html .= '<span class="fb-op">' . fmt($op['fb'], $f) . '</span>';
                }
                if ($o['solucion'] && $op['fraccion'] != 0 && $op['fraccion'] != 1) {
                    $html .= ' <span class="peso">' . round($op['fraccion'] * 100) . '%</span>';
                }
                $html .= '</label>';
            }
            $html .= '</div>';
            if ($q['multiple'] && !$bloqueado) $html .= '<p class="ayuda">Puedes marcar más de una opción.</p>';
            break;

        case 'shortanswer':
        case 'numerical':
            if (!$inline) {
                $valor = $o['solucion'] ? respuesta_correcta_texto($q) : (string)$r;
                $modo = $q['tipo'] === 'numerical' ? ' inputmode="decimal"' : '';
                $html .= '<input type="text" name="r" class="campo" autocomplete="off" placeholder="Tu respuesta" value="' . h($valor) . '"' . $modo . $dis . ($bloqueado ? '' : ' autofocus') . '>';
            }
            break;

        case 'matching':
            $der = opciones_derecha($q);
            $ordenDer = permutacion(count($der), $o['semilla'] + 1, true);
            $html .= '<table class="emparejar">';
            foreach (permutacion(count($q['pares']), $o['semilla'], $o['mezclar']) as $i) {
                $p = $q['pares'][$i];
                if ($p['izq'] === '') continue;
                $elegido = is_array($r) ? ($r[(string)$i] ?? null) : null;
                $cls = '';
                if ($o['revisar']) $cls = ($elegido !== null && ($der[$elegido] ?? null) === $p['der']) ? 'correcta' : 'incorrecta';
                $html .= '<tr class="' . $cls . '"><td>' . fmt($p['izq'], $f) . '</td><td>';
                if ($o['solucion']) {
                    $html .= fmt($p['der'], $f);
                } else {
                    $html .= '<select name="r[' . $i . ']"' . $dis . ' required><option value="">— elegir —</option>';
                    foreach ($ordenDer as $j) {
                        $html .= '<option value="' . $j . '"' . ($elegido === $j ? ' selected' : '') . '>' . h(strip_tags($der[$j])) . '</option>';
                    }
                    $html .= '</select>';
                    if ($o['revisar'] && $cls === 'incorrecta') $html .= '<div class="fb-op">Correcta: ' . fmt($p['der'], $f) . '</div>';
                }
                $html .= '</td></tr>';
            }
            $html .= '</table>';
            break;

        case 'essay':
            if (!$o['solucion']) {
                $html .= '<textarea name="r" rows="6" class="campo" placeholder="Escribe tu respuesta"' . $dis . '>' . h((string)$r) . '</textarea>';
            }
            break;
    }

    if ($o['revisar'] && $o['resultado'] !== null) {
        $res = $o['resultado'];
        $html .= '<div class="retro ' . clase_fraccion($res['fraccion']) . '">';
        if ($res['fraccion'] === null) {
            if ($q['tipo'] === 'essay') $html .= '<strong>Pregunta de desarrollo:</strong> compara tu respuesta con la guía.';
        } elseif ($res['fraccion'] >= 0.999) {
            $html .= '<strong>¡Correcto!</strong>';
        } elseif ($res['fraccion'] > 0) {
            $html .= '<strong>Parcialmente correcto</strong> (' . round($res['fraccion'] * 100) . '%)';
        } else {
            $html .= '<strong>' . ($r === null || $r === '' || $r === [] ? 'Sin responder' : 'Incorrecto') . '</strong>';
        }
        if ($res['fraccion'] !== null && $res['fraccion'] < 0.999 && ($c = respuesta_correcta_texto($q)) !== '' && in_array($q['tipo'], ['shortanswer', 'numerical'], true)) {
            $html .= '<div class="correcta-txt">Respuesta correcta: ' . fmt($c, $f) . '</div>';
        }
        if ($res['nota'] !== '') $html .= '<div class="nota">' . h($res['nota']) . '</div>';
        foreach ($res['fb'] as $fb) {
            if ($q['tipo'] !== 'multichoice') $html .= '<div class="fb">' . fmt($fb, $f) . '</div>';
        }
        if ($q['fb_general'] !== '') $html .= '<div class="fb-general">' . fmt($q['fb_general'], $f) . '</div>';
        $html .= '</div>';
    } elseif ($o['solucion'] && ($q['fb_general'] !== '' || $q['tipo'] === 'truefalse')) {
        $html .= '<div class="retro neutra">';
        if ($q['tipo'] === 'truefalse') {
            if ($q['fb_correcta'] !== '') $html .= '<div class="fb">✔ ' . fmt($q['fb_correcta'], $f) . '</div>';
            if ($q['fb_incorrecta'] !== '') $html .= '<div class="fb">✘ ' . fmt($q['fb_incorrecta'], $f) . '</div>';
        }
        if ($q['fb_general'] !== '') $html .= '<div class="fb-general">' . fmt($q['fb_general'], $f) . '</div>';
        $html .= '</div>';
    }
    return $html;
}
