<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * M-84: materiais cadastráveis no painel. Cores (`colors.material_type`) e peças (`products.allowed_materials`)
 * já guardam o código do material; a tabela nasce com os 4 de antes, com o mesmo código e os mesmos textos.
 * Os valores ficam escritos aqui (sem ler o enum `MaterialType`) para a migration não mudar se o enum mudar.
 */
return new class extends Migration
{
    private const INITIAL_MATERIALS = [
        [
            'code' => 'pla',
            'short_name' => 'PLA',
            'full_name' => 'PLA Premium (Biodegradável em Compostagem Industrial)',
            'description' => 'Polímero de origem vegetal, biodegradável em compostagem industrial. Ideal para decoração, vasos e action figures.',
        ],
        [
            'code' => 'petg',
            'short_name' => 'PETG',
            'full_name' => 'PETG Resistente (Uso Externo & Térmico)',
            'description' => 'Alta resistência ao impacto e raios UV. Ideal para peças funcionais, suportes e uso automotivo.',
        ],
        [
            'code' => 'abs',
            'short_name' => 'ABS',
            'full_name' => 'ABS Industrial (Alta Resistência Mecânica)',
            'description' => 'Resiste a temperaturas de até 90°C. Acabamento rígido e excelente durabilidade mecânica.',
        ],
        [
            'code' => 'tpu',
            'short_name' => 'TPU Flexível',
            'full_name' => 'TPU Flexível (Emborrachado & Maleável)',
            'description' => 'Filamento elastômero flexível. Resistente a torção, impactos e absorção de choque.',
        ],
    ];

    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Mesmo tamanho de colors.material_type; só [a-z0-9-], não muda depois de criado
            $table->string('code', 30)->unique();
            $table->string('short_name', 40);
            $table->string('full_name', 120);
            $table->text('description');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [];
        foreach (self::INITIAL_MATERIALS as $position => $material) {
            $rows[] = $material + [
                'id' => (string) Str::orderedUuid(),
                'is_active' => true,
                'sort_order' => $position + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('materials')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
