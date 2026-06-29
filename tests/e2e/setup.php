<?php
declare(strict_types=1);

$rootDir  = realpath(__DIR__ . '/../../');
$distDir  = rtrim(realpath($argv[1] ?? "$rootDir/dist"), '/');
$icsPath  = realpath(__DIR__ . '/../fixtures/demo.ics');
$dbPath   = "$distDir/data/demo.db";
$cachePath = "$distDir/cache/demo.ics.cache";

foreach (['data', 'cache'] as $dir) {
    is_dir("$distDir/$dir") || mkdir("$distDir/$dir", 0755, true);
}

// Pre-populate the ICS cache so Calendar never makes a network call
copy($icsPath, $cachePath);

// Propagate optional keys from the real config
$rootConfig  = is_file("$rootDir/config.php") ? (require "$rootDir/config.php") : [];
$optionalKeys = ['icon_url', 'site_url', 'nav_links'];
$extraLines  = '';
foreach ($optionalKeys as $key) {
    if (!array_key_exists($key, $rootConfig)) continue;
    $value = $rootConfig[$key];
    // Copy local assets to dist/www/assets/
    if ($key === 'icon_url' && is_string($value) && str_starts_with($value, '/')) {
        $src = "$rootDir/public$value";
        if (is_file($src)) copy($src, "$distDir/www/assets/" . basename($value));
    }
    $extraLines .= "\n    " . var_export($key, true) . ' => ' . var_export($value, true) . ',';
}

$configContent = <<<PHP
<?php
return [
    'association_name'     => 'Association Démo',
    'db_dsn'               => 'sqlite:$dbPath',
    'cache_path'           => '$cachePath',
    'calendar_url'         => 'file://$icsPath',
    'session_label_format' => '{date:EEEE d MMMM yyyy}',
    'show_location'        => false,$extraLines
];
PHP;
file_put_contents("$distDir/config.php", $configContent);

// Bootstrap classes without going through Database::get() (which reads config.php from src/../)
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/Database.php';

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys=ON');

$migrate = new ReflectionMethod(Database::class, 'migrate');
$migrate->invoke(null, $pdo);

// Find the two most recent Mondays and Wednesdays within the last 30 days
$now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$days = [];
for ($i = 0; $i <= 30; $i++) {
    $d = $now->modify("-$i days");
    $dow = (int) $d->format('N');
    if ($dow === 1 || $dow === 3) {
        $days[] = $d;
    }
    if (count($days) >= 4) break;
}

$sessions = array_map(
    fn($d) => 'sps-demo-weekly_' . $d->format('Ymd'),
    $days
);

// Demo attendees
$attendees = ['Alice Martin', 'Bob Dupont', 'Claire Bernard', 'David Petit', 'Emma Leroy'];
foreach ($attendees as $name) {
    $pdo->prepare('INSERT OR IGNORE INTO attendees (id, nickname) VALUES (?, ?)')->execute([uuid4(), $name]);
}
$rows = $pdo->query('SELECT id FROM attendees')->fetchAll(PDO::FETCH_COLUMN);

// Populate the two most recent sessions with checkins
$times = ['19:05', '19:08', '19:12', '19:15', '19:22'];
foreach (array_slice($sessions, 0, 2) as $i => $sessionUid) {
    $date = substr($sessionUid, -8);
    $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
    foreach (array_slice($rows, 0, $i === 0 ? 5 : 3) as $j => $attendeeId) {
        try {
            $pdo->prepare("INSERT INTO checkins (id, session_uid, attendee_id, created_at) VALUES (?, ?, ?, ?)")
                ->execute([uuid4(), $sessionUid, $attendeeId, "$date {$times[$j]}:00"]);
        } catch (PDOException) {}
    }
}

echo "Demo environment ready in $distDir\n";
