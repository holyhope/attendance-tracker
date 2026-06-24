<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/Calendar.php';

$config   = require __DIR__ . '/../../config.php';
$calendar = new Calendar(
    $config['calendar_url'],
    $config['cache_path'],
    filter:      $config['event_filter']      ?? [],
    labelFormat: $config['session_label_format'] ?? '{datetime} — {title}',
);

preg_match('/^([a-z]{2})/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'fr', $m);
$lang = in_array(strtolower($m[1] ?? 'fr'), ['en']) ? 'en' : 'fr';

try {
    json_ok([
        'association_name' => $config['association_name'],
        'sessions'         => $calendar->getSessions($lang),
    ]);
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 503);
}
