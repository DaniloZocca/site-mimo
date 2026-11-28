<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primary = $this->primaryImage
            ?? $this->images->where('is_primary', true)->first()
            ?? $this->images->first();

        $primaryImgPath = $primary?->image_path;
        $baseUrl = config('app.url', 'http://localhost:8000');

        $resolveUrl = function (?string $path) use ($baseUrl): string {
            if (empty($path)) {
                return '/assets/products/cow_planter.jpg';
            }
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }

            return rtrim($baseUrl, '/').'/storage/'.ltrim($path, '/');
        };

        $imageUrl = $resolveUrl($primaryImgPath);

        $gallery = $this->images->map(function ($img) use ($resolveUrl): string {
            return $resolveUrl($img->image_path);
        })->values()->toArray();

        if (empty($gallery)) {
            $gallery = [$imageUrl];
        }

        $hours = $this->estimated_print_time_hours ?: 14;

        // Dynamic category resolution
        $firstCat = $this->categories->first();
        $categorySlug = match ($firstCat?->slug) {
            'decoracao', 'decor' => 'decor',
            'suportes-geek', 'geek' => 'geek',
            'articulados-toys', 'articulated' => 'articulated',
            default => $firstCat?->slug ?? 'decor',
        };
        $categoryName = $firstCat?->name ?? 'Decoração';

        // Materiais ativos (M-84): cor de material desativado não vai para a loja
        $activeMaterials = Material::activeByCode($request);

        // Filament colors mapping with material_type for client filtering
        $colorsList = $this->colors
            ->filter(fn (Color $c): bool => $activeMaterials->has((string) $c->material_type))
            ->map(function (Color $c): array {
                return [
                    'id' => (string) $c->id,
                    'name' => $c->name,
                    'hex' => $c->hex_code,
                    'material_type' => (string) $c->material_type,
                    'tag' => $c->tag ?: 'Disponível',
                    'isActive' => (bool) $c->is_active,
                ];
            })->values()->toArray();

        // Tamanhos que deixariam a peça abaixo de 1 centavo (base + acréscimo) não são vendáveis: o checkout recusa (M-29)
        $basePriceCents = (int) $this->base_price_cents;
        $sellableSizes = $this->sizes->filter(
            fn (Size $s): bool => $basePriceCents + (int) ($s->price_modifier_in_cents ?? $s->pivot->price_modifier_in_cents ?? 0) >= 1
        );

        // Sizes mapping
        $sizesList = $sellableSizes->map(function (Size $s): array {
            $modifierCents = (int) ($s->price_modifier_in_cents ?? $s->pivot->price_modifier_in_cents ?? 0);
            $modifierHours = (int) ($s->time_modifier_hours ?? $s->pivot->time_modifier_hours ?? 0);

            return [
                'id' => (string) $s->id,
                'label' => $s->name,
                'dimensions' => $s->dimensions ?: 'Sob Medida',
                'addPrice' => (float) ($modifierCents / 100),
                'addTime' => $modifierHours,
                'price_modifier_cents' => $modifierCents,
                'multiplier' => 1.0 + ($modifierCents > 0 ? 0.3 : 0),
            ];
        })->values()->toArray();

        // Fallback size if none linked
        if (empty($sizesList)) {
            $sizesList = [
                [
                    'id' => 'p',
                    'label' => 'Padrão (12cm)',
                    'dimensions' => '12 x 10 x 8 cm',
                    'addPrice' => 0.0,
                    'addTime' => 0,
                    'price_modifier_cents' => 0,
                    'multiplier' => 1.0,
                ],
            ];
        }

        // Materials rich objects mapping (só os ativos, M-84)
        $materialsList = collect(static::activeMaterialCodes($this->allowed_materials, $activeMaterials))
            ->map(function (string $code) use ($activeMaterials): array {
                /** @var Material $material */
                $material = $activeMaterials->get($code);

                return [
                    'id' => $material->code,
                    'name' => $material->short_name,
                    'full_name' => $material->full_name,
                    'description' => $material->description,
                ];
            })->values()->toArray();

        $priceInReais = round(((int) $this->base_price_cents) / 100, 2);
        $rawDescription = (string) ($this->description ?? '');

        return [
            'id' => (string) $this->id,
            'slug' => (string) $this->slug,
            'name' => (string) $this->title,
            'title' => (string) $this->title,
            'description' => $rawDescription,
            'description_excerpt' => Str::limit(strip_tags($rawDescription), 120),
            'shortDescription' => strip_tags($rawDescription ?: 'Peça artesanal feita sob encomenda em impressão 3D.'),
            'fullDescription' => $rawDescription ?: 'Modelagem de alta resolução feita sob encomenda em filamento PLA premium.',
            'tagline' => 'Fabricado sob encomenda em impressão 3D ✨',
            'category' => $categorySlug,
            'categoryName' => $categoryName,
            'categories' => $this->categories->map(fn (Category $category): array => [
                'id' => (string) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
            ])->values()->toArray(),
            'colors' => $colorsList,
            'sizeOptions' => $sizesList,
            'sizes' => $sellableSizes->map(fn (Size $size): array => [
                'id' => (string) $size->id,
                'name' => (string) $size->name,
                'dimensions' => (string) ($size->dimensions ?? 'Sob Medida'),
                'price_modifier_cents' => (int) ($size->price_modifier_in_cents ?? $size->pivot->price_modifier_in_cents ?? 0),
                'price_modifier' => (float) (($size->price_modifier_in_cents ?? $size->pivot->price_modifier_in_cents ?? 0) / 100),
            ])->values()->toArray(),
            'allowed_materials' => static::activeMaterialCodes($this->allowed_materials, $activeMaterials),
            'materials' => $materialsList,
            'base_price_cents' => (int) $this->base_price_cents,
            'base_price' => (float) ($this->base_price_cents / 100),
            'pix_discount_percent' => 10,
            'price' => (float) $priceInReais,
            // M-77: sem preço "de" nem nota/avaliações inventados (a loja ainda não tem avaliações reais)
            'printTimeHours' => (int) round((float) $hours),
            'printTimeDays' => max(1, (int) ceil((float) $hours / 12)).' a '.max(2, (int) ceil((float) $hours / 8)).' dias úteis',
            'badge' => 'Novo no Catálogo ✨',
            'badgeColor' => 'bg-mimoo-purple text-white',
            'image' => $imageUrl,
            'gallery' => $gallery,
            'weight_grams' => (int) ($this->weight_grams ?? 120),
            'is_active' => (bool) $this->is_active,
            'isBestSeller' => false,
            'specs' => [
                'technology' => 'FDM (Modelagem por Fusão e Deposição)',
                'layerHeight' => '0.12mm (Ultra Definição)',
                'material' => ! empty($materialsList) ? $materialsList[0]['full_name'] : 'PLA Premium (Biodegradável em Compostagem Industrial)',
                'infill' => '20% Padrão Gyroid Resistente',
                'weight' => "{$this->weight_grams}g",
                'origin' => 'Feito sob encomenda pela MIMOO LAB',
            ],
            'careInstructions' => [
                'Peça impressa em 3D: resistente e durável.',
                'Evite expor a temperaturas acima de 55°C (como água fervente ou painel do carro ao sol).',
                'Para limpeza, utilize pano levemente umedecido ou água corrente fria com sabão neutro.',
            ],
        ];
    }

    /**
     * Códigos de "Materiais Homologados" da peça que estão ativos (M-84). Lista vazia: a peça não
     * aparece na loja (`ProductController`) e o checkout recusa.
     *
     * @param  \Illuminate\Support\Collection<string, Material>  $activeMaterials
     * @return array<int, string>
     */
    public static function activeMaterialCodes(mixed $allowedMaterials, \Illuminate\Support\Collection $activeMaterials): array
    {
        return array_values(array_filter(
            Product::normalizeMaterialCodes($allowedMaterials),
            fn (string $code): bool => $activeMaterials->has($code)
        ));
    }
}
