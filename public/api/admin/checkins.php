<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../../src/helpers.php';
require_once __DIR__ . '/../../../src/Database.php';
require_once __DIR__ . '/../../../src/GristExport.php';
require_once __DIR__ . '/../../../src/Calendar.php';
require_once __DIR__ . '/../../../src/Geocoder.php';

$config     = require __DIR__ . '/../../../config.php';
$sessionUid = trim($_GET['session_uid'] ?? '');
$format     = strtolower(trim($_GET['format'] ?? ''));

// Export — sessions visible in the calendar (same window as the admin page)
if ($format === 'csv' || $format === 'grist') {
    preg_match('/^([a-z]{2})/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'fr', $m);
    $lang = in_array(strtolower($m[1] ?? 'fr'), ['en']) ? 'en' : 'fr';

    $calendar = new Calendar(
        $config['calendar_url'],
        $config['cache_path'],
        filter:      $config['event_filter']         ?? [],
        labelFormat: $config['session_label_format'] ?? '{date} — {title}',
    );
    try {
        $sessions = $calendar->getSessions($lang);
    } catch (RuntimeException) {
        $sessions = [];
    }

    // uid => label / location from calendar
    $sessionLabels    = array_column($sessions, 'label',    'uid');
    $sessionLocations = array_column($sessions, 'location', 'uid');

    // Geocode each unique location
    $geocacheFile  = dirname($config['cache_path']) . '/geocode.json';
    $sessionCoords = [];
    foreach ($sessions as $s) {
        $sessionCoords[$s['uid']] = geocode($s['location'] ?? '', $geocacheFile);
    }

    if (!$sessionLabels) {
        header('Content-Type: text/plain'); echo 'Aucune séance disponible.'; exit;
    }

    $placeholders = implode(',', array_fill(0, count($sessionLabels), '?'));
    $stmt = Database::get()->prepare("
        SELECT c.id, c.session_uid, c.created_at, a.nickname
        FROM checkins c
        JOIN attendees a ON a.id = c.attendee_id
        WHERE c.session_uid IN ($placeholders)
        ORDER BY c.session_uid, c.created_at ASC
    ");
    $stmt->execute(array_keys($sessionLabels));
    $allRows = $stmt->fetchAll();

    $baseName = preg_replace('/[^a-z0-9]+/i', '-', $config['association_name']);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$baseName}-presences.csv\"");
        $out = fopen('php://output', 'w');
        fputcsv($out, ['seance', 'pseudonyme', 'date']);
        foreach ($allRows as $row) {
            fputcsv($out, [
                $sessionLabels[$row['session_uid']],
                $row['nickname'],
                $row['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    export_grist($allRows, $sessionLabels, $sessionLocations, $sessionCoords, $baseName);
}

// Internal JSON endpoint (used by the admin JS to refresh the table)
if (!$sessionUid) {
    json_error('session_uid is required');
}

$stmt = Database::get()->prepare('
    SELECT c.id, c.created_at, a.nickname
    FROM checkins c
    JOIN attendees a ON a.id = c.attendee_id
    WHERE c.session_uid = ?
    ORDER BY c.created_at ASC
');
$stmt->execute([$sessionUid]);

json_ok(['checkins' => $stmt->fetchAll()]);
