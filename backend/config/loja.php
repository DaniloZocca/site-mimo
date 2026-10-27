<?php

declare(strict_types=1);

/*
 * Dados da loja que aparecem no rodapé dos e-mails (M-41). Ficam em branco até o Zocca preencher o .env de
 * produção: `mimoo:check-production` reprova sem razão social e CNPJ. Nunca coloque dado inventado aqui.
 * Os mesmos dados, para as páginas da loja, ficam em `src/config/loja.js`.
 */
return [
    'razao_social' => trim((string) env('LOJA_RAZAO_SOCIAL', '')),
    'cnpj' => trim((string) env('LOJA_CNPJ', '')),
    'cidade_uf' => trim((string) env('LOJA_CIDADE_UF', '')),
];
