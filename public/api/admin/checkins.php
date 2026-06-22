<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/helpers.php';
require_once __DIR__ . '/../../../src/Database.php';
require_once __DIR__ . '/../../../src/Auth.php';

Auth::require();

$sessionUid = trim($_GET['session_uid'] ?? '');
$format     = strtolower(trim($_GET['format'] ?? 'json'));

if (!$sessionUid) {
    json_error('session_uid is required');
}

if (!in_array($format, ['json', 'csv'], true)) {
    json_error('format must be json or csv');
}

$stmt = Database::get()->prepare("
    SELECT
        c.id,
        c.created_at,
        a.nickname,
        p.nickname AS checked_in_by
    FROM checkins c
    JOIN attendees a ON a.id = c.attendee_id
    LEFT JOIN attendees p ON p.id = c.checked_in_by
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
    fputcsv($out, ['nickname', 'checked_in_by', 'created_at']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['nickname'], $row['checked_in_by'] ?? '', $row['created_at']]);
    }
    fclose($out);
    exit;
}

json_ok(['checkins' => $rows]);
