<?php
/**
 * Self-hosted visitor counter badge.
 *
 * Renders a shields-style SVG badge and increments a per-page counter
 * stored in a JSON file next to this script. No database needed.
 *
 * Usage (Markdown):
 *   ![](https://your-host.example/counter/counter.php?page=profile&label=Profile%20views)
 *
 * Query params:
 *   page  - counter bucket, [a-zA-Z0-9_-] only (default: profile)
 *   label - badge label text, max 40 chars   (default: Profile views)
 *   color - hex color for the count side     (default: 2ea44f)
 */

$dataFile = __DIR__ . '/counter_data.json';

$page  = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['page'] ?? 'profile');
if ($page === '') { $page = 'profile'; }
$label = substr(trim($_GET['label'] ?? 'Profile views'), 0, 40);
if ($label === '') { $label = 'Profile views'; }
$color = preg_match('/^[0-9a-fA-F]{6}$/', $_GET['color'] ?? '') ? $_GET['color'] : '2ea44f';

// Atomic read-increment-write under an exclusive lock.
$fp = fopen($dataFile, 'c+');
if ($fp === false) {
    http_response_code(500);
    exit('cannot open counter storage');
}
flock($fp, LOCK_EX);
$raw = stream_get_contents($fp);
// Tolerate a UTF-8 BOM and stray whitespace from hand edits.
$clean = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw));
$data  = $clean !== '' ? json_decode($clean, true) : [];
if (!is_array($data)) {
    // Never wipe existing data on a parse failure — refuse to write instead.
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(500);
    header('Content-Type: text/plain');
    exit("counter_data.json is not valid JSON; fix it by hand, e.g.: {\"profile\": 1500}");
}
$data[$page] = ($data[$page] ?? 0) + 1;
rewind($fp);
ftruncate($fp, 0);
fwrite($fp, json_encode($data));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

$count = number_format($data[$page]);

// Approximate text widths for the default badge font (11px Verdana-ish).
$labelW = (int) ceil(strlen($label) * 6.5) + 12;
$countW = (int) ceil(strlen($count) * 7.0) + 12;
$totalW = $labelW + $countW;

$labelX = $labelW / 2;
$countX = $labelW + $countW / 2;

$labelEsc = htmlspecialchars($label, ENT_QUOTES | ENT_XML1);

// no-cache headers so GitHub's image proxy (camo) re-fetches every view
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$totalW}" height="20" role="img" aria-label="{$labelEsc}: {$count}">
  <linearGradient id="s" x2="0" y2="100%">
    <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>
    <stop offset="1" stop-opacity=".1"/>
  </linearGradient>
  <clipPath id="r"><rect width="{$totalW}" height="20" rx="3" fill="#fff"/></clipPath>
  <g clip-path="url(#r)">
    <rect width="{$labelW}" height="20" fill="#555"/>
    <rect x="{$labelW}" width="{$countW}" height="20" fill="#{$color}"/>
    <rect width="{$totalW}" height="20" fill="url(#s)"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">
    <text x="{$labelX}" y="14" fill="#010101" fill-opacity=".3">{$labelEsc}</text>
    <text x="{$labelX}" y="13">{$labelEsc}</text>
    <text x="{$countX}" y="14" fill="#010101" fill-opacity=".3">{$count}</text>
    <text x="{$countX}" y="13">{$count}</text>
  </g>
</svg>
SVG;
