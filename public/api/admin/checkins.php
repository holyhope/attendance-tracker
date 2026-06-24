<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../../src/helpers.php';
require_once __DIR__ . '/../../../src/Database.php';
require_once __DIR__ . '/../../../src/GristExport.php';

$sessionUid = trim($_GET['session_uid'] ?? '');
$format     = strtolower(trim($_GET['format'] ?? 'json'));

if (!$sessionUid) {
    json_error('session_uid is required');
}

if (!in_array($format, ['json', 'csv', 'grist'], true)) {
    json_error('format must be json, csv or grist');
}

$stmt = Database::get()->prepare("
    SELECT c.id, c.created_at, a.nickname
    FROM checkins c
    JOIN attendees a ON a.id = c.attendee_id
    WHERE c.session_uid = ?
    ORDER BY c.created_at ASC
");
$stmt->execute([$sessionUid]);
$rows = $stmt->fetchAll();

if ($format === 'csv') {
    $filename = 'presences-' . preg_replace('/[^a-z0-9_-]/i', '-', $sessionUid) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['nickname', 'created_at']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['nickname'], $row['created_at']]);
    }
    fclose($out);
    exit;
}

if ($format === 'grist') {
    export_grist($rows, $sessionUid);
}

json_ok(['checkins' => $rows]);
