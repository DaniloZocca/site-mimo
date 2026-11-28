<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Support\ContentClaims;
use ReflectionClassConstant;
use Tests\Feature\Characterization\Content\ContentClaimsTest;
use Tests\TestCase;

/**
 * M-84: o painel confere o texto dos materiais com a mesma lista de formas proibidas do ContentClaimsTest (M-59).
 */
class ContentClaimsListTest extends TestCase
{
    public function test_lista_do_painel_e_igual_a_do_content_claims_test(): void
    {
        $testList = (new ReflectionClassConstant(ContentClaimsTest::class, 'FORBIDDEN'))->getValue();

        $this->assertSame($testList, config('content_claims.forbidden'));
    }

    public function test_recusa_as_formas_proibidas_e_aceita_a_redacao_precisa(): void
    {
        $this->assertNotSame([], ContentClaims::brokenRules('Filamento ecológico'));
        $this->assertNotSame([], ContentClaims::brokenRules('PLA 100% biodegradável'));
        $this->assertNotSame([], ContentClaims::brokenRules('Tinta atóxica'));
        $this->assertSame([], ContentClaims::brokenRules('PLA Premium (Biodegradável em Compostagem Industrial)'));
        $this->assertSame([], ContentClaims::brokenRules('Brilho acetinado, ótimo para colecionáveis.'));
    }
}
