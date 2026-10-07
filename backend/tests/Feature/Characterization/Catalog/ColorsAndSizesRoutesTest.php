<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Catalog;

use App\Models\Color;
use App\Models\Size;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * /api/colors e /api/sizes listam todos os registros: as tabelas não têm
 * coluna is_active (antes, o filtro por ela devolvia [] no SQLite e 500 no Postgres).
 */
#[Group('characterization')]
class ColorsAndSizesRoutesTest extends CharacterizationTestCase
{
    public function test_colors_lista_todas_as_cores_com_in_stock(): void
    {
        Color::factory()->create(['name' => 'Rosa Bebê', 'hex_code' => '#F9C5D1', 'in_stock' => true]);
        Color::factory()->create(['name' => 'Azul Céu', 'hex_code' => '#A7C7E7', 'in_stock' => false]);

        $response = $this->getJson('/api/colors');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Azul Céu')
            ->assertJsonPath('0.in_stock', false)
            ->assertJsonPath('1.name', 'Rosa Bebê')
            ->assertJsonPath('1.in_stock', true);
    }

    public function test_sizes_lista_todos_os_tamanhos(): void
    {
        Size::factory()->create(['name' => 'Pequeno', 'price_modifier_in_cents' => 0]);
        Size::factory()->create(['name' => 'Grande', 'price_modifier_in_cents' => 1500]);

        $response = $this->getJson('/api/sizes');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Grande')
            ->assertJsonPath('0.price_modifier_in_cents', 1500)
            ->assertJsonPath('1.name', 'Pequeno');
    }

    public function test_listas_vazias_devolvem_array_vazio(): void
    {
        $this->getJson('/api/colors')->assertOk()->assertExactJson([]);
        $this->getJson('/api/sizes')->assertOk()->assertExactJson([]);
    }
}
