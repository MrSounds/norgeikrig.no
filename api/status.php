<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

erdet_json_response(erdet_get_war_status());

