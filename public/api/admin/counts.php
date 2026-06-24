<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/helpers.php';
require_once __DIR__ . '/../../../src/Database.php';

$rows = Database::get()
    ->query('SELECT session_uid, COUNT(*) AS count FROM checkins GROUP BY session_uid')
    ->fetchAll();

$counts = [];
foreach ($rows as $row) {
    $counts[$row['session_uid']] = (int) $row['count'];
}

json_ok(['counts' => $counts]);
