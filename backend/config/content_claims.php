<?php

declare(strict_types=1);

/*
 * M-59: alegações ambientais ou de saúde proibidas no que o cliente vê (sem comprovação documentada).
 * Regra => expressão regular aplicada ao texto em minúsculas e sem acento. Mesma lista do
 * `ContentClaimsTest` (o `ContentClaimsListTest` confere que são iguais). Fica em config/ porque o
 * `ContentClaimsTest` varre `app/` e reprovaria as próprias palavras.
 * Usada pelo painel (M-84) para recusar texto de material com essas formas.
 */
return [
    'forbidden' => [
        'biodegradável sem "em compostagem industrial"' => '/biodegrad\w*+(?!\s+em\s+compostagem\s+industrial)/u',
        '"100%" junto de alegação ambiental' => '/100\s*%[^.\n]{0,40}?(biodegrad|compost|energia|renovav|vegetal|ecologic|sustentav)/u',
        'energia limpa/renovável/verde' => '/energia\s+(limpa|renovav\w*|verde)/u',
        'atóxico' => '/atoxic/u',
        'ecológico' => '/ecologic/u',
        'sustentável' => '/sustentav/u',
        'plástico jogado fora / desperdício de plástico' => '/plastico\s+jogado\s+fora|desperdicio\s+de\s+plastico/u',
        'origem específica do PLA (amido, milho, cana)' => '/\bamido\b|\bmilho\b|cana-de-acucar|\bcana\b/u',
    ],
];
