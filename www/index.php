<?php
// ============================================================
// METRIQUES CPU — cgroups v2 (usage conteneur) + loadavg fallback
// Delta calculé entre deux appels via cache fichier /tmp/
// ============================================================
function getCpuMetrics(): array
{
    $cores = 1;
    if (is_readable('/proc/cpuinfo')) {
        $cores = max(1, substr_count(file_get_contents('/proc/cpuinfo'), 'processor'));
    }

    $percent  = 0;
    $barClass = '';
    $source   = 'load';
    $load     = 0;

    $statFile  = '/sys/fs/cgroup/cpu.stat';
    $maxFile   = '/sys/fs/cgroup/cpu.max';
    $cacheFile = sys_get_temp_dir() . '/dashboard_cpu.json';

    if (is_readable($statFile)) {
        preg_match('/usage_usec\s+(\d+)/', file_get_contents($statFile), $m);
        $usageNow = isset($m[1]) ? (int) $m[1] : 0;
        $timeNow  = microtime(true);

        // Quota CPU du conteneur (ex : "50000 100000" = 0.5 cœur, "max …" = illimité)
        $quotaCores = $cores;
        if (is_readable($maxFile)) {
            [$q, $p] = explode(' ', trim(file_get_contents($maxFile)));
            if ($q !== 'max' && (int) $p > 0) {
                $quotaCores = (int) $q / (int) $p;
            }
        }

        $cache = [];
        if (is_readable($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true) ?? [];
        }

        if (!empty($cache) && isset($cache['usage_usec'], $cache['time'])) {
            $deltaUsage = $usageNow - (int) $cache['usage_usec'];
            $deltaTime  = ($timeNow - (float) $cache['time']) * 1e6; // µs
            if ($deltaTime > 0 && $deltaUsage >= 0) {
                $percent = round(($deltaUsage / ($deltaTime * $quotaCores)) * 100, 1);
                $percent = min(max($percent, 0), 100);
            }
        }

        @file_put_contents($cacheFile, json_encode([
            'usage_usec' => $usageNow,
            'time'       => $timeNow,
        ]));

        $source = 'cgroup';
    }

    // Load average — toujours lu (affiché en secondaire, utilisé si pas de cgroup)
    if (function_exists('sys_getloadavg')) {
        $avg  = sys_getloadavg()[0];
        $load = round($avg, 2);
        if ($source === 'load') {
            $percent = round(($avg / $cores) * 100, 1);
            $percent = min($percent, 100);
        }
    }

    if ($percent >= 90)      $barClass = 'disk__bar-fill--critical';
    elseif ($percent >= 70)  $barClass = 'disk__bar-fill--warning';

    return [
        'percent'  => $percent,
        'load'     => $load,
        'cores'    => $cores,
        'barClass' => $barClass,
        'source'   => $source,
    ];
}

// ============================================================
// ROUTEUR API — Endpoints JSON intégrés (?api=metrics|docker)
// Appelés par fetch() depuis le dashboard, retournent du JSON
// et stoppent l'exécution avant le rendu HTML.
// ============================================================

$apiAction = $_GET['api'] ?? null;

if ($apiAction === 'metrics') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');

    // Uptime
    $uptime = 'N/A';
    if (is_readable('/proc/uptime')) {
        $s = (int) explode(' ', file_get_contents('/proc/uptime'))[0];
        $d = floor($s / 86400);
        $h = floor(($s % 86400) / 3600);
        $m = floor(($s % 3600) / 60);
        $parts = [];
        if ($d > 0) $parts[] = $d . 'j';
        if ($h > 0) $parts[] = $h . 'h';
        $parts[] = $m . 'm';
        $uptime = implode(' ', $parts);
    }

    // CPU
    $cpu = getCpuMetrics();
    $cpuPercent  = $cpu['percent'];
    $cpuLoad     = $cpu['load'];
    $cpuCores    = $cpu['cores'];
    $cpuBarClass = $cpu['barClass'];
    $cpuSource   = $cpu['source'];

    // RAM
    $ramTotal = 0;
    $ramUsed = 0;
    $ramFree = 0;
    $ramPercent = 0;
    $ramBarClass = '';
    $ramSource = '';
    $cgroupLimit = '/sys/fs/cgroup/memory.max';
    $cgroupCurrent = '/sys/fs/cgroup/memory.current';
    if (is_readable($cgroupLimit) && is_readable($cgroupCurrent)) {
        $limitRaw = trim(file_get_contents($cgroupLimit));
        $currentRaw = trim(file_get_contents($cgroupCurrent));
        if ($limitRaw !== 'max' && is_numeric($limitRaw)) {
            $ramTotal = (int) $limitRaw;
            $ramUsed = (int) $currentRaw;
            $ramFree = $ramTotal - $ramUsed;
            $ramSource = 'conteneur';
        }
    }
    if ($ramTotal === 0 && is_readable('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mt))     $ramTotal = $mt[1] * 1024;
        if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma)) $ramFree  = $ma[1] * 1024;
        $ramUsed = $ramTotal - $ramFree;
        $ramSource = 'WSL';
    }
    if ($ramTotal > 0) {
        $ramPercent = round(($ramUsed / $ramTotal) * 100, 1);
        if ($ramPercent >= 90)     $ramBarClass = 'disk__bar-fill--critical';
        elseif ($ramPercent >= 70) $ramBarClass = 'disk__bar-fill--warning';
    }

    $fmt = function (int|float $bytes, int $precision = 2): string {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return round($bytes, $precision) . ' ' . $units[$i];
    };

    echo json_encode([
        'uptime' => $uptime,
        'cpu'    => ['percent' => $cpuPercent, 'load' => $cpuLoad, 'cores' => $cpuCores, 'barClass' => $cpuBarClass, 'source' => $cpuSource],
        'ram'    => ['percent' => $ramPercent, 'used' => $fmt($ramUsed), 'free' => $fmt($ramFree), 'total' => $fmt($ramTotal), 'source' => $ramSource, 'barClass' => $ramBarClass, 'available' => $ramTotal > 0],
    ]);
    exit;
}

if ($apiAction === 'docker') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');

    $containers = [];
    $socket = '/var/run/docker.sock';
    if (file_exists($socket)) {
        $fp = @stream_socket_client("unix://$socket", $errno, $errstr, 2);
        if ($fp) {
            $request = "GET /containers/json?all=true HTTP/1.0\r\nHost: localhost\r\n\r\n";
            fwrite($fp, $request);
            $response = '';
            while (!feof($fp)) { $response .= fread($fp, 8192); }
            fclose($fp);
            $parts = explode("\r\n\r\n", $response, 2);
            if (isset($parts[1])) {
                $data = json_decode($parts[1], true);
                if (is_array($data)) {
                    foreach ($data as $c) {
                        $containers[] = [
                            'name'   => ltrim($c['Names'][0] ?? 'unknown', '/'),
                            'state'  => $c['State'] ?? 'unknown',
                            'status' => $c['Status'] ?? '',
                        ];
                    }
                }
            }
        }
    }
    if (empty($containers)) {
        $output = @shell_exec('docker ps -a --format "{{.Names}}|{{.State}}|{{.Status}}" 2>/dev/null');
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                $parts = explode('|', $line, 3);
                if (count($parts) === 3) {
                    $containers[] = ['name' => $parts[0], 'state' => $parts[1], 'status' => $parts[2]];
                }
            }
        }
    }

    echo json_encode(['containers' => $containers, 'available' => !empty($containers)]);
    exit;
}

// ============================================================
// LOGIQUE PHP — Données collectées avant tout affichage HTML
// ============================================================

// --- Fonctions utilitaires ---
function formatBytes(int|float $bytes, int $precision = 2): string
{
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}

function getFolderSize(string $dir): int
{
    $size = 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $f) {
        $size += $f->getSize();
    }
    return $size;
}

// --- Connexion MySQL ---
$dbHost     = getenv('DB_HOST') ?: 'mysql-server';
$dbUser     = getenv('DB_USER') ?: 'root';
$dbPass     = getenv('DB_PASS') ?: 'root';
$dbName     = getenv('DB_DATABASE') ?: 'test';
$mysqlVersion   = '';
$mysqlConnected = false;
$mysqlError     = '';

if (extension_loaded('mysqli')) {
    try {
        $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if ($conn->connect_error) {
            $mysqlError = $conn->connect_error;
        } else {
            $mysqlVersion   = $conn->server_info;
            $mysqlConnected = true;
            $conn->close();
        }
    } catch (Exception $e) {
        $mysqlError = $e->getMessage();
    }
} elseif (extension_loaded('pdo_mysql')) {
    try {
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $mysqlVersion   = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $mysqlConnected = true;
    } catch (PDOException $e) {
        $mysqlError = $e->getMessage();
    }
}

// --- Uptime serveur ---
$uptimeString = 'N/A';
if (is_readable('/proc/uptime')) {
    $uptimeRaw     = file_get_contents('/proc/uptime');
    $uptimeSeconds = (int) explode(' ', $uptimeRaw)[0];
    $days    = floor($uptimeSeconds / 86400);
    $hours   = floor(($uptimeSeconds % 86400) / 3600);
    $minutes = floor(($uptimeSeconds % 3600) / 60);
    $parts   = [];
    if ($days > 0)    $parts[] = $days . 'j';
    if ($hours > 0)   $parts[] = $hours . 'h';
    $parts[] = $minutes . 'm';
    $uptimeString = implode(' ', $parts);
}

// --- Volume partage (www/) ---
$volumeSize    = getFolderSize(__DIR__);
$volumeFolders = 0;
$volumeFiles   = 0;
foreach (array_diff(scandir(__DIR__), ['.', '..', 'index.php', '.git']) as $entry) {
    if (is_dir(__DIR__ . DIRECTORY_SEPARATOR . $entry)) $volumeFolders++;
    else $volumeFiles++;
}

// --- CPU ---
$cpu         = getCpuMetrics();
$cpuPercent  = $cpu['percent'];
$cpuLoad     = $cpu['load'];
$cpuCores    = $cpu['cores'];
$cpuBarClass = $cpu['barClass'];
$cpuSource   = $cpu['source'];

// --- Memoire RAM (conteneur via cgroups v2) ---
$ramTotal    = 0;
$ramUsed     = 0;
$ramFree     = 0;
$ramPercent  = 0;
$ramBarClass = '';
$ramSource   = '';

// Cgroups v2 : limite et usage reels du conteneur
$cgroupLimit   = '/sys/fs/cgroup/memory.max';
$cgroupCurrent = '/sys/fs/cgroup/memory.current';

if (is_readable($cgroupLimit) && is_readable($cgroupCurrent)) {
    $limitRaw   = trim(file_get_contents($cgroupLimit));
    $currentRaw = trim(file_get_contents($cgroupCurrent));

    // "max" signifie pas de limite definie → fallback /proc/meminfo
    if ($limitRaw !== 'max' && is_numeric($limitRaw)) {
        $ramTotal  = (int) $limitRaw;
        $ramUsed   = (int) $currentRaw;
        $ramFree   = $ramTotal - $ramUsed;
        $ramSource = 'conteneur';
    }
}

// Fallback : /proc/meminfo (RAM WSL si pas de cgroup)
if ($ramTotal === 0 && is_readable('/proc/meminfo')) {
    $meminfo = file_get_contents('/proc/meminfo');
    if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m))     $ramTotal = $m[1] * 1024;
    if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m)) $ramFree  = $m[1] * 1024;
    $ramUsed   = $ramTotal - $ramFree;
    $ramSource = 'WSL';
}

if ($ramTotal > 0) {
    $ramPercent = round(($ramUsed / $ramTotal) * 100, 1);
    if ($ramPercent >= 90)      $ramBarClass = 'disk__bar-fill--critical';
    elseif ($ramPercent >= 70)  $ramBarClass = 'disk__bar-fill--warning';
}

// --- Extensions PHP ---
$composerCheck = shell_exec('composer --version 2>/dev/null');
$checks = [
    'PDO'      => extension_loaded('pdo_mysql'),
    'MySQLi'   => function_exists('mysqli_connect'),
    'XDebug'   => extension_loaded('xdebug'),
    'Composer'  => !empty($composerCheck),
];

// --- Compatibilite Symfony ---
$symfonyChecks = [
    ['name' => 'PHP >= 8.2',    'ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'required' => true,  'fix' => ''],
    ['name' => 'PDO',           'ok' => extension_loaded('pdo_mysql'),                'required' => true,  'fix' => 'docker-php-ext-install pdo pdo_mysql'],
    ['name' => 'Composer',      'ok' => !empty($composerCheck),                       'required' => true,  'fix' => ''],
    ['name' => 'mod_rewrite',   'ok' => in_array('mod_rewrite', apache_get_modules() ?? []), 'required' => true,  'fix' => 'a2enmod rewrite'],
    ['name' => 'intl',          'ok' => extension_loaded('intl'),                     'required' => true,  'fix' => 'apt-get install -y libicu-dev && docker-php-ext-install intl'],
    ['name' => 'mbstring',      'ok' => extension_loaded('mbstring'),                 'required' => true,  'fix' => 'docker-php-ext-install mbstring'],
    ['name' => 'opcache',       'ok' => extension_loaded('Zend OPcache'),             'required' => false, 'fix' => 'docker-php-ext-install opcache'],
    ['name' => 'apcu',          'ok' => extension_loaded('apcu'),                     'required' => false, 'fix' => 'pecl install apcu && docker-php-ext-enable apcu'],
];
$symfonyReady    = count(array_filter($symfonyChecks, fn($c) => $c['required'] && !$c['ok'])) === 0;
$symfonyScore    = count(array_filter($symfonyChecks, fn($c) => $c['ok']));
$symfonyTotal    = count($symfonyChecks);
$symfonyMissing  = array_filter($symfonyChecks, fn($c) => !$c['ok'] && $c['fix'] !== '');

// --- Port MySQL (pour le tooltip) ---
$dbPort = getenv('DB_PORT') ?: '3306';

// --- Scan des projets ---
$allowedExtensions = ['html', 'htm', 'php', 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico'];
$rawFiles  = array_diff(scandir(__DIR__), ['.', '..', 'index.php', '.git']);

$filesData = [];
foreach ($rawFiles as $file) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $file;
    if (is_dir($path)) {
        $sizeRaw = getFolderSize($path);
        $sizeTxt = round($sizeRaw / (1024 * 1024), 2) . ' MB';
        $isDir   = true;
    } else {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions)) continue;
        $sizeRaw = filesize($path);
        $sizeTxt = round($sizeRaw / 1024, 2) . ' KB';
        $isDir   = false;
    }
    $filesData[] = [
        'name'      => $file,
        'modTime'   => filemtime($path),
        'sizeBytes' => $sizeRaw,
        'sizeTxt'   => $sizeTxt,
        'isDir'     => $isDir,
    ];
}
usort($filesData, fn($a, $b) => $b['modTime'] <=> $a['modTime']);

// --- Raccourcis rapides ---
$quickLinks = [
    ['label' => 'phpinfo()',    'url' => '/info.php',             'icon' => 'info'],
    ['label' => 'PhpMyAdmin',   'url' => '/phpmyadmin/',          'icon' => 'storage'],
    ['label' => 'PHP.net',      'url' => 'https://www.php.net/manual/fr/', 'icon' => 'code'],
    ['label' => 'MySQL Docs',   'url' => 'https://dev.mysql.com/doc/',     'icon' => 'menu_book'],
];

// --- Liens utiles ---
$usefulLinks = [
    ['label' => 'PHP',             'url' => 'https://www.php.net/manual/fr/'],
    ['label' => 'MySQL',           'url' => 'https://dev.mysql.com/doc/refman/8.4/en/'],
    ['label' => 'Docker',          'url' => 'https://docs.docker.com/'],
    ['label' => 'MDN',             'url' => 'https://developer.mozilla.org/fr/'],
    ['label' => 'Stack Overflow',  'url' => 'https://stackoverflow.com/'],
    ['label' => 'GitHub',          'url' => 'https://github.com/'],
];

// --- Variables serveur ---
$apacheVersion = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$phpVersion    = phpversion();
$pmaVersion    = getenv('PMA_VERSION') ?: '';

// Version Docker : variable d'environnement injectee depuis le host via docker-compose
$dockerVersion = getenv('DOCKER_VERSION') ?: '';
if (empty($dockerVersion) && is_readable('/proc/version')) {
    // Fallback : extraire la version depuis le kernel (contient souvent "docker" ou "WSL")
    $kernelInfo = file_get_contents('/proc/version');
    if (preg_match('/Docker Desktop (\S+)/i', $kernelInfo, $m)) {
        $dockerVersion = $m[1];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Docker LAMP - Dashboard</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons" />
    <link rel="shortcut icon" href="https://skillicons.dev/icons?i=php" type="image/x-icon" />
    <style>
        /* =============================================
           1. VARIABLES & DESIGN SYSTEM
           ============================================= */
        :root {
            --bg-body: #0a0a0f;
            --bg-card: rgba(255, 255, 255, 0.03);
            --bg-card-hover: rgba(255, 255, 255, 0.06);
            --border-card: rgba(255, 255, 255, 0.08);
            --border-card-hover: rgba(255, 255, 255, 0.15);

            --text-primary: rgba(255, 255, 255, 0.92);
            --text-secondary: rgba(255, 255, 255, 0.55);
            --text-tertiary: rgba(255, 255, 255, 0.35);

            --accent-green: #22c55e;
            --accent-red: #ef4444;
            --accent-blue: #3b82f6;
            --accent-purple: #a855f7;
            --accent-amber: #f59e0b;
            --accent-cyan: #06b6d4;

            --gradient-hero: linear-gradient(135deg,
                    rgba(59, 130, 246, 0.12) 0%,
                    rgba(168, 85, 247, 0.08) 50%,
                    rgba(6, 182, 212, 0.06) 100%);

            --card-radius: 16px;
            --card-padding: 24px;
            --grid-gap: 12px;

            --font-sans: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            --font-mono: 'SF Mono', 'Cascadia Code', 'Fira Code', 'Consolas', monospace;

            --space-1: 4px;
            --space-2: 8px;
            --space-3: 12px;
            --space-4: 16px;
            --space-5: 20px;
            --space-6: 24px;
            --space-8: 32px;
        }

        /* --- Light mode --- */
        [data-theme="light"] {
            --bg-body: #f5f5f7;
            --bg-card: rgba(0, 0, 0, 0.03);
            --bg-card-hover: rgba(0, 0, 0, 0.06);
            --border-card: rgba(0, 0, 0, 0.1);
            --border-card-hover: rgba(0, 0, 0, 0.18);

            --text-primary: rgba(0, 0, 0, 0.88);
            --text-secondary: rgba(0, 0, 0, 0.55);
            --text-tertiary: rgba(0, 0, 0, 0.35);

            --gradient-hero: linear-gradient(135deg,
                    rgba(59, 130, 246, 0.08) 0%,
                    rgba(168, 85, 247, 0.05) 50%,
                    rgba(6, 182, 212, 0.04) 100%);
        }

        /* =============================================
           2. RESET & BASE
           ============================================= */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        img {
            display: block;
            max-width: 100%;
        }

        /* =============================================
           3. DASHBOARD & BENTO GRID
           ============================================= */
        .dashboard {
            max-width: 1200px;
            margin: 0 auto;
            padding: var(--space-6);
        }

        .bento {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            grid-auto-rows: minmax(80px, auto);
            gap: var(--grid-gap);
        }

        /* =============================================
           4. CARD BASE
           ============================================= */
        .bento__card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: var(--card-radius);
            padding: var(--card-padding);
            transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .bento__card:hover {
            background: var(--bg-card-hover);
            border-color: var(--border-card-hover);
            transform: translateY(-1px);
        }

        .card__label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 600;
            color: var(--text-tertiary);
            margin-bottom: var(--space-3);
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        /* =============================================
           5. GRID PLACEMENTS
           ============================================= */
        .bento__card--hero {
            grid-column: span 4;
            grid-row: span 2;
        }

        .bento__card--clock {
            grid-column: span 2;
            grid-row: span 2;
        }

        .bento__card--apache {
            grid-column: span 1;
        }

        .bento__card--php {
            grid-column: span 1;
        }

        .bento__card--mysql {
            grid-column: span 2;
        }

        .bento__card--pma {
            grid-column: span 2;
        }

        .bento__card--extensions {
            grid-column: span 3;
        }

        .bento__card--quicklinks {
            grid-column: span 3;
        }

        .bento__card--disk {
            grid-column: span 2;
        }

        .bento__card--ram {
            grid-column: span 2;
        }

        .bento__card--cpu {
            grid-column: span 2;
        }

        .bento__card--symfony {
            grid-column: 1 / -1;
        }

        .bento__card--projects {
            grid-column: 1 / -1;
            padding-right: calc(var(--card-padding) + 28px);
        }

        .bento__card--links {
            grid-column: 1 / -1;
        }

        /* =============================================
           6. HERO CARD
           ============================================= */
        .bento__card--hero {
            background: var(--gradient-hero);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .bento__card--hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 80%;
            height: 160%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%);
            animation: heroGlow 8s ease-in-out infinite alternate;
            pointer-events: none;
        }

        @keyframes heroGlow {
            0% {
                opacity: 0.3;
                transform: scale(1) translate(0, 0);
            }

            100% {
                opacity: 0.7;
                transform: scale(1.2) translate(-5%, 5%);
            }
        }

        .hero__content {
            display: flex;
            align-items: center;
            gap: var(--space-5);
            position: relative;
            z-index: 1;
        }

        .hero__logo {
            width: 64px;
            height: 64px;
            flex-shrink: 0;
        }

        .hero__title {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .hero__subtitle {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-top: var(--space-1);
            font-family: var(--font-mono);
        }

        /* =============================================
           7. CLOCK CARD
           ============================================= */
        .bento__card--clock {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }

        .clock__time {
            font-size: 2.5rem;
            font-weight: 300;
            font-family: var(--font-mono);
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.02em;
            line-height: 1.2;
        }

        .clock__date {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: var(--space-2);
            text-transform: capitalize;
        }

        .clock__uptime {
            display: flex;
            align-items: center;
            gap: var(--space-1);
            margin-top: var(--space-4);
            font-size: 0.8rem;
            color: var(--text-tertiary);
            padding: var(--space-1) var(--space-3);
            background: rgba(255, 255, 255, 0.04);
            border-radius: 20px;
        }

        .clock__uptime .material-icons {
            font-size: 14px;
        }

        /* =============================================
           8. SERVICE CARDS (Apache, PHP, MySQL, PMA)
           ============================================= */
        .service__inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            text-align: center;
            height: 100%;
        }

        .service__logo {
            width: 48px;
            height: 32px;
            object-fit: contain;
        }

        .service__version {
            font-size: 0.85rem;
            font-family: var(--font-mono);
            color: var(--text-secondary);
        }

        /* --- Icone de lien externe (haut droite) --- */
        .card__external {
            position: absolute;
            top: var(--space-3);
            right: var(--space-3);
            font-size: 14px;
            color: var(--text-tertiary);
            opacity: 0;
            transition: opacity 0.2s ease, transform 0.2s ease, color 0.2s ease;
        }

        .bento__card:hover .card__external {
            opacity: 1;
        }

        a.bento__card {
            cursor: pointer;
        }

        a.bento__card:hover .card__external {
            opacity: 1;
            transform: translate(2px, -2px);
            color: var(--text-primary);
        }

        /* =============================================
           9. STATUS DOTS
           ============================================= */
        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .status-dot--ok {
            background: var(--accent-green);
            box-shadow: 0 0 6px rgba(34, 197, 94, 0.5);
        }

        .status-dot--ko {
            background: var(--accent-red);
            box-shadow: 0 0 6px rgba(239, 68, 68, 0.5);
        }

        /* =============================================
           10. EXTENSIONS CARD
           ============================================= */
        .extensions__grid {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-3);
        }

        .extension__item {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-1) var(--space-3);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.04);
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* =============================================
           11. DISK USAGE CARD
           ============================================= */
        .bento__card--disk {
            display: flex;
            flex-direction: column;
        }

        .disk__percent {
            font-size: 2.2rem;
            font-weight: 700;
            font-family: var(--font-mono);
            margin-bottom: var(--space-2);
        }

        .disk__label {
            font-size: 0.8rem;
            color: var(--text-tertiary);
            margin-bottom: var(--space-4);
        }

        .disk__bar {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: var(--space-4);
        }

        .disk__bar-fill {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, var(--accent-green), var(--accent-cyan));
            transition: width 0.8s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .disk__bar-fill--warning {
            background: linear-gradient(90deg, var(--accent-amber), var(--accent-red));
        }

        .disk__bar-fill--critical {
            background: var(--accent-red);
        }

        .disk__details {
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: auto;
        }

        .disk__detail-row {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .disk__legend {
            width: 8px;
            height: 8px;
            border-radius: 2px;
            display: inline-block;
            flex-shrink: 0;
        }

        .disk__legend--used {
            background: var(--accent-cyan);
        }

        .disk__legend--free {
            background: rgba(255, 255, 255, 0.15);
        }

        .disk__legend--load {
            background: var(--accent-amber);
        }

        .disk__legend--cores {
            background: var(--accent-purple);
        }

        /* =============================================
           11b. METRICS SPARKLINE CHART
           ============================================= */
        .metrics__chart {
            width: 100%;
            height: 80px;
            margin-top: var(--space-3);
            border-radius: 6px;
            opacity: 0.85;
            display: block;
        }

        /* =============================================
           12. QUICK LINKS CARD
           ============================================= */
        .quicklinks__grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--space-2);
        }

        .quicklink__item {
            position: relative;
            display: flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-2) var(--space-3);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid transparent;
            font-size: 0.85rem;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            overflow: hidden;
        }

        .quicklink__item:hover {
            border-color: var(--border-card-hover);
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-primary);
        }

        .quicklink__item .material-icons {
            font-size: 18px;
            color: var(--accent-blue);
        }

        .quicklink__external {
            position: absolute;
            top: 4px;
            right: 4px;
            font-size: 11px;
            color: var(--text-tertiary);
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .quicklink__item:hover .quicklink__external {
            opacity: 1;
        }

        /* =============================================
           13. PROJECTS CARD
           ============================================= */
        .card__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-4);
            margin-bottom: var(--space-4);
            flex-wrap: wrap;
        }

        .badge {
            font-size: 0.7rem;
            font-family: var(--font-mono);
            background: rgba(255, 255, 255, 0.08);
            padding: 2px 8px;
            border-radius: 10px;
            color: var(--text-secondary);
        }

        .search {
            position: relative;
            min-width: 200px;
            flex: 0 1 280px;
        }

        .search__icon {
            position: absolute;
            left: var(--space-3);
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: var(--text-tertiary);
            font-size: 18px;
        }

        .search__input {
            width: 100%;
            padding: var(--space-2) 34px var(--space-2) 38px;
            border: 1px solid var(--border-card);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.03);
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: var(--font-sans);
            outline: none;
            transition: border-color 0.2s ease;
        }

        .search__input:focus {
            border-color: var(--accent-blue);
        }

        .search__input::placeholder {
            color: var(--text-tertiary);
        }

        .search__clear {
            position: absolute;
            right: var(--space-2);
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-tertiary);
            padding: 2px;
            line-height: 0;
            border-radius: 50%;
            transition: color 0.2s ease, background 0.2s ease;
            display: none;
        }

        .search__clear .material-icons {
            font-size: 16px;
        }

        .search__clear:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.1);
        }

        .search__clear--visible {
            display: block;
        }

        .projects__grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: var(--space-3);
        }

        .project__item {
            padding: var(--space-4);
            border: 1px solid var(--border-card);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.02);
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
        }

        .project__item:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border-card-hover);
            transform: translateY(-1px);
        }

        .project__icon {
            font-size: 20px;
        }

        .project__icon--folder {
            color: #f59e0b;
        }

        .project__icon--file {
            color: var(--text-tertiary);
        }

        .project__header {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .project__name {
            font-size: 0.9rem;
            font-weight: 500;
        }

        .project__meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--text-tertiary);
            font-family: var(--font-mono);
        }

        .projects__empty {
            grid-column: 1 / -1;
            text-align: center;
            padding: var(--space-8);
            color: var(--text-tertiary);
        }

        /* =============================================
           14. USEFUL LINKS FOOTER
           ============================================= */
        .links__row {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
        }

        .link__item {
            display: flex;
            align-items: center;
            gap: var(--space-1);
            padding: var(--space-1) var(--space-3);
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--text-tertiary);
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .link__item:hover {
            color: var(--text-primary);
            border-color: var(--border-card-hover);
        }

        .link__item .material-icons {
            font-size: 12px;
        }

        /* =============================================
           15. SYMFONY CARD
           ============================================= */
        .symfony__header {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            margin-bottom: var(--space-4);
        }

        .symfony__logo {
            width: 32px;
            height: 32px;
            flex-shrink: 0;
        }

        .symfony__status {
            font-size: 0.85rem;
            font-weight: 600;
        }

        .symfony__status--ready {
            color: var(--accent-green);
        }

        .symfony__status--missing {
            color: var(--accent-amber);
        }

        .symfony__grid {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
        }

        .symfony__item {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-1) var(--space-3);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.04);
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .symfony__tag {
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1px 5px;
            border-radius: 4px;
            font-weight: 600;
        }

        .symfony__tag--req {
            background: rgba(239, 68, 68, 0.15);
            color: var(--accent-red);
        }

        .symfony__tag--rec {
            background: rgba(245, 158, 11, 0.15);
            color: var(--accent-amber);
        }

        .symfony__hint {
            margin-top: var(--space-4);
            padding: var(--space-3) var(--space-4);
            background: rgba(245, 158, 11, 0.06);
            border: 1px solid rgba(245, 158, 11, 0.15);
            border-radius: 10px;
            font-size: 0.75rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .symfony__hint-title {
            font-weight: 600;
            color: var(--accent-amber);
            margin-bottom: var(--space-2);
            display: flex;
            align-items: center;
            gap: var(--space-1);
        }

        .symfony__hint-title .material-icons {
            font-size: 14px;
        }

        .symfony__hint code {
            font-family: var(--font-mono);
            font-size: 0.7rem;
            background: rgba(255, 255, 255, 0.06);
            padding: 1px 6px;
            border-radius: 4px;
            color: var(--accent-cyan);
        }

        .symfony__details {
            width: 100%;
        }

        .symfony__summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            list-style: none;
            user-select: none;
            padding: var(--space-2) 0;
            margin: calc(var(--space-2) * -1) 0;
            border-radius: 8px;
            transition: background 0.15s ease;
        }

        .symfony__summary:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        [data-theme="light"] .symfony__summary:hover {
            background: rgba(0, 0, 0, 0.03);
        }

        .symfony__summary::-webkit-details-marker {
            display: none;
        }

        .symfony__summary-right {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .symfony__chevron {
            font-size: 20px;
            color: var(--text-tertiary);
            transition: transform 0.3s ease;
        }

        .symfony__details[open] .symfony__chevron {
            transform: rotate(180deg);
        }

        .symfony__body {
            margin-top: var(--space-4);
        }

        .bento__card--symfony {
            padding: var(--space-3) var(--card-padding);
            transition: padding 0.3s ease, background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }

        .bento__card--symfony:has(.symfony__details[open]) {
            padding: var(--card-padding);
        }

        /* =============================================
           15b. THEME TOGGLE
           ============================================= */
        .theme-toggle {
            position: fixed;
            bottom: var(--space-4);
            right: var(--space-4);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: 1px solid var(--border-card);
            background: var(--bg-card);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            z-index: 100;
            backdrop-filter: blur(10px);
        }

        .theme-toggle:hover {
            background: var(--bg-card-hover);
            border-color: var(--border-card-hover);
            color: var(--text-primary);
            transform: scale(1.1);
        }

        .theme-toggle .material-icons {
            font-size: 20px;
        }

        /* =============================================
           15c. PROJECT SORT & FILTER BUTTONS
           ============================================= */
        .projects__toolbar {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            flex-wrap: wrap;
        }

        .toolbar__group {
            display: flex;
            gap: 2px;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 8px;
            padding: 2px;
        }

        [data-theme="light"] .toolbar__group {
            background: rgba(0, 0, 0, 0.04);
        }

        .toolbar__btn {
            padding: var(--space-1) var(--space-3);
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--text-tertiary);
            font-size: 0.75rem;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .toolbar__btn:hover {
            color: var(--text-secondary);
            background: rgba(255, 255, 255, 0.06);
        }

        [data-theme="light"] .toolbar__btn:hover {
            background: rgba(0, 0, 0, 0.06);
        }

        .toolbar__btn--active {
            background: rgba(59, 130, 246, 0.15);
            color: var(--accent-blue);
        }

        .toolbar__btn--active:hover {
            background: rgba(59, 130, 246, 0.2);
            color: var(--accent-blue);
        }

        /* =============================================
           15d. MYSQL TOOLTIP
           ============================================= */
        .mysql__tooltip {
            position: absolute;
            bottom: var(--space-2);
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.65rem;
            font-family: var(--font-mono);
            color: var(--text-tertiary);
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .bento__card--mysql:hover .mysql__tooltip {
            opacity: 1;
        }

        /* =============================================
           15e. DOCKER STATUS CARD
           ============================================= */
        .bento__card--docker {
            grid-column: 1 / -1;
        }

        .docker__grid {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
        }

        .docker__service {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-1) var(--space-3);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.04);
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        [data-theme="light"] .docker__service {
            background: rgba(0, 0, 0, 0.04);
        }

        .docker__service-name {
            font-family: var(--font-mono);
            font-size: 0.75rem;
        }

        /* =============================================
           15f. AUTO-REFRESH INDICATOR
           ============================================= */
        .auto-refresh-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--accent-green);
            display: inline-block;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        /* =============================================
           16. REFRESH BUTTON (inside hero card)
           ============================================= */
        .refresh {
            position: absolute;
            top: var(--space-3);
            right: var(--space-3);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-tertiary);
            font-size: 14px;
            padding: 2px;
            line-height: 0;
            opacity: 0;
            transition: opacity 0.2s ease, transform 0.2s ease, color 0.2s ease;
            z-index: 2;
        }

        .refresh .material-icons {
            font-size: 18px;
            transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .bento__card:hover .refresh {
            opacity: 1;
        }

        .refresh:hover {
            color: var(--text-primary);
        }

        .refresh:hover .material-icons {
            transform: rotate(180deg);
        }

        /* =============================================
           16. RESPONSIVE
           ============================================= */
        @media (max-width: 900px) {
            .dashboard {
                padding: var(--space-3);
            }

            .bento {
                grid-template-columns: repeat(2, 1fr);
            }

            .bento__card--hero,
            .bento__card--clock,
            .bento__card--mysql,
            .bento__card--pma,
            .bento__card--extensions,
            .bento__card--disk,
            .bento__card--ram,
            .bento__card--cpu,
            .bento__card--quicklinks,
            .bento__card--symfony,
            .bento__card--docker {
                grid-column: span 2;
            }

            .bento__card--apache,
            .bento__card--php {
                grid-column: span 1;
            }

            .hero__logo {
                width: 48px;
                height: 48px;
            }

            .hero__title {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 500px) {
            .bento {
                grid-template-columns: 1fr;
            }

            .bento__card {
                grid-column: span 1 !important;
                grid-row: span 1 !important;
            }

            .bento__card--hero {
                grid-row: span 1 !important;
            }

            .card__header {
                flex-direction: column;
                align-items: stretch;
            }

            .search {
                flex: 1 1 100%;
            }

            .quicklinks__grid {
                grid-template-columns: 1fr;
            }

            .clock__time {
                font-size: 2rem;
            }
        }
    </style>
</head>

<body>
    <div class="dashboard">
        <div class="bento">

            <!-- ========== 1. HERO ========== -->
            <div class="bento__card bento__card--hero">
                <button class="refresh" onclick="location.reload()" title="Rafraichir le dashboard">
                    <span class="material-icons">refresh</span>
                </button>
                <div class="card__label">Serveur</div>
                <div class="hero__content">
                    <img src="https://www.vectorlogo.zone/logos/docker/docker-tile.svg"
                        alt="Docker" class="hero__logo" />
                    <div>
                        <h1 class="hero__title">Docker LAMP</h1>
                        <p class="hero__subtitle">
                            <?php if ($dockerVersion): ?>
                                Docker <?= htmlspecialchars($dockerVersion) ?>
                            <?php else: ?>
                                Docker Engine
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- ========== 2. HORLOGE + UPTIME ========== -->
            <div class="bento__card bento__card--clock">
                <div class="card__label">Horloge</div>
                <time class="clock__time" id="liveClock">--:--:--</time>
                <div class="clock__date" id="liveDate">---</div>
                <div class="clock__uptime">
                    <span class="material-icons">schedule</span>
                    Uptime : <span id="liveUptime"><?= htmlspecialchars($uptimeString) ?></span>
                </div>
            </div>

            <!-- ========== 3. APACHE ========== -->
            <div class="bento__card bento__card--apache">
                <div class="card__label">Apache</div>
                <div class="service__inner">
                    <img src="https://www.vectorlogo.zone/logos/apache/apache-official.svg"
                        alt="Apache" class="service__logo" />
                    <span class="service__version"><?= htmlspecialchars($apacheVersion) ?></span>
                </div>
            </div>

            <!-- ========== 4. PHP ========== -->
            <div class="bento__card bento__card--php">
                <div class="card__label">PHP</div>
                <div class="service__inner">
                    <img src="https://www.vectorlogo.zone/logos/php/php-ar21.svg"
                        alt="PHP" class="service__logo" />
                    <span class="service__version"><?= htmlspecialchars($phpVersion) ?></span>
                </div>
            </div>

            <!-- ========== 5. MYSQL ========== -->
            <div class="bento__card bento__card--mysql">
                <div class="card__label">
                    <span class="status-dot status-dot--<?= $mysqlConnected ? 'ok' : 'ko' ?>"></span>
                    MySQL
                </div>
                <div class="service__inner">
                    <img src="https://www.vectorlogo.zone/logos/mysql/mysql-ar21.svg"
                        alt="MySQL" class="service__logo" />
                    <span class="service__version">
                        <?php if ($mysqlConnected): ?>
                            <?= htmlspecialchars($mysqlVersion) ?>
                        <?php else: ?>
                            <?= htmlspecialchars($mysqlError ?: 'Non disponible') ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mysql__tooltip"><?= htmlspecialchars($dbHost) ?>:<?= htmlspecialchars($dbPort) ?> / <?= htmlspecialchars($dbName) ?></div>
            </div>

            <!-- ========== 6. PHPMYADMIN ========== -->
            <a href="/phpmyadmin/" target="_blank" class="bento__card bento__card--pma" title="Ouvrir PhpMyAdmin">
                <span class="material-icons card__external">open_in_new</span>
                <div class="card__label">PhpMyAdmin</div>
                <div class="service__inner">
                    <img src="https://www.vectorlogo.zone/logos/phpmyadmin/phpmyadmin-ar21.svg"
                        alt="PhpMyAdmin" class="service__logo" />
                    <?php if ($pmaVersion): ?>
                        <span class="service__version">v<?= htmlspecialchars($pmaVersion) ?></span>
                    <?php endif; ?>
                </div>
            </a>

            <!-- ========== 7. EXTENSIONS ========== -->
            <div class="bento__card bento__card--extensions">
                <div class="card__label">Extensions PHP</div>
                <div class="extensions__grid">
                    <?php foreach ($checks as $name => $status): ?>
                        <div class="extension__item">
                            <span class="status-dot status-dot--<?= $status ? 'ok' : 'ko' ?>"></span>
                            <span><?= htmlspecialchars($name) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ========== 8. RACCOURCIS ========== -->
            <div class="bento__card bento__card--quicklinks">
                <div class="card__label">Raccourcis</div>
                <div class="quicklinks__grid">
                    <?php foreach ($quickLinks as $link): ?>
                        <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" class="quicklink__item" title="Ouvrir <?= htmlspecialchars($link['label']) ?>">
                            <span class="material-icons"><?= htmlspecialchars($link['icon']) ?></span>
                            <span><?= htmlspecialchars($link['label']) ?></span>
                            <span class="material-icons quicklink__external">open_in_new</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ========== 9. VOLUME PARTAGE ========== -->
            <div class="bento__card bento__card--disk" title="Espace du volume www/">
                <button class="refresh" onclick="location.reload()" title="Rafraichir">
                    <span class="material-icons">refresh</span>
                </button>
                <div class="card__label">Volume www/</div>
                <div class="disk__percent"><?= formatBytes($volumeSize) ?></div>
                <div class="disk__label">espace utilise</div>
                <div class="disk__details" style="margin-top: var(--space-4);">
                    <div class="disk__detail-row">
                        <span class="material-icons" style="font-size:14px; color:var(--accent-amber);">folder</span>
                        <?= $volumeFolders ?> dossier<?= $volumeFolders > 1 ? 's' : '' ?>
                    </div>
                    <div class="disk__detail-row">
                        <span class="material-icons" style="font-size:14px; color:var(--text-tertiary);">description</span>
                        <?= $volumeFiles ?> fichier<?= $volumeFiles > 1 ? 's' : '' ?>
                    </div>
                </div>
            </div>

            <!-- ========== 10. MEMOIRE RAM ========== -->
            <div class="bento__card bento__card--ram" title="Memoire RAM" id="ramCard">
                <button class="refresh" onclick="location.reload()" title="Rafraichir">
                    <span class="material-icons">refresh</span>
                </button>
                <div class="card__label">RAM <?php if ($ramSource): ?>(<?= $ramSource ?>)<?php endif; ?> <span class="auto-refresh-dot" title="Auto-refresh 10s"></span></div>
                <?php if ($ramTotal > 0): ?>
                    <div class="disk__percent" id="ramPercent"><?= $ramPercent ?>%</div>
                    <div class="disk__label">utilise</div>
                    <div class="disk__bar">
                        <div class="disk__bar-fill <?= $ramBarClass ?>" id="ramBar"
                            data-width="<?= $ramPercent ?>" style="width: 0%"></div>
                    </div>
                    <canvas id="ramChart" class="metrics__chart"></canvas>
                    <div class="disk__details">
                        <div class="disk__detail-row">
                            <span class="disk__legend disk__legend--used"></span>
                            Utilise : <span id="ramUsed"><?= formatBytes($ramUsed) ?></span>
                        </div>
                        <div class="disk__detail-row">
                            <span class="disk__legend disk__legend--free"></span>
                            Disponible : <span id="ramFree"><?= formatBytes($ramFree) ?></span>
                        </div>
                        <div class="disk__detail-row">
                            Total : <span id="ramTotal"><?= formatBytes($ramTotal) ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="disk__percent">N/A</div>
                <?php endif; ?>
            </div>

            <!-- ========== 11. CPU ========== -->
            <div class="bento__card bento__card--cpu" title="Charge CPU" id="cpuCard">
                <button class="refresh" onclick="location.reload()" title="Rafraichir">
                    <span class="material-icons">refresh</span>
                </button>
                <div class="card__label">CPU (<?= $cpuSource === 'cgroup' ? 'conteneur' : 'hôte' ?>) <span class="auto-refresh-dot" title="Auto-refresh 10s"></span></div>
                <?php if ($cpuPercent > 0 || $cpuSource === 'cgroup'): ?>
                    <div class="disk__percent" id="cpuPercent"><?= $cpuPercent ?>%</div>
                    <div class="disk__label" id="cpuSubLabel"><?= $cpuSource === 'cgroup' ? 'usage conteneur' : 'charge moyenne' ?></div>
                    <div class="disk__bar">
                        <div class="disk__bar-fill <?= $cpuBarClass ?>" id="cpuBar"
                            data-width="<?= $cpuPercent ?>" style="width: 0%"></div>
                    </div>
                    <canvas id="cpuChart" class="metrics__chart"></canvas>
                    <div class="disk__details">
                        <div class="disk__detail-row">
                            <span class="disk__legend disk__legend--load"></span>
                            Load avg : <span id="cpuLoad"><?= $cpuLoad ?></span>
                        </div>
                        <div class="disk__detail-row">
                            <span class="disk__legend disk__legend--cores"></span>
                            Coeurs : <span id="cpuCores"><?= $cpuCores ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="disk__percent">N/A</div>
                <?php endif; ?>
            </div>

            <!-- ========== 12. PROJETS ========== -->
            <div class="bento__card bento__card--projects">
                <button class="refresh" onclick="location.reload()" title="Rafraichir la liste">
                    <span class="material-icons">refresh</span>
                </button>
                <div class="card__header">
                    <div class="card__label">
                        Projets
                        <span class="badge" id="projectsCount">0</span>
                    </div>
                    <div class="projects__toolbar">
                        <div class="toolbar__group" id="filterGroup">
                            <button class="toolbar__btn toolbar__btn--active" data-filter="all">Tout</button>
                            <button class="toolbar__btn" data-filter="folder">Dossiers</button>
                            <button class="toolbar__btn" data-filter="file">Fichiers</button>
                        </div>
                        <div class="toolbar__group" id="sortGroup">
                            <button class="toolbar__btn toolbar__btn--active" data-sort="date">Date</button>
                            <button class="toolbar__btn" data-sort="name">Nom</button>
                            <button class="toolbar__btn" data-sort="size">Taille</button>
                        </div>
                    </div>
                    <div class="search">
                        <span class="material-icons search__icon">search</span>
                        <input type="text" id="searchInput" class="search__input"
                            placeholder="Rechercher un projet..." />
                        <button type="button" id="searchClear" class="search__clear" aria-label="Effacer" title="Effacer la recherche">
                            <span class="material-icons">close</span>
                        </button>
                    </div>
                </div>
                <div class="projects__grid" id="projectsGrid">
                    <?php if (empty($filesData)): ?>
                        <div class="projects__empty">
                            <span class="material-icons" style="font-size:48px;display:block;margin-bottom:8px;">folder_open</span>
                            Aucun projet. Creez un dossier dans www/ pour commencer.
                        </div>
                    <?php else: ?>
                        <?php foreach ($filesData as $item): ?>
                            <a class="project__item" href="<?= htmlspecialchars($item['name']) ?>" target="_blank"
                               title="Ouvrir <?= htmlspecialchars($item['name']) ?>"
                               data-type="<?= $item['isDir'] ? 'folder' : 'file' ?>"
                               data-name="<?= htmlspecialchars($item['name']) ?>"
                               data-date="<?= $item['modTime'] ?>"
                               data-size="<?= $item['sizeBytes'] ?>">
                                <div class="project__header">
                                    <span class="material-icons project__icon <?= $item['isDir'] ? 'project__icon--folder' : 'project__icon--file' ?>">
                                        <?= $item['isDir'] ? 'folder' : 'description' ?>
                                    </span>
                                    <span class="project__name projectName"><?= htmlspecialchars($item['name']) ?></span>
                                </div>
                                <div class="project__meta">
                                    <span><?= $item['sizeTxt'] ?></span>
                                    <span><?= date('d/m/Y', $item['modTime']) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ========== 13. COMPATIBILITE SYMFONY ========== -->
            <div class="bento__card bento__card--symfony">
                <details class="symfony__details">
                    <summary class="symfony__summary">
                        <div class="card__label" style="margin-bottom:0;">
                            <span class="status-dot status-dot--<?= $symfonyReady ? 'ok' : 'ko' ?>"></span>
                            Symfony
                        </div>
                        <div class="symfony__summary-right">
                            <span class="symfony__status <?= $symfonyReady ? 'symfony__status--ready' : 'symfony__status--missing' ?>">
                                <?= $symfonyReady ? 'Pret' : 'Extensions manquantes' ?>
                                <span style="font-weight:400; color:var(--text-tertiary); font-size:0.75rem;">
                                    (<?= $symfonyScore ?>/<?= $symfonyTotal ?>)
                                </span>
                            </span>
                            <span class="material-icons symfony__chevron">expand_more</span>
                        </div>
                    </summary>
                    <div class="symfony__body">
                        <div class="symfony__header">
                            <img src="https://www.vectorlogo.zone/logos/symfony/symfony-icon.svg"
                                alt="Symfony" class="symfony__logo" />
                        </div>
                        <div class="symfony__grid">
                            <?php foreach ($symfonyChecks as $check): ?>
                                <div class="symfony__item">
                                    <span class="status-dot status-dot--<?= $check['ok'] ? 'ok' : 'ko' ?>"></span>
                                    <?= htmlspecialchars($check['name']) ?>
                                    <?php if (!$check['ok']): ?>
                                        <span class="symfony__tag <?= $check['required'] ? 'symfony__tag--req' : 'symfony__tag--rec' ?>">
                                            <?= $check['required'] ? 'requis' : 'recommande' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($symfonyMissing)): ?>
                            <div class="symfony__hint">
                                <div class="symfony__hint-title">
                                    <span class="material-icons">build</span>
                                    A ajouter dans Dockerfile.php (puis rebuild)
                                </div>
                                <?php foreach ($symfonyMissing as $m): ?>
                                    <div>RUN <code><?= htmlspecialchars($m['fix']) ?></code></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            </div>

            <!-- ========== 14. DOCKER COMPOSE STATUS ========== -->
            <div class="bento__card bento__card--docker" id="dockerCard">
                <div class="card__label">
                    <span class="material-icons" style="font-size:14px;">dns</span>
                    Conteneurs Docker
                    <span class="badge" id="dockerCount">...</span>
                </div>
                <div class="docker__grid" id="dockerGrid">
                    <div style="color:var(--text-tertiary); font-size:0.8rem;">Chargement...</div>
                </div>
            </div>

            <!-- ========== 15. LIENS UTILES ========== -->
            <div class="bento__card bento__card--links">
                <div class="card__label">Liens utiles</div>
                <div class="links__row">
                    <?php foreach ($usefulLinks as $link): ?>
                        <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" class="link__item" title="Ouvrir <?= htmlspecialchars($link['label']) ?>">
                            <?= htmlspecialchars($link['label']) ?>
                            <span class="material-icons">open_in_new</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- Theme toggle -->
    <button class="theme-toggle" id="themeToggle" title="Changer de theme">
        <span class="material-icons" id="themeIcon">light_mode</span>
    </button>

    <script>
        // ============================================================
        // 1. HORLOGE LIVE
        // ============================================================
        const clockEl = document.getElementById('liveClock');
        const dateEl = document.getElementById('liveDate');

        function updateClock() {
            const now = new Date();
            clockEl.textContent = now.toLocaleTimeString('fr-FR', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
            clockEl.dateTime = now.toISOString();
            dateEl.textContent = now.toLocaleDateString('fr-FR', {
                weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
            });
        }
        updateClock();
        setInterval(updateClock, 1000);

        // ============================================================
        // 2. PROJETS : recherche + filtre type + tri
        // ============================================================
        const grid = document.getElementById('projectsGrid');
        const allProjectItems = [...document.querySelectorAll('.project__item')];
        const countEl = document.getElementById('projectsCount');
        const searchInput = document.getElementById('searchInput');
        const searchClear = document.getElementById('searchClear');

        let currentFilter = 'all';
        let currentSort = 'date';

        function applyProjectsView() {
            const query = searchInput.value.toLowerCase();
            let visible = 0;

            // Tri
            const sorted = [...allProjectItems].sort((a, b) => {
                if (currentSort === 'name') return a.dataset.name.localeCompare(b.dataset.name);
                if (currentSort === 'size') return Number(b.dataset.size) - Number(a.dataset.size);
                return Number(b.dataset.date) - Number(a.dataset.date); // date desc
            });

            // Re-attacher dans le bon ordre
            sorted.forEach(item => grid.appendChild(item));

            // Filtre type + recherche
            sorted.forEach(item => {
                const matchType = currentFilter === 'all' || item.dataset.type === currentFilter;
                const matchSearch = item.dataset.name.toLowerCase().includes(query);
                const show = matchType && matchSearch;
                item.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            countEl.textContent = (query || currentFilter !== 'all')
                ? visible + '/' + allProjectItems.length
                : allProjectItems.length;
            searchClear.classList.toggle('search__clear--visible', query.length > 0);
        }

        // Boutons filtre type
        document.getElementById('filterGroup').addEventListener('click', e => {
            const btn = e.target.closest('[data-filter]');
            if (!btn) return;
            document.querySelectorAll('#filterGroup .toolbar__btn').forEach(b => b.classList.remove('toolbar__btn--active'));
            btn.classList.add('toolbar__btn--active');
            currentFilter = btn.dataset.filter;
            applyProjectsView();
        });

        // Boutons tri
        document.getElementById('sortGroup').addEventListener('click', e => {
            const btn = e.target.closest('[data-sort]');
            if (!btn) return;
            document.querySelectorAll('#sortGroup .toolbar__btn').forEach(b => b.classList.remove('toolbar__btn--active'));
            btn.classList.add('toolbar__btn--active');
            currentSort = btn.dataset.sort;
            applyProjectsView();
        });

        searchInput.addEventListener('input', applyProjectsView);

        searchClear.addEventListener('click', () => {
            searchInput.value = '';
            applyProjectsView();
            searchInput.focus();
        });

        applyProjectsView();

        // ============================================================
        // 3. ANIMATION BARRES AU CHARGEMENT
        // ============================================================
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.disk__bar-fill').forEach(bar => {
                const target = bar.dataset.width + '%';
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => { bar.style.width = target; });
                });
            });
        });

        // ============================================================
        // 4. AUTO-REFRESH METRIQUES (fetch toutes les 30s)
        // ============================================================
        const MAX_HISTORY = 20;
        const cpuHistory = [{v: <?= $cpuPercent ?>, t: Date.now()}];
        const ramHistory = [{v: <?= $ramPercent ?>, t: Date.now()}];

        function fmtRelTime(ms) {
            const s = Math.round(ms / 1000);
            if (s < 60) return '-' + s + 's';
            const m = Math.floor(s / 60);
            const rs = s % 60;
            return '-' + m + 'm' + (rs > 0 ? rs + 's' : '');
        }

        function drawSparkline(canvasId, history, percent) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            const dpr = window.devicePixelRatio || 1;
            const W = canvas.offsetWidth;
            const H = canvas.offsetHeight;
            if (W === 0 || H === 0) return;
            canvas.width  = W * dpr;
            canvas.height = H * dpr;
            const ctx = canvas.getContext('2d');
            ctx.scale(dpr, dpr);

            const padL = 28; // labels Y
            const padR = 10; // "now" ne déborde pas
            const padT = 7;  // demi-hauteur police (9px) + 2
            const padB = 15; // labels X temporels
            const w = W - padL - padR;
            const h = H - padT - padB;

            // Extraire valeurs et timestamps
            let data = history.map(p => p.v);
            const times = history.map(p => p.t);
            if (data.length < 2) {
                data = [percent, percent];
                times.push(Date.now());
            }

            // Couleur selon le seuil réel (indépendant de l'échelle)
            let color;
            if (percent >= 90)      color = '#ef4444';
            else if (percent >= 70) color = '#f59e0b';
            else                    color = '#06b6d4';

            const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
            const gridColor = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.07)';
            const labelColor = isDark ? 'rgba(255,255,255,0.3)' : 'rgba(0,0,0,0.3)';

            // Échelle Y dynamique
            const rawMin = Math.min(...data);
            const rawMax = Math.max(...data);
            const margin = Math.max(2, (rawMax - rawMin) * 0.2);
            const yMin = Math.max(0,   Math.floor(rawMin - margin));
            const yMax = Math.min(100, Math.ceil(rawMax  + margin));
            const yRange = yMax - yMin || 1;

            ctx.font = '9px ' + getComputedStyle(document.body).fontFamily;

            // Lignes de référence Y — seulement yMin et yMax (évite surcharge)
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            [yMax, yMin].forEach((val, idx) => {
                const yRaw = padT + h - ((val - yMin) / yRange) * h;
                // Clamp pour ne pas clipper hors zone
                const y = Math.max(padT + 4, Math.min(padT + h - 4, yRaw));
                ctx.strokeStyle = gridColor;
                ctx.lineWidth = 1;
                ctx.setLineDash([2, 3]);
                ctx.beginPath();
                ctx.moveTo(padL, y);
                ctx.lineTo(padL + w, y);
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.fillStyle = labelColor;
                ctx.fillText(val + '%', padL - 4, y);
            });

            // Axe X — labels temporels avec alignement adapté
            const now = Date.now();
            ctx.textBaseline = 'top';
            ctx.fillStyle = labelColor;
            const xLabels = [
                { i: 0,                             align: 'left'   },
                { i: Math.floor((times.length-1)/2), align: 'center' },
                { i: times.length - 1,              align: 'right'  },
            ];
            xLabels.forEach(({ i, align }) => {
                const t = times.length > 1 ? times.length - 1 : 1;
                const x = padL + (i / t) * w;
                const label = i === times.length - 1 ? 'now' : fmtRelTime(now - times[i]);
                ctx.textAlign = align;
                ctx.fillText(label, x, padT + h + 4);
            });

            // Points mis à l'échelle dynamique
            const points = data.map((v, i) => ({
                x: padL + (i / (data.length - 1)) * w,
                y: padT + h - ((Math.min(Math.max(v, yMin), yMax) - yMin) / yRange) * h,
            }));

            // Gradient de remplissage
            const grad = ctx.createLinearGradient(0, padT, 0, padT + h);
            grad.addColorStop(0, color + '44');
            grad.addColorStop(1, color + '00');

            // Aire (courbe lissée Bezier)
            ctx.beginPath();
            ctx.moveTo(points[0].x, padT + h);
            ctx.lineTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) {
                const cpx = (points[i - 1].x + points[i].x) / 2;
                ctx.bezierCurveTo(cpx, points[i - 1].y, cpx, points[i].y, points[i].x, points[i].y);
            }
            ctx.lineTo(points[points.length - 1].x, padT + h);
            ctx.closePath();
            ctx.fillStyle = grad;
            ctx.fill();

            // Ligne (courbe lissée)
            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) {
                const cpx = (points[i - 1].x + points[i].x) / 2;
                ctx.bezierCurveTo(cpx, points[i - 1].y, cpx, points[i].y, points[i].x, points[i].y);
            }
            ctx.strokeStyle = color;
            ctx.lineWidth = 1.5;
            ctx.stroke();

            // Dots sur chaque point de données
            points.forEach((p, i) => {
                const isLast = i === points.length - 1;
                ctx.beginPath();
                ctx.arc(p.x, p.y, isLast ? 3 : 2, 0, Math.PI * 2);
                ctx.fillStyle = isLast ? color : color + '88';
                ctx.fill();
            });
        }

        // Dessin initial (le DOM est déjà prêt car script en fin de body)
        requestAnimationFrame(() => {
            drawSparkline('cpuChart', cpuHistory, <?= $cpuPercent ?>);
            drawSparkline('ramChart', ramHistory, <?= $ramPercent ?>);
        });

        async function refreshMetrics() {
            try {
                const res = await fetch('?api=metrics', { cache: 'no-store' });
                if (!res.ok) return;
                const d = await res.json();

                // Uptime
                const uptimeEl = document.getElementById('liveUptime');
                if (uptimeEl) uptimeEl.textContent = d.uptime;

                // RAM
                const ramPercentEl = document.getElementById('ramPercent');
                const ramBar = document.getElementById('ramBar');
                if (ramPercentEl && d.ram.available) {
                    ramPercentEl.textContent = d.ram.percent + '%';
                    ramBar.style.width = d.ram.percent + '%';
                    ramBar.className = 'disk__bar-fill ' + d.ram.barClass;
                    document.getElementById('ramUsed').textContent = d.ram.used;
                    document.getElementById('ramFree').textContent = d.ram.free;
                    document.getElementById('ramTotal').textContent = d.ram.total;
                }

                // CPU
                const cpuPercentEl = document.getElementById('cpuPercent');
                const cpuBar = document.getElementById('cpuBar');
                if (cpuPercentEl) {
                    cpuPercentEl.textContent = d.cpu.percent + '%';
                    cpuBar.style.width = d.cpu.percent + '%';
                    cpuBar.className = 'disk__bar-fill ' + d.cpu.barClass;
                    document.getElementById('cpuLoad').textContent = d.cpu.load;
                    document.getElementById('cpuCores').textContent = d.cpu.cores;
                    cpuHistory.push({v: d.cpu.percent, t: Date.now()});
                    if (cpuHistory.length > MAX_HISTORY) cpuHistory.shift();
                    drawSparkline('cpuChart', cpuHistory, d.cpu.percent);
                }

                // RAM sparkline
                if (d.ram.available) {
                    ramHistory.push({v: d.ram.percent, t: Date.now()});
                    if (ramHistory.length > MAX_HISTORY) ramHistory.shift();
                    drawSparkline('ramChart', ramHistory, d.ram.percent);
                }
            } catch { /* silently ignore network errors */ }
        }

        refreshMetrics();
        setInterval(refreshMetrics, 10000);

        window.addEventListener('resize', () => {
            drawSparkline('cpuChart', cpuHistory, cpuHistory[cpuHistory.length - 1]?.v ?? 0);
            drawSparkline('ramChart', ramHistory, ramHistory[ramHistory.length - 1]?.v ?? 0);
        });

        // ============================================================
        // 5. THEME TOGGLE (dark/light)
        // ============================================================
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        const html = document.documentElement;

        function applyTheme(theme) {
            html.setAttribute('data-theme', theme);
            themeIcon.textContent = theme === 'light' ? 'dark_mode' : 'light_mode';
            localStorage.setItem('dashboard-theme', theme);
        }

        // Charger la preference sauvegardee
        const savedTheme = localStorage.getItem('dashboard-theme') || 'dark';
        applyTheme(savedTheme);

        themeToggle.addEventListener('click', () => {
            const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            requestAnimationFrame(() => {
                drawSparkline('cpuChart', cpuHistory, cpuHistory[cpuHistory.length - 1]?.v ?? 0);
                drawSparkline('ramChart', ramHistory, ramHistory[ramHistory.length - 1]?.v ?? 0);
            });
        });

        // ============================================================
        // 6. FAVICON DYNAMIQUE
        // ============================================================
        function setDynamicFavicon(allOk) {
            const canvas = document.createElement('canvas');
            canvas.width = 32;
            canvas.height = 32;
            const ctx = canvas.getContext('2d');

            // Fond arrondi
            ctx.beginPath();
            ctx.roundRect(0, 0, 32, 32, 6);
            ctx.fillStyle = allOk ? '#22c55e' : '#ef4444';
            ctx.fill();

            // Icone check ou croix
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 20px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(allOk ? '\u2713' : '!', 16, 17);

            // Appliquer
            let link = document.querySelector("link[rel*='icon']");
            if (!link) {
                link = document.createElement('link');
                link.rel = 'shortcut icon';
                document.head.appendChild(link);
            }
            link.type = 'image/png';
            link.href = canvas.toDataURL('image/png');
        }

        // MySQL connecte = <?= $mysqlConnected ? 'true' : 'false' ?>
        setDynamicFavicon(<?= $mysqlConnected ? 'true' : 'false' ?>);

        // ============================================================
        // 7. DOCKER COMPOSE STATUS
        // ============================================================
        async function loadDockerStatus() {
            const grid = document.getElementById('dockerGrid');
            const badge = document.getElementById('dockerCount');
            try {
                const res = await fetch('?api=docker', { cache: 'no-store' });
                if (!res.ok) throw new Error();
                const d = await res.json();

                if (!d.available || d.containers.length === 0) {
                    grid.innerHTML = '<div style="color:var(--text-tertiary); font-size:0.8rem;">API Docker non disponible (socket non monte)</div>';
                    badge.textContent = '–';
                    return;
                }

                const running = d.containers.filter(c => c.state === 'running').length;
                badge.textContent = running + '/' + d.containers.length;

                grid.innerHTML = d.containers.map(c => {
                    const dotClass = c.state === 'running' ? 'status-dot--ok' : 'status-dot--ko';
                    return `<div class="docker__service" title="${c.status}">
                        <span class="status-dot ${dotClass}"></span>
                        <span class="docker__service-name">${c.name}</span>
                    </div>`;
                }).join('');
            } catch {
                grid.innerHTML = '<div style="color:var(--text-tertiary); font-size:0.8rem;">Impossible de contacter l\'API Docker</div>';
                badge.textContent = '–';
            }
        }

        loadDockerStatus();
    </script>
</body>

</html>
