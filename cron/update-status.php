<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

$status = erdet_get_war_status(true);
erdet_get_military_exercise_notices(true);

echo '[' . erdet_now_iso() . '] ' . $status['label'] . ' - ' . $status['message'] . PHP_EOL;

