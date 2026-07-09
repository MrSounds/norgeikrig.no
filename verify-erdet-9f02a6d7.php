<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';

if ($token !== '9f02a6d7') {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

require_once __DIR__ . '/app/bootstrap.php';

$config = erdet_config();
$status = erdet_get_war_status(true);
$exercises = erdet_get_military_exercise_notices(true);
$mailResult = null;

if (($_GET['mail'] ?? '') === '1') {
    $mailResult = erdet_send_alert_notification([
        'title' => 'SMTP-test fra erdetkriginorge.no',
        'description' => 'Dette er en kontrollert test av e-postvarsling. Dette er ikke et reelt varsel.',
        'link' => 'https://erdetkriginorge.no/',
        'publishedAt' => erdet_now_iso(),
    ], [
        'classification' => 'uncertain',
        'confidence' => 'low',
        'appliesToNorwayNow' => false,
        'explicitWarOrArmedAttack' => false,
        'isTestOrExercise' => true,
        'reason' => 'Kontrollert SMTP-test. Skal ikke påvirke offentlig status.',
        'model' => 'manual-test',
        'checkedAt' => erdet_now_iso(),
    ]);
}

echo json_encode([
    'php' => PHP_VERSION,
    'curl' => function_exists('curl_init'),
    'simplexml' => function_exists('simplexml_load_string'),
    'openaiConfigured' => (string) ($config['openai_api_key'] ?? '') !== '',
    'smtpConfigured' => (string) ($config['smtp_user'] ?? '') !== '' && (string) ($config['smtp_password'] ?? '') !== '',
    'status' => [
        'label' => $status['label'],
        'sourceState' => $status['source']['state'] ?? null,
        'activeAlerts' => count($status['activeAlerts'] ?? []),
        'checkedAt' => $status['checkedAt'] ?? null,
    ],
    'exercises' => [
        'count' => count($exercises),
        'items' => array_map(static function (array $notice): array {
            return [
                'title' => $notice['title'] ?? '',
                'location' => $notice['location'] ?? null,
                'dateText' => $notice['dateText'] ?? null,
            ];
        }, $exercises),
    ],
    'mail' => $mailResult,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
