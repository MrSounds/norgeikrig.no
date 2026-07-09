<?php

declare(strict_types=1);

const ERDET_OPENAI_RESPONSES_URL = 'https://api.openai.com/v1/responses';

function erdet_classify_alert_with_ai(array $alert): array
{
    $config = erdet_config();
    $checkedAt = erdet_now_iso();
    $model = (string) $config['openai_model'];
    $apiKey = (string) $config['openai_api_key'];

    if ($apiKey === '') {
        return erdet_uncertain_review($checkedAt, $model, 'OPENAI_API_KEY mangler. Aktivt varsel med triggerord maa vurderes manuelt.', 'OPENAI_API_KEY is not configured');
    }

    try {
        $data = erdet_post_json(ERDET_OPENAI_RESPONSES_URL, [
            'model' => $model,
            'instructions' => 'Du er en streng klassifikator for en offentlig norsk statusside. Du skal ikke vaere hjelpsom eller spekulativ. Svar confirmed_yes bare hvis aktivt varsel eksplisitt betyr at Norge naa er i krig eller under vaepnet/militaert angrep. Hvis varselet er test, oevelse, historisk omtale, utenlandsk forhold, beredskap, trussel, medieomtale, forebyggende raad, eller uklart: svar no eller uncertain. Ved tvil: uncertain. Ikke sett confirmed_yes bare fordi ordet krig eller angrep forekommer.',
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => erdet_build_alert_prompt($alert),
                ]],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'war_alert_classification',
                    'strict' => true,
                    'schema' => erdet_classification_schema(),
                ],
            ],
            'max_output_tokens' => 500,
        ], [
            'Authorization: Bearer ' . $apiKey,
        ]);

        $parsed = erdet_parse_model_classification(erdet_extract_output_text($data));

        return erdet_enforce_conservative_review($parsed, $checkedAt, $model);
    } catch (Throwable $error) {
        return erdet_uncertain_review($checkedAt, $model, 'OpenAI-klassifisering feilet. Aktivt varsel med triggerord maa vurderes manuelt.', erdet_error_message($error));
    }
}

function erdet_classification_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => [
            'classification',
            'confidence',
            'applies_to_norway_now',
            'explicit_war_or_armed_attack',
            'is_test_or_exercise',
            'reason',
        ],
        'properties' => [
            'classification' => [
                'type' => 'string',
                'enum' => ['confirmed_yes', 'uncertain', 'no'],
            ],
            'confidence' => [
                'type' => 'string',
                'enum' => ['low', 'medium', 'high'],
            ],
            'applies_to_norway_now' => ['type' => 'boolean'],
            'explicit_war_or_armed_attack' => ['type' => 'boolean'],
            'is_test_or_exercise' => ['type' => 'boolean'],
            'reason' => ['type' => 'string'],
        ],
    ];
}

function erdet_build_alert_prompt(array $alert): string
{
    return implode("\n", [
        'Vurder dette aktive varselet fra Nødvarsel.',
        '',
        'Tittel: ' . ($alert['title'] ?: '(tom)'),
        'Beskrivelse: ' . ($alert['description'] ?: '(tom)'),
        'Lenke: ' . ($alert['link'] ?: '(tom)'),
        'Publisert: ' . ($alert['publishedAt'] ?: '(ukjent)'),
        '',
        "Spørsmål: Betyr dette aktive varselet at Norge akkurat naa er i krig eller under vaepnet/militaert angrep, slik at en norsk nettside boer svare JA paa 'Er det krig i Norge naa?'",
    ]);
}

function erdet_extract_output_text(array $data): string
{
    if (isset($data['output_text']) && is_string($data['output_text'])) {
        return $data['output_text'];
    }

    foreach (($data['output'] ?? []) as $output) {
        foreach (($output['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text']) && is_string($content['text'])) {
                return $content['text'];
            }
        }
    }

    throw new RuntimeException('OpenAI-respons manglet output_text');
}

function erdet_parse_model_classification(string $text): array
{
    $parsed = json_decode($text, true);

    if (!is_array($parsed)) {
        throw new RuntimeException('OpenAI-respons hadde ugyldig JSON');
    }

    $classification = $parsed['classification'] ?? null;
    $confidence = $parsed['confidence'] ?? null;

    if (
        !in_array($classification, ['confirmed_yes', 'uncertain', 'no'], true) ||
        !in_array($confidence, ['low', 'medium', 'high'], true) ||
        !is_bool($parsed['applies_to_norway_now'] ?? null) ||
        !is_bool($parsed['explicit_war_or_armed_attack'] ?? null) ||
        !is_bool($parsed['is_test_or_exercise'] ?? null) ||
        !is_string($parsed['reason'] ?? null)
    ) {
        throw new RuntimeException('OpenAI-respons hadde ugyldig schema');
    }

    return $parsed;
}

function erdet_enforce_conservative_review(array $parsed, string $checkedAt, string $model): array
{
    $review = [
        'classification' => $parsed['classification'],
        'confidence' => $parsed['confidence'],
        'appliesToNorwayNow' => $parsed['applies_to_norway_now'],
        'explicitWarOrArmedAttack' => $parsed['explicit_war_or_armed_attack'],
        'isTestOrExercise' => $parsed['is_test_or_exercise'],
        'reason' => $parsed['reason'],
        'model' => $model,
        'checkedAt' => $checkedAt,
    ];

    if ($review['isTestOrExercise']) {
        $review['classification'] = 'no';
        $review['reason'] .= ' Klassifisering overstyrt til no fordi varselet er test eller oevelse.';

        return $review;
    }

    if (
        $review['classification'] === 'confirmed_yes' &&
        (!$review['appliesToNorwayNow'] || !$review['explicitWarOrArmedAttack'] || $review['confidence'] !== 'high')
    ) {
        $review['classification'] = 'uncertain';
        $review['reason'] .= ' Klassifisering nedgradert til uncertain fordi alle sikkerhetskrav for JA ikke var oppfylt.';
    }

    return $review;
}

function erdet_uncertain_review(string $checkedAt, string $model, string $reason, ?string $error = null): array
{
    $review = [
        'classification' => 'uncertain',
        'confidence' => 'low',
        'appliesToNorwayNow' => false,
        'explicitWarOrArmedAttack' => false,
        'isTestOrExercise' => false,
        'reason' => $reason,
        'model' => $model,
        'checkedAt' => $checkedAt,
    ];

    if ($error) {
        $review['error'] = $error;
    }

    return $review;
}

