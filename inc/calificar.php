<?php
declare(strict_types=1);

/** Tipos que suman puntaje (ensayo y descripción no se califican automáticamente). */
function es_calificable(array $q): bool
{
    return !in_array($q['tipo'], ['essay', 'description'], true);
}

/**
 * Normaliza la respuesta que envía el navegador (JSON) según el tipo de pregunta.
 * null = sin responder ("No sé").
 */
function normalizar_respuesta(array $q, $r)
{
    if ($r === null) return null;
    switch ($q['tipo']) {
        case 'truefalse':
            return in_array($r, ['true', 'false'], true) ? $r : null;
        case 'multichoice':
            if ($q['multiple']) {
                return is_array($r) ? array_values(array_unique(array_map('intval', array_filter($r, 'is_scalar')))) : [];
            }
            return (is_scalar($r) && $r !== '') ? (int)$r : null;
        case 'matching':
            $out = [];
            if (is_array($r)) {
                foreach ($r as $i => $v) {
                    if (is_scalar($v) && $v !== '') $out[(string)(int)$i] = (int)$v;
                }
            }
            return $out;
        case 'description':
            return null;
        default: // shortanswer, numerical, essay
            return is_scalar($r) ? mb_substr(trim((string)$r), 0, 5000) : '';
    }
}

/** Lista de textos de la columna derecha (sin repetir) para emparejamiento. */
function opciones_derecha(array $q): array
{
    return array_values(array_unique(array_column($q['pares'], 'der')));
}

function normalizar_texto(string $s): string
{
    return preg_replace('/\s+/u', ' ', mb_strtolower(trim($s)));
}

function sin_tildes(string $s): string
{
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
}

function coincide_corta(string $resp, string $patron, bool $ignorar_tildes = false): bool
{
    $resp = normalizar_texto($resp);
    $patron = normalizar_texto($patron);
    if ($ignorar_tildes) {
        $resp = sin_tildes($resp);
        $patron = sin_tildes($patron);
    }
    if (strpos($patron, '*') === false) return $resp === $patron;
    $re = implode('.*', array_map(function ($p) { return preg_quote($p, '/'); }, explode('*', $patron)));
    return (bool)preg_match('/^' . $re . '$/u', $resp);
}

/**
 * Califica una respuesta.
 * @return array{fraccion: ?float, fb: string[], nota: string}
 *   fraccion null = no calificable; fb = retroalimentaciones (texto GIFT) a mostrar; nota = aviso extra (texto plano)
 */
function calificar(array $q, $r): array
{
    $out = ['fraccion' => 0.0, 'fb' => [], 'nota' => ''];
    switch ($q['tipo']) {
        case 'truefalse':
            if ($r === null) break;
            $ok = ($r === 'true') === $q['correcta'];
            $out['fraccion'] = $ok ? 1.0 : 0.0;
            $fb = $ok ? $q['fb_correcta'] : $q['fb_incorrecta'];
            if ($fb !== '') $out['fb'][] = $fb;
            break;

        case 'multichoice':
            if ($q['multiple']) {
                $suma = 0.0;
                foreach ((array)$r as $i) {
                    if (!isset($q['opciones'][$i])) continue;
                    $suma += $q['opciones'][$i]['fraccion'];
                    if ($q['opciones'][$i]['fb'] !== '') $out['fb'][] = $q['opciones'][$i]['fb'];
                }
                $out['fraccion'] = $suma;
            } elseif ($r !== null && isset($q['opciones'][$r])) {
                $out['fraccion'] = $q['opciones'][$r]['fraccion'];
                if ($q['opciones'][$r]['fb'] !== '') $out['fb'][] = $q['opciones'][$r]['fb'];
            }
            break;

        case 'shortanswer':
            if ($r === '' || $r === null) break;
            $mejor = null;
            foreach ([false, true] as $tildes) {
                foreach ($q['respuestas'] as $a) {
                    if (coincide_corta($r, $a['texto'], $tildes) && ($mejor === null || $a['fraccion'] > $mejor['fraccion'])) {
                        $mejor = $a;
                    }
                }
                if ($mejor !== null) {
                    if ($tildes && $mejor['fraccion'] > 0) $out['nota'] = 'Se aceptó tu respuesta, pero revisa las tildes: «' . $mejor['texto'] . '».';
                    break;
                }
            }
            if ($mejor) {
                $out['fraccion'] = $mejor['fraccion'];
                if ($mejor['fb'] !== '') $out['fb'][] = $mejor['fb'];
            }
            break;

        case 'numerical':
            if (!is_string($r) || !Gift::es_num($r)) {
                if ($r !== '' && $r !== null) $out['nota'] = 'La respuesta debe ser un número (puedes usar punto o coma decimal).';
                break;
            }
            $v = Gift::num($r);
            $mejor = null;
            foreach ($q['respuestas'] as $a) {
                if (abs($v - $a['valor']) <= $a['tol'] + 1e-9 && ($mejor === null || $a['fraccion'] > $mejor['fraccion'])) {
                    $mejor = $a;
                }
            }
            if ($mejor) {
                $out['fraccion'] = $mejor['fraccion'];
                if ($mejor['fb'] !== '') $out['fb'][] = $mejor['fb'];
            }
            break;

        case 'matching':
            $der = opciones_derecha($q);
            $total = 0;
            $bien = 0;
            foreach ($q['pares'] as $i => $p) {
                if ($p['izq'] === '') continue;
                $total++;
                $elegido = $r[(string)$i] ?? null;
                if ($elegido !== null && ($der[$elegido] ?? null) === $p['der']) $bien++;
            }
            $out['fraccion'] = $total ? $bien / $total : 0.0;
            break;

        case 'essay':
        case 'description':
            $out['fraccion'] = null;
            break;
    }
    if ($out['fraccion'] !== null) $out['fraccion'] = max(0.0, min(1.0, (float)$out['fraccion']));
    return $out;
}

/** Texto plano con la respuesta correcta, para mostrar en la revisión. */
function respuesta_correcta_texto(array $q): string
{
    switch ($q['tipo']) {
        case 'truefalse':
            return $q['correcta'] ? 'Verdadero' : 'Falso';
        case 'multichoice':
            $c = array_filter($q['opciones'], function ($o) { return $o['fraccion'] > 0; });
            usort($c, function ($a, $b) { return $b['fraccion'] <=> $a['fraccion']; });
            if (!$q['multiple']) $c = array_filter($c, function ($o) use ($c) { return $o['fraccion'] >= reset($c)['fraccion']; });
            return implode(' · ', array_column($c, 'texto'));
        case 'shortanswer':
            $c = array_filter($q['respuestas'], function ($a) { return $a['fraccion'] >= 1; });
            return implode(' / ', array_column($c ?: $q['respuestas'], 'texto'));
        case 'numerical':
            $c = array_filter($q['respuestas'], function ($a) { return $a['fraccion'] >= 1; });
            return implode(' / ', array_map(function ($a) {
                return $a['tol'] > 0 ? fmt_num($a['valor']) . ' ± ' . fmt_num($a['tol']) : fmt_num($a['valor']);
            }, $c ?: $q['respuestas']));
        case 'matching':
            $c = array_filter($q['pares'], function ($p) { return $p['izq'] !== ''; });
            return implode(' · ', array_map(function ($p) { return $p['izq'] . ' → ' . $p['der']; }, $c));
    }
    return '';
}

function fmt_num(float $n): string
{
    return rtrim(rtrim(number_format($n, 6, ',', ''), '0'), ',');
}
