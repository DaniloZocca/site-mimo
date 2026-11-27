<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Catalog;

use App\Models\Product;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-77: a loja ainda não tem avaliações reais. Antes, a API mandava nota 5.0, 18 avaliações e um preço "de"
 * (preço × 1,30) para todo produto, e a loja mostrava "⭐ 5 (18 avaliações)" (propaganda enganosa, art. 37 do CDC;
 * mesma regra do M-71). Agora a API não manda esses campos e a loja só mostra a nota quando houver avaliações.
 */
#[Group('characterization')]
class ProductReviewsClaimsTest extends CharacterizationTestCase
{
    private const INVENTED_FIELDS = ['rating', 'reviewsCount', 'originalPrice'];

    public function test_lista_de_produtos_nao_tem_nota_avaliacoes_nem_preco_de_inventados(): void
    {
        Product::factory()->create(['title' => 'Vaso Origami', 'base_price_cents' => 5000]);

        $response = $this->getJson('/api/products');

        $response->assertOk()->assertJsonPath('0.name', 'Vaso Origami');
        foreach (self::INVENTED_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $response->json('0'), "Campo inventado na lista: {$field}");
        }
    }

    public function test_produto_individual_nao_tem_nota_avaliacoes_nem_preco_de_inventados(): void
    {
        $product = Product::factory()->create(['title' => 'Gato Lua', 'base_price_cents' => 4500]);

        $response = $this->getJson("/api/products/{$product->slug}");

        $response->assertOk()->assertJsonPath('name', 'Gato Lua');
        foreach (self::INVENTED_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $response->json(), "Campo inventado no produto: {$field}");
        }
    }

    public function test_loja_nao_inventa_nota_nem_avaliacoes_quando_o_produto_nao_tem(): void
    {
        $root = dirname(__DIR__, 5);
        $violations = [];

        foreach (['src/components/ProductCard.jsx', 'src/components/ProductDetailModal.jsx'] as $relative) {
            $source = (string) file_get_contents("{$root}/{$relative}");
            $this->assertStringContainsString('reviewsCount > 0', $source, "{$relative}: a nota deveria aparecer só com avaliações reais.");

            // Valor padrão inventado, como `rating || 5.0` ou `reviewsCount || 1`
            if (preg_match('/\b(?:rating|reviewsCount)\s*(?:\|\||\?\?)\s*\d/', $source) === 1) {
                $violations[] = $relative;
            }
        }

        $this->assertSame([], $violations, 'Nota ou número de avaliações com valor padrão inventado (M-77): '.implode(', ', $violations));
    }
}
