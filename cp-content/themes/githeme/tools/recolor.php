<?php

/**
 * Githeme recolor generator.
 *
 * Reads the Default theme's compiled sheets (style.css, forum.css), resolves every colour a rule
 * declares (hex, rgb(), hsl(), var(), color-mix()), classifies it by ROLE (surface, text, border,
 * brand, status) and emits a sheet that points the same selectors at --gh-* tokens. The tokens
 * (dark and light) live in githeme.css, so one pass recolours every page of every module.
 *
 *   php tools/recolor.php            writes Resources/dist/githeme-recolor.css
 *
 * Re-run it whenever Default's style.css or forum.css changes.
 */

declare(strict_types=1);

/** Default's compiled sheets, then the inline <style> partials of modules that ship their own front-end CSS. */
const SOURCES = ['style.css', 'forum.css'];
const MODULE_STYLES = ['DnsTools', 'Messages', 'Showcase'];

$themes = dirname(__DIR__, 2);
$srcDir = $themes.'/cpalius-website/Resources/dist';
$out = dirname(__DIR__).'/Resources/dist/githeme-recolor.css';

// ---------------------------------------------------------------------------------- css parsing

function readSource(string $file): string
{
    $raw = (string) file_get_contents($file);
    if (str_ends_with($file, '.twig')) {
        $raw = (string) preg_replace('~\{\{.*?\}\}|\{%.*?%\}~s', '', $raw);
        $raw = (string) preg_replace('~</?style[^>]*>~i', '', $raw);
    }

    return str_replace("", '', $raw);
}

function stripComments(string $css): string
{
    return (string) preg_replace('~/\*.*?\*/~s', '', $css);
}

/** @return list<array{prelude: string, body: string}> top-level blocks of $css (at-rules keep their body) */
function blocks(string $css): array
{
    $result = [];
    $len = strlen($css);
    $i = 0;
    while ($i < $len) {
        $start = $i;
        $depthParen = 0;
        $quote = null;
        while ($i < $len) {
            $c = $css[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    ++$i;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                ++$depthParen;
            } elseif ($c === ')') {
                --$depthParen;
            } elseif ($depthParen === 0 && ($c === '{' || $c === ';')) {
                break;
            }
            ++$i;
        }
        $prelude = trim(substr($css, $start, $i - $start));
        if ($i >= $len) {
            break;
        }
        if ($css[$i] === ';') {
            ++$i; // @import / @charset
            continue;
        }
        // matching close brace
        $depth = 1;
        $j = $i + 1;
        $quote = null;
        while ($j < $len && $depth > 0) {
            $c = $css[$j];
            if ($quote !== null) {
                if ($c === '\\') {
                    ++$j;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '{') {
                ++$depth;
            } elseif ($c === '}') {
                --$depth;
            }
            ++$j;
        }
        $result[] = ['prelude' => $prelude, 'body' => substr($css, $i + 1, $j - $i - 2)];
        $i = $j;
    }

    return $result;
}

/** @return list<array{string, string, bool}> [prop, value, important] */
function declarations(string $body): array
{
    $decls = [];
    $len = strlen($body);
    $buf = '';
    $depth = 0;
    $quote = null;
    for ($i = 0; $i <= $len; ++$i) {
        $c = $i < $len ? $body[$i] : ';';
        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $buf .= $body[++$i];
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === '"' || $c === "'") {
            $quote = $c;
        } elseif ($c === '(') {
            ++$depth;
        } elseif ($c === ')') {
            --$depth;
        }
        if ($c === ';' && $depth === 0) {
            $pos = strpos($buf, ':');
            if ($pos !== false) {
                $prop = strtolower(trim(substr($buf, 0, $pos)));
                $value = trim(substr($buf, $pos + 1));
                $important = false;
                if (preg_match('/\s*!\s*important\s*$/i', $value) === 1) {
                    $important = true;
                    $value = trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value));
                }
                if ($prop !== '' && $value !== '') {
                    $decls[] = [$prop, $value, $important];
                }
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }

    return $decls;
}

// ---------------------------------------------------------------------------------- colour maths

/** @return array{float, float, float, float}|null r,g,b (0-255), alpha */
function parseColor(string $v): ?array
{
    $v = strtolower(trim($v));
    if (preg_match('/^#([0-9a-f]{3,8})$/', $v, $m) === 1) {
        $h = $m[1];
        if (strlen($h) === 3 || strlen($h) === 4) {
            $h = preg_replace('/(.)/', '$1$1', $h);
        }
        if (strlen($h) === 6 || strlen($h) === 8) {
            return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)), strlen($h) === 8 ? hexdec(substr($h, 6, 2)) / 255 : 1.0];
        }

        return null;
    }
    if (preg_match('/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,\/]\s*([\d.]+%?))?\s*\)$/', $v, $m) === 1) {
        $a = 1.0;
        if (isset($m[4]) && $m[4] !== '') {
            $a = str_ends_with($m[4], '%') ? (float) $m[4] / 100 : (float) $m[4];
        }

        return [(float) $m[1], (float) $m[2], (float) $m[3], $a];
    }
    if (preg_match('/^hsla?\(\s*([\d.]+)(?:deg)?[\s,]+([\d.]+)%[\s,]+([\d.]+)%(?:\s*[,\/]\s*([\d.]+%?))?\s*\)$/', $v, $m) === 1) {
        [$r, $g, $b] = hslToRgb((float) $m[1], (float) $m[2] / 100, (float) $m[3] / 100);
        $a = 1.0;
        if (isset($m[4]) && $m[4] !== '') {
            $a = str_ends_with($m[4], '%') ? (float) $m[4] / 100 : (float) $m[4];
        }

        return [$r, $g, $b, $a];
    }
    if ($v === 'white') {
        return [255, 255, 255, 1.0];
    }
    if ($v === 'black') {
        return [0, 0, 0, 1.0];
    }

    return null;
}

/** @return array{float, float, float} */
function hslToRgb(float $h, float $s, float $l): array
{
    $h = fmod($h, 360) / 360;
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $f = static function (float $t) use ($p, $q): float {
        $t = fmod($t + 1, 1);
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    };

    return [$f($h + 1 / 3) * 255, $f($h) * 255, $f($h - 1 / 3) * 255];
}

/** @return array{float, float, float} hue (0-360), saturation (0-1), lightness (0-1) */
function toHsl(float $r, float $g, float $b): array
{
    $r /= 255;
    $g /= 255;
    $b /= 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) {
        return [0.0, 0.0, $l];
    }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match (true) {
        $max === $r => fmod(($g - $b) / $d + 6, 6),
        $max === $g => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };

    return [$h * 60, $s, $l];
}

// ---------------------------------------------------------------------------------- var resolving

/** @var array<string, string> */
$vars = [];

function collectVars(string $css): void
{
    global $vars;
    if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;{}]+);/i', $css, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as $row) {
            $vars[$row[1]] ??= trim($row[2]);
        }
    }
}

/** Replaces var(--x[, fallback]) with its resolved text; leaves it when nothing resolves. */
function resolveVars(string $v, int $depth = 0): string
{
    global $vars;
    if ($depth > 6) {
        return $v;
    }
    $out = '';
    $len = strlen($v);
    $i = 0;
    while ($i < $len) {
        if (substr($v, $i, 4) === 'var(') {
            $d = 1;
            $j = $i + 4;
            while ($j < $len && $d > 0) {
                if ($v[$j] === '(') {
                    ++$d;
                } elseif ($v[$j] === ')') {
                    --$d;
                }
                ++$j;
            }
            $inner = substr($v, $i + 4, $j - $i - 5);
            $comma = strpos($inner, ',');
            $name = trim($comma === false ? $inner : substr($inner, 0, $comma));
            $fallback = $comma === false ? null : trim(substr($inner, $comma + 1));
            if (isset($vars[$name])) {
                $out .= resolveVars($vars[$name], $depth + 1);
            } elseif ($fallback !== null) {
                $out .= resolveVars($fallback, $depth + 1);
            } else {
                $out .= substr($v, $i, $j - $i);
            }
            $i = $j;
            continue;
        }
        $out .= $v[$i++];
    }

    return $out;
}

/** Evaluates color-mix(in srgb, A p%, B [q%]) when both stops are plain colours. */
function evalColorMix(string $v): ?array
{
    if (preg_match('/^color-mix\(\s*in\s+srgb\s*,\s*(.+?)\s*\)$/is', trim($v), $m) !== 1) {
        return null;
    }
    $parts = splitTop($m[1]);
    if (count($parts) !== 2) {
        return null;
    }
    $stops = [];
    foreach ($parts as $p) {
        if (preg_match('/^(.*?)(?:\s+([\d.]+)%)?$/s', trim($p), $mm) !== 1) {
            return null;
        }
        $col = parseColor(resolveVars($mm[1])) ?? evalColorMix(resolveVars($mm[1]));
        if ($col === null) {
            return null;
        }
        $stops[] = [$col, isset($mm[2]) && $mm[2] !== '' ? (float) $mm[2] / 100 : null];
    }
    [$a, $pa] = $stops[0];
    [$b, $pb] = $stops[1];
    if ($pa === null && $pb === null) {
        $pa = 0.5;
        $pb = 0.5;
    } elseif ($pa === null) {
        $pa = 1 - $pb;
    } elseif ($pb === null) {
        $pb = 1 - $pa;
    }
    $sum = $pa + $pb;
    if ($sum <= 0) {
        return null;
    }
    $pa /= $sum;
    $pb /= $sum;

    return [$a[0] * $pa + $b[0] * $pb, $a[1] * $pa + $b[1] * $pb, $a[2] * $pa + $b[2] * $pb, $a[3] * $pa + $b[3] * $pb];
}

/** @return list<string> */
function splitTop(string $s, string $sep = ','): array
{
    $parts = [];
    $depth = 0;
    $buf = '';
    for ($i = 0, $n = strlen($s); $i < $n; ++$i) {
        $c = $s[$i];
        if ($c === '(') {
            ++$depth;
        } elseif ($c === ')') {
            --$depth;
        }
        if ($c === $sep && $depth === 0) {
            $parts[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $parts[] = $buf;

    return $parts;
}

// ---------------------------------------------------------------------------------- classification

/** @param array{float, float, float, float} $c */
function mapColor(array $c, string $role): ?string
{
    [$r, $g, $b, $a] = $c;
    if ($a < 0.02) {
        return null;
    }
    [$h, $s, $l] = toHsl($r, $g, $b);

    // Dark navy and blue-greys are this theme's "ink" and "bar" colours, not brand hues.
    $chromatic = $l < 0.3 ? false : ($l >= 0.88 ? $s >= 0.35 : $s >= 0.28);

    // Translucent colours: white/black overlays work on any ground; surfaces and tints become token mixes.
    if ($a < 0.98) {
        $pct = max(6, min(100, (int) round($a * 100)));
        if (!$chromatic) {
            if ($role !== 'bg') {
                return null;
            }
            if ($l >= 0.9) {
                return $a >= 0.7 ? "color-mix(in srgb, var(--gh-surface) {$pct}%, transparent)" : null;
            }
            if ($l < 0.3 && $s >= 0.14) {
                return "color-mix(in srgb, var(--gh-bar) {$pct}%, transparent)";
            }

            return null;
        }
        $token = chromaToken($h, $role === 'bg' ? 'tint' : $role);
        if ($token === null) {
            return null;
        }

        return "color-mix(in srgb, var($token) ".min($pct, 60)."%, transparent)";
    }

    if (!$chromatic) {
        return neutralToken($l, $role);
    }
    $token = chromaToken($h, $role, $l);

    return $token !== null ? "var($token)" : neutralToken($l, $role);
}

function neutralToken(float $l, string $role): ?string
{
    switch ($role) {
        case 'bg':
            return 'var('.match (true) {
                $l >= 0.985 => '--gh-surface',
                $l >= 0.93 => '--gh-subtle',
                $l >= 0.85 => '--gh-surface-2',
                $l >= 0.6 => '--gh-surface-3',
                $l >= 0.35 => '--gh-border-strong',
                $l >= 0.14 => '--gh-bar-2',
                default => '--gh-bar',
            }.')';
        case 'text':
            return 'var('.match (true) {
                $l >= 0.97 => '--gh-on-bar-strong',
                $l >= 0.5 => '--gh-on-bar',
                $l >= 0.3 => '--gh-muted',
                $l >= 0.2 => '--gh-fg',
                default => '--gh-fg-strong',
            }.')';
        case 'border':
            return 'var('.($l >= 0.8 ? '--gh-border' : '--gh-border-strong').')';
        default:
            return null;
    }
}

/** Token for a coloured (saturated) value. Hue buckets: blue brand, green, red, amber/gold. */
function chromaToken(float $h, string $role, float $l = 0.5): ?string
{
    $soft = $l >= 0.82;
    $family = match (true) {
        $h >= 175 && $h < 262 => 'blue',
        $h >= 80 && $h < 175 => 'green',
        $h >= 345 || $h < 14 => 'red',
        $h >= 14 && $h < 70 => 'amber',
        default => null,
    };
    if ($family === null) {
        return null;
    }

    return match ($role) {
        'bg' => match ($family) {
            'blue' => $soft ? '--gh-info-bg' : '--gh-primary',
            'green' => $soft ? '--gh-ok-bg' : '--gh-primary',
            'red' => $soft ? '--gh-danger-bg' : '--gh-danger-solid',
            'amber' => $soft ? '--gh-warn-bg' : '--gh-warn-solid',
        },
        'tint' => match ($family) {
            'blue' => '--gh-link',
            'green' => '--gh-ok',
            'red' => '--gh-danger',
            'amber' => '--gh-warn',
        },
        'text', 'border' => match ($family) {
            'blue' => '--gh-link',
            'green' => '--gh-ok',
            'red' => '--gh-danger',
            'amber' => $h < 50 ? '--gh-accent' : '--gh-warn',
        },
        default => null,
    };
}

/** Index just past the ')' that closes the first '(' at or after $from. */
function balancedEnd(string $v, int $from): int
{
    $d = 0;
    $n = strlen($v);
    for ($j = (int) strpos($v, '(', $from); $j < $n; ++$j) {
        if ($v[$j] === '(') {
            ++$d;
        } elseif ($v[$j] === ')' && --$d === 0) {
            return $j + 1;
        }
    }

    return $n;
}

/** Rewrites every colour inside $value for $role; returns null when nothing changed. */
function rewriteColors(string $value, string $role): ?string
{
    $changed = false;
    $out = '';
    $len = strlen($value);
    $i = 0;
    while ($i < $len) {
        $c = $value[$i];
        // quoted strings and url() pass through
        if ($c === '"' || $c === "'") {
            $j = $i + 1;
            while ($j < $len && $value[$j] !== $c) {
                if ($value[$j] === '\\') {
                    ++$j;
                }
                ++$j;
            }
            $out .= substr($value, $i, $j - $i + 1);
            $i = $j + 1;
            continue;
        }
        if (preg_match('/\G(url|image-set|attr)\(/i', $value, $mm, 0, $i) === 1) {
            $j = balancedEnd($value, $i);
            $out .= substr($value, $i, $j - $i);
            $i = $j;
            continue;
        }
        // functional colours
        if (preg_match('/\G(var|color-mix|rgba?|hsla?)\(/i', $value, $mm, 0, $i) === 1) {
            $j = balancedEnd($value, $i);
            $token = substr($value, $i, $j - $i);
            $resolved = resolveVars($token);
            $col = parseColor($resolved) ?? evalColorMix($resolved);
            // Already a Githeme token or an unresolvable var: leave it.
            $mapped = ($col !== null && !str_contains($token, '--gh-')) ? mapColor($col, $role) : null;
            if ($mapped !== null) {
                $out .= $mapped;
                $changed = true;
            } else {
                $out .= $token;
            }
            $i = $j;
            continue;
        }
        if ($c === '#' && preg_match('/\G#[0-9a-fA-F]{3,8}\b/', $value, $mm, 0, $i) === 1) {
            $col = parseColor($mm[0]);
            $mapped = $col !== null ? mapColor($col, $role) : null;
            if ($mapped !== null) {
                $out .= $mapped;
                $changed = true;
            } else {
                $out .= $mm[0];
            }
            $i += strlen($mm[0]);
            continue;
        }
        if (preg_match('/\G(white|black)\b/i', $value, $mm, 0, $i) === 1 && ($i === 0 || !preg_match('/[a-z-]/i', $value[$i - 1]))) {
            $mapped = mapColor(parseColor($mm[1]) ?? [0, 0, 0, 1.0], $role);
            if ($mapped !== null) {
                $out .= $mapped;
                $changed = true;
            } else {
                $out .= $mm[0];
            }
            $i += strlen($mm[0]);
            continue;
        }
        $out .= $c;
        ++$i;
    }

    return $changed ? $out : null;
}

/** A gradient becomes one flat token: the first fully opaque colour in it (translucent glows are dropped). */
function firstColorRole(string $gradient): ?string
{
    $pattern = '/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)|color-mix\((?:[^()]|\([^()]*\))*\)|var\(--[a-z0-9-]+(?:\s*,[^)]*)?\)/i';
    if (preg_match_all($pattern, $gradient, $m) < 1) {
        return null;
    }
    foreach ($m[0] as $token) {
        $resolved = resolveVars($token);
        $col = parseColor($resolved) ?? evalColorMix($resolved);
        if ($col === null || $col[3] < 0.98) {
            continue;
        }

        return mapColor($col, 'bg');
    }

    return 'transparent';
}

/** @return string|null new value (without !important) or null to keep */
function mapDeclaration(string $prop, string $value): ?string
{
    if (str_starts_with($prop, '--')) {
        return null;
    }
    if ($prop === 'box-shadow') {
        if (stripos($value, 'inset') !== false || $value === 'none' || str_contains($value, 'var(--gh-')) {
            return null;
        }
        // Depth comes from borders in this theme; a lifted card becomes a 1px ring + soft shadow token.
        return 'var(--gh-shadow)';
    }
    if ($prop === 'text-shadow') {
        return $value === 'none' ? null : 'none';
    }
    if ($prop === 'filter' || $prop === 'backdrop-filter') {
        return null;
    }
    if (preg_match('/^border(-top|-right|-bottom|-left)?-radius$/', $prop) === 1 || $prop === 'border-radius') {
        return mapRadius($value);
    }
    if ($prop === 'background' || $prop === 'background-image') {
        if (preg_match('/^(repeating-)?(linear|radial|conic)-gradient\(/i', $value) === 1 && strlen(trim($value)) > 0 && substr_count($value, 'url(') === 0) {
            // A gradient is one flat token here.
            $first = firstColorRole($value);
            if ($first === null) {
                return null;
            }

            return $prop === 'background-image' ? 'none' : $first;
        }
        if (stripos($value, 'gradient(') !== false) {
            return null;
        }

        return rewriteColors($value, 'bg');
    }
    if ($prop === 'background-color') {
        return rewriteColors($value, 'bg');
    }
    if ($prop === 'color' || $prop === 'fill' || $prop === 'stroke' || $prop === 'caret-color' || $prop === 'text-decoration-color' || $prop === 'column-rule-color') {
        return rewriteColors($value, 'text');
    }
    if ($prop === 'accent-color') {
        return rewriteColors($value, 'text');
    }
    if (preg_match('/^(border|outline)(-top|-right|-bottom|-left|-inline|-block)*(-color)?$/', $prop) === 1) {
        return rewriteColors($value, 'border');
    }

    return null;
}

function mapRadius(string $value): ?string
{
    if (str_contains($value, '%') || str_contains($value, 'var(')) {
        return null;
    }
    $changed = false;
    $new = preg_replace_callback('/(?<![\w.-])(\d*\.?\d+)(px|rem|em)\b/', static function (array $m) use (&$changed): string {
        $px = (float) $m[1] * ($m[2] === 'px' ? 1 : 16);
        if ($px > 6) {
            $changed = true;

            return 'var(--gh-radius)';
        }

        return $m[0];
    }, $value);

    return $changed ? (string) $new : null;
}

// ---------------------------------------------------------------------------------- emit

function emit(array $tree, string $indent = ''): string
{
    $css = '';
    foreach ($tree as $node) {
        if (isset($node['children'])) {
            $inner = emit($node['children'], $indent.'  ');
            if ($inner !== '') {
                $css .= $indent.$node['prelude']." {\n".$inner.$indent."}\n";
            }
            continue;
        }
        if ($node['decls'] === []) {
            continue;
        }
        $css .= $indent.$node['prelude']." {\n";
        foreach ($node['decls'] as [$p, $v, $imp]) {
            $css .= $indent.'  '.$p.': '.$v.($imp ? ' !important' : '').";\n";
        }
        $css .= $indent."}\n";
    }

    return $css;
}

function process(string $css): array
{
    $tree = [];
    foreach (blocks($css) as $b) {
        $prelude = (string) preg_replace('/\s+/', ' ', $b['prelude']);
        if ($prelude === '') {
            continue;
        }
        if ($prelude[0] === '@') {
            if (preg_match('/^@(media|supports|layer|container)\b/i', $prelude) === 1) {
                $children = process($b['body']);
                if ($children !== []) {
                    $tree[] = ['prelude' => $prelude, 'children' => $children];
                }
            }
            continue; // @keyframes, @font-face, @page: untouched
        }
        if (preg_match('/^:root\b|^html\b$/', $prelude) === 1) {
            continue;
        }
        $decls = [];
        foreach (declarations($b['body']) as [$prop, $value, $important]) {
            $new = mapDeclaration($prop, $value);
            if ($new !== null && $new !== $value) {
                $decls[] = [$prop, $new, $important];
            }
        }
        if ($decls !== []) {
            $tree[] = ['prelude' => $prelude, 'decls' => $decls];
        }
    }

    return $tree;
}

$files = [];
foreach (SOURCES as $name) {
    $files[$name] = $srcDir.'/'.$name;
}
foreach (MODULE_STYLES as $module) {
    $files[$module.' (module)'] = dirname($themes).'/modules/'.$module.'/Resources/views/front/partials/_styles.html.twig';
}

$sheets = [];
foreach ($files as $label => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing $file\n");
        exit(1);
    }
    $sheets[$label] = stripComments(readSource($file));
    collectVars($sheets[$label]);
}

$sheetOut = "/* Githeme: generated by tools/recolor.php from Default's style.css and forum.css and the module style partials. Do not edit by hand. */\n";
foreach ($sheets as $label => $css) {
    $sheetOut .= "\n/* ---- from $label ---- */\n".emit(process($css));
}
file_put_contents($out, $sheetOut);
printf("wrote %s (%d bytes, %d rules)\n", $out, strlen($sheetOut), substr_count($sheetOut, " {\n"));
