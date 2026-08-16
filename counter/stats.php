<?php
/**
 * Self-hosted GitHub stats cards (SVG).
 *
 * Fetches the account's repo list via the GitHub API (token from config.php),
 * caches the computed summary for an hour, and renders one of two cards.
 * If the API is unreachable or the token has expired, the last cached
 * summary is served no matter how old — the badge never breaks.
 *
 * Usage (Markdown):
 *   ![](https://your-host.example/stats.php?card=stats)
 *   ![](https://your-host.example/stats.php?card=langs)
 *
 * Setup: copy config.sample.php to config.php and paste a fine-grained PAT
 * with Metadata: Read-only on all repositories. Nothing else is required.
 */

$cacheFile = __DIR__ . '/stats_cache.json';
$cacheTtl  = 3600;

$cards = ['stats', 'langs', 'trophies', 'top'];
$card  = in_array($_GET['card'] ?? 'stats', $cards, true) ? $_GET['card'] : 'stats';

/* ---------- data ---------- */

function github_get(string $url, string $token)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: self-hosted-stats-card',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }
    return json_decode($body, true);
}

function fetch_summary(string $token): ?array
{
    $user = github_get('https://api.github.com/user', $token);
    if (!is_array($user) || !isset($user['login'])) {
        return null;
    }

    $repos = [];
    for ($page = 1; $page <= 10; $page++) {
        $batch = github_get(
            "https://api.github.com/user/repos?per_page=100&page={$page}&affiliation=owner",
            $token
        );
        if (!is_array($batch)) {
            return null; // partial data would understate the numbers
        }
        $repos = array_merge($repos, $batch);
        if (count($batch) < 100) {
            break;
        }
    }

    $langs   = [];
    $stars   = 0;
    $forks   = 0;
    $recent  = 0;
    $oldest  = '9999';
    $public  = [];
    $yearAgo = gmdate('Y-m-d', strtotime('-1 year')) . 'T00:00:00Z';
    foreach ($repos as $r) {
        $stars += $r['stargazers_count'] ?? 0;
        $forks += $r['forks_count'] ?? 0;
        if (($r['created_at'] ?? '') >= $yearAgo) {
            $recent++;
        }
        if (($r['created_at'] ?? '9999') < $oldest) {
            $oldest = $r['created_at'];
        }
        if (!empty($r['language'])) {
            $langs[$r['language']] = ($langs[$r['language']] ?? 0) + 1;
        }
        if (empty($r['private'])) {
            $public[] = [
                'name'  => $r['name'],
                'stars' => $r['stargazers_count'] ?? 0,
                'lang'  => $r['language'] ?? '',
            ];
        }
    }
    arsort($langs);
    usort($public, fn($a, $b) => $b['stars'] <=> $a['stars']);

    return [
        'v'         => 2,
        'name'      => $user['name'] ?: $user['login'],
        'repos'     => count($repos),
        'stars'     => $stars,
        'forks'     => $forks,
        'followers' => $user['followers'] ?? 0,
        'recent'    => $recent,
        'since'     => (int) substr($oldest, 0, 4),
        'langCount' => count($langs),
        'langs'     => array_slice($langs, 0, 6, true),
        'langTotal' => array_sum($langs),
        'top'       => array_slice($public, 0, 5),
    ];
}

$config  = @include __DIR__ . '/config.php';
$token   = is_array($config) ? ($config['token'] ?? '') : '';
$cache   = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
$summary = null;
$stale   = false;

if (is_array($cache) && isset($cache['summary'], $cache['fetched_at'])
    && ($cache['summary']['v'] ?? 0) >= 2
    && time() - $cache['fetched_at'] < $cacheTtl) {
    $summary = $cache['summary'];
} else {
    if ($token !== '') {
        $summary = fetch_summary($token);
    }
    if ($summary !== null) {
        file_put_contents(
            $cacheFile,
            json_encode(['fetched_at' => time(), 'summary' => $summary]),
            LOCK_EX
        );
    } elseif (is_array($cache) && isset($cache['summary'])) {
        // token expired or API down: serve the last good numbers forever
        $summary = $cache['summary'];
        $stale   = true;
    }
}

/* ---------- render ---------- */

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
}

if ($summary === null) {
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="420" height="60">'
       . '<rect width="420" height="60" rx="6" fill="#0d1117" stroke="#30363d"/>'
       . '<text x="20" y="36" font-family="Segoe UI,Ubuntu,sans-serif" font-size="14" fill="#f85149">'
       . 'stats unavailable: check config.php token</text></svg>';
    exit;
}

$langColors = [
    'Python'           => '#3572A5',
    'JavaScript'       => '#f1e05a',
    'TypeScript'       => '#3178c6',
    'HTML'             => '#e34c26',
    'CSS'              => '#563d7c',
    'PHP'              => '#4F5D95',
    'Jupyter Notebook' => '#DA5B0B',
    'Java'             => '#b07219',
    'Kotlin'           => '#A97BFF',
    'Batchfile'        => '#C1F12E',
    'Visual Basic'     => '#945db7',
];

$staleNote = $stale ? '<text x="398" y="16" text-anchor="end" font-family="Segoe UI,Ubuntu,sans-serif" '
    . 'font-size="9" fill="#8b949e">cached</text>' : '';

if ($card === 'stats') {
    $rows = [
        ['Total Repositories',            number_format($summary['repos'])],
        ['Total Stars',                   number_format($summary['stars'])],
        ['Total Forks',                   number_format($summary['forks'])],
        ['Followers',                     number_format($summary['followers'])],
        ['Repos Created (Last 12 Months)', number_format($summary['recent'])],
    ];
    $h    = 70 + count($rows) * 26;
    $body = '';
    $y    = 64;
    foreach ($rows as [$label, $value]) {
        $body .= '<text x="24" y="' . $y . '" font-size="13" fill="#c9d1d9">' . esc($label) . '</text>'
               . '<text x="396" y="' . $y . '" text-anchor="end" font-size="13" font-weight="600" fill="#58a6ff">'
               . esc($value) . '</text>';
        $y += 26;
    }
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="420" height="' . $h . '" '
       . 'font-family="Segoe UI,Ubuntu,sans-serif" role="img" aria-label="GitHub stats">'
       . '<rect width="420" height="' . $h . '" rx="6" fill="#0d1117" stroke="#30363d"/>'
       . '<text x="24" y="34" font-size="16" font-weight="600" fill="#58a6ff">'
       . esc($summary['name']) . "'s GitHub Stats</text>"
       . $staleNote . $body . '</svg>';
    exit;
}

if ($card === 'trophies') {
    $years   = max(1, (int) gmdate('Y') - ($summary['since'] ?? (int) gmdate('Y')));
    $tiles = [
        ['⭐', number_format($summary['stars']),        'Total Stars',      '#e3b341'],
        ['📦', number_format($summary['repos']),        'Repositories',     '#58a6ff'],
        ['👥', number_format($summary['followers']),    'Followers',        '#bc8cff'],
        ['🚀', number_format($summary['recent']),       'Repos / Last Year', '#f778ba'],
        ['🗓', $years . '+',                            'Years Shipping',   '#7ee787'],
        ['💻', (string) ($summary['langCount'] ?? 0),   'Languages',        '#ffa657'],
    ];
    $w = 420; $tw = 124; $th = 88; $gap = 10; $x0 = 18; $y0 = 48;
    $body = '';
    foreach ($tiles as $i => [$icon, $value, $label, $color]) {
        $x = $x0 + ($i % 3) * ($tw + $gap);
        $y = $y0 + intdiv($i, 3) * ($th + $gap);
        $cx = $x + $tw / 2;
        $body .= '<rect x="' . $x . '" y="' . $y . '" width="' . $tw . '" height="' . $th
               . '" rx="8" fill="#161b22" stroke="' . $color . '" stroke-width="1"/>'
               . '<text x="' . $cx . '" y="' . ($y + 26) . '" text-anchor="middle" font-size="16">' . $icon . '</text>'
               . '<text x="' . $cx . '" y="' . ($y + 52) . '" text-anchor="middle" font-size="18" font-weight="700" fill="'
               . $color . '">' . esc($value) . '</text>'
               . '<text x="' . $cx . '" y="' . ($y + 72) . '" text-anchor="middle" font-size="10" fill="#8b949e">'
               . esc($label) . '</text>';
    }
    $h = $y0 + 2 * $th + $gap + 18;
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" '
       . 'font-family="Segoe UI,Ubuntu,sans-serif" role="img" aria-label="GitHub achievements">'
       . '<rect width="' . $w . '" height="' . $h . '" rx="6" fill="#0d1117" stroke="#30363d"/>'
       . '<text x="24" y="32" font-size="16" font-weight="600" fill="#58a6ff">Achievements</text>'
       . $staleNote . $body . '</svg>';
    exit;
}

if ($card === 'top') {
    $top     = $summary['top'] ?? [];
    $maxStar = max(1, $top ? $top[0]['stars'] : 1);
    $h       = 64 + count($top) * 32;
    $body    = '';
    $y       = 56;
    foreach ($top as $r) {
        $barW = (int) round(($r['stars'] / $maxStar) * 150);
        $body .= '<text x="24" y="' . ($y + 12) . '" font-size="12" fill="#c9d1d9">' . esc($r['name']) . '</text>'
               . '<rect x="200" y="' . $y . '" width="150" height="14" rx="7" fill="#21262d"/>'
               . '<rect x="200" y="' . $y . '" width="' . max(4, $barW) . '" height="14" rx="7" fill="#e3b341"/>'
               . '<text x="396" y="' . ($y + 12) . '" text-anchor="end" font-size="12" fill="#8b949e">★ '
               . number_format($r['stars']) . '</text>';
        $y += 32;
    }
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="420" height="' . $h . '" '
       . 'font-family="Segoe UI,Ubuntu,sans-serif" role="img" aria-label="Top repositories">'
       . '<rect width="420" height="' . $h . '" rx="6" fill="#0d1117" stroke="#30363d"/>'
       . '<text x="24" y="32" font-size="16" font-weight="600" fill="#58a6ff">Top Repositories</text>'
       . $staleNote . $body . '</svg>';
    exit;
}

/* langs card */
$langs = $summary['langs'];
$total = max(1, $summary['langTotal']);
$h     = 66 + count($langs) * 30;
$body  = '';
$y     = 58;
foreach ($langs as $lang => $count) {
    $pct   = $count / $total * 100;
    $barW  = (int) round($pct / 100 * 180);
    $color = $langColors[$lang] ?? '#8b949e';
    $body .= '<text x="24" y="' . ($y + 11) . '" font-size="12" fill="#c9d1d9">' . esc($lang) . '</text>'
           . '<rect x="150" y="' . $y . '" width="180" height="14" rx="7" fill="#21262d"/>'
           . '<rect x="150" y="' . $y . '" width="' . max(4, $barW) . '" height="14" rx="7" fill="' . $color . '"/>'
           . '<text x="396" y="' . ($y + 11) . '" text-anchor="end" font-size="12" fill="#8b949e">'
           . sprintf('%.1f%%', $pct) . '</text>';
    $y += 30;
}
echo '<svg xmlns="http://www.w3.org/2000/svg" width="420" height="' . $h . '" '
   . 'font-family="Segoe UI,Ubuntu,sans-serif" role="img" aria-label="Top languages">'
   . '<rect width="420" height="' . $h . '" rx="6" fill="#0d1117" stroke="#30363d"/>'
   . '<text x="24" y="32" font-size="16" font-weight="600" fill="#58a6ff">Most Used Languages</text>'
   . $staleNote . $body . '</svg>';
