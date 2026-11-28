<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Models\Color;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * M-84: materiais cadastrados no painel (tabela `materials`) no checkout e na loja.
 */
class MaterialsCheckoutAndCatalogTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private const INACTIVE_MESSAGE = 'Esse material não está mais disponível. Tire o item da sacola e escolha outro.';

    private function silk(array $attributes = []): Material
    {
        return Material::factory()->create($attributes + [
            'code' => 'pla-silk',
            'short_name' => 'PLA Silk',
            'full_name' => 'PLA Silk (Acabamento Acetinado)',
            'description' => 'Brilho acetinado.',
        ]);
    }

    /**
     * @param  array<int, string>  $materials
     * @return array{product: Product, color: Color}
     */
    private function productIn(array $materials, string $colorMaterial): array
    {
        $product = Product::factory()->materials($materials)->create(['title' => 'Vaso Origami']);
        $color = Color::factory()->material($colorMaterial)->create(['name' => 'Pérola']);
        $product->colors()->attach($color->id);

        return ['product' => $product, 'color' => $color];
    }

    public function test_migration_cria_os_4_materiais_de_antes_com_os_mesmos_codigos_e_textos(): void
    {
        $this->assertTrue(Schema::hasTable('materials'));

        $rows = DB::table('materials')->orderBy('sort_order')->get(['code', 'short_name', 'full_name', 'description', 'is_active'])
            ->map(fn ($row): array => array_merge((array) $row, ['is_active' => (bool) $row->is_active]))->all();

        $this->assertSame([
            ['code' => 'pla', 'short_name' => 'PLA', 'full_name' => 'PLA Premium (Biodegradável em Compostagem Industrial)', 'description' => 'Polímero de origem vegetal, biodegradável em compostagem industrial. Ideal para decoração, vasos e action figures.', 'is_active' => true],
            ['code' => 'petg', 'short_name' => 'PETG', 'full_name' => 'PETG Resistente (Uso Externo & Térmico)', 'description' => 'Alta resistência ao impacto e raios UV. Ideal para peças funcionais, suportes e uso automotivo.', 'is_active' => true],
            ['code' => 'abs', 'short_name' => 'ABS', 'full_name' => 'ABS Industrial (Alta Resistência Mecânica)', 'description' => 'Resiste a temperaturas de até 90°C. Acabamento rígido e excelente durabilidade mecânica.', 'is_active' => true],
            ['code' => 'tpu', 'short_name' => 'TPU Flexível', 'full_name' => 'TPU Flexível (Emborrachado & Maleável)', 'description' => 'Filamento elastômero flexível. Resistente a torção, impactos e absorção de choque.', 'is_active' => true],
        ], $rows);
    }

    public function test_checkout_com_material_novo_ativo_cria_pedido_com_o_nome_da_epoca(): void
    {
        $this->silk();
        ['product' => $product, 'color' => $color] = $this->productIn(['pla-silk'], 'pla-silk');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9050, 'pending', statusDetail: 'pending_waiting_transfer'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, material: 'pla-silk')]))
            ->assertStatus(201);

        $this->assertSame('PLA Silk (Acabamento Acetinado)', OrderItem::sole()->material_name_snapshot);
    }

    public function test_checkout_com_material_desativado_responde_422_com_a_mensagem_para_o_cliente(): void
    {
        $silk = $this->silk();
        ['product' => $product, 'color' => $color] = $this->productIn(['pla-silk'], 'pla-silk');
        $silk->update(['is_active' => false]);

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, material: 'pla-silk')]))
            ->assertStatus(422)
            ->assertJsonPath('message', self::INACTIVE_MESSAGE)
            ->assertJsonPath('errors', ['items.0.material' => [self::INACTIVE_MESSAGE]]);

        $this->assertSame(0, Order::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_material_inexistente_continua_com_a_mensagem_de_sempre(): void
    {
        ['product' => $product, 'color' => $color] = $this->productIn(['pla'], 'pla');

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, material: 'resina')]))
            ->assertStatus(422)
            ->assertJsonPath('errors', ['items.0.material' => ['O valor escolhido em material é inválido.']]);
    }

    public function test_validacao_dos_materiais_faz_uma_consulta_so_com_varias_linhas(): void
    {
        ['product' => $product, 'color' => $color] = $this->productIn(['pla'], 'pla');
        $lines = array_fill(0, 20, $this->itemPayload($product, $color, material: 'resina'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson('/api/checkout', $this->checkoutPayload($lines))->assertStatus(422);
        $materialQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], '"materials"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $materialQueries);
    }

    public function test_pedido_antigo_de_material_desativado_e_apagado_continua_com_o_mesmo_nome(): void
    {
        $silk = $this->silk();
        ['product' => $product, 'color' => $color] = $this->productIn(['pla-silk'], 'pla-silk');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9050, 'pending', statusDetail: 'pending_waiting_transfer'));
        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, material: 'pla-silk')]))->assertStatus(201);
        $order = Order::sole();

        $silk->update(['is_active' => false]);
        $color->delete();
        $product->update(['allowed_materials' => ['pla']]);
        $this->assertTrue($silk->fresh()->delete());

        $this->assertSame('PLA Silk (Acabamento Acetinado)', $order->items()->sole()->material_name_snapshot);
        $this->postJson('/api/orders/track', ['tracking_token' => $order->tracking_token])
            ->assertOk()
            ->assertJsonPath('items.0.material', 'PLA Silk (Acabamento Acetinado)');
    }

    public function test_loja_mostra_material_novo_e_esconde_desativado_e_suas_cores(): void
    {
        $silk = $this->silk();
        $product = Product::factory()->materials(['pla', 'pla-silk'])->create();
        $pla = Color::factory()->create(['name' => 'Lilás']);
        $silkColor = Color::factory()->material('pla-silk')->create(['name' => 'Pérola']);
        $product->colors()->attach([$pla->id, $silkColor->id]);

        $data = $this->getJson('/api/products')->assertOk()->json('0');
        $this->assertSame(['pla', 'pla-silk'], array_column($data['materials'], 'id'));
        $this->assertSame(['id' => 'pla-silk', 'name' => 'PLA Silk', 'full_name' => 'PLA Silk (Acabamento Acetinado)', 'description' => 'Brilho acetinado.'], $data['materials'][1]);
        $this->assertEqualsCanonicalizing(['Lilás', 'Pérola'], array_column($data['colors'], 'name'));

        $silk->update(['is_active' => false]);

        $data = $this->getJson('/api/products')->assertOk()->json('0');
        $this->assertSame(['pla'], array_column($data['materials'], 'id'));
        $this->assertSame(['pla'], $data['allowed_materials']);
        $this->assertSame(['Lilás'], array_column($data['colors'], 'name'));
    }

    public function test_peca_com_todos_os_materiais_desativados_sai_da_loja_e_o_checkout_recusa(): void
    {
        $silk = $this->silk();
        ['product' => $product, 'color' => $color] = $this->productIn(['pla-silk'], 'pla-silk');
        $other = Product::factory()->create();

        $this->assertCount(2, $this->getJson('/api/products')->assertOk()->json());

        $silk->update(['is_active' => false]);

        $this->assertSame([(string) $other->id], array_column($this->getJson('/api/products')->assertOk()->json(), 'id'));
        $this->getJson('/api/products/'.$product->slug)->assertNotFound();
        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, material: 'pla-silk')]))
            ->assertStatus(422)
            ->assertJsonPath('message', self::INACTIVE_MESSAGE);
        $this->assertSame(0, Order::count());
    }

    public function test_reenvio_com_a_mesma_chave_depois_de_desativar_o_material_devolve_a_resposta_do_pedido(): void
    {
        $silk = $this->silk();
        ['product' => $product, 'color' => $color] = $this->productIn(['pla-silk'], 'pla-silk');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9050, 'pending', statusDetail: 'pending_waiting_transfer'));
        $payload = $this->checkoutPayload([$this->itemPayload($product, $color, material: 'pla-silk')]);
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key])->assertStatus(201);
        $silk->update(['is_active' => false]);
        $second = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key])
            ->assertStatus(201)
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, Order::count());
        $this->assertCount(1, $this->mpHttp->requests);
    }
}
