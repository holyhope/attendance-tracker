<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/helpers.php';
require_once __DIR__ . '/../../../src/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$body      = request_body();
$checkinId = trim($body['checkin_id'] ?? '');

if (!$checkinId) {
    json_error('checkin_id is required');
}

$stmt = Database::get()->prepare('DELETE FROM checkins WHERE id = ?');
$stmt->execute([$checkinId]);

if ($stmt->rowCount() === 0) {
    json_error('Check-in not found', 404);
}

json_ok();
