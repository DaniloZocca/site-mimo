<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Material de impressão cadastrado no painel (M-84). Cores e peças guardam o `code`, que não muda;
 * o pedido guarda o nome da época (`order_items.material_name_snapshot`), então desativar ou apagar um
 * material não muda pedido nenhum.
 *
 * @property string $code
 * @property string $short_name
 * @property string $full_name
 * @property string $description
 * @property bool $is_active
 * @property int $sort_order
 */
class Material extends Model
{
    use HasFactory, HasVersion4Uuids;

    /** Mesmo tamanho de `colors.material_type`. */
    public const CODE_MAX_LENGTH = 30;

    public const CODE_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const INACTIVE_MESSAGE = 'Esse material não está mais disponível. Tire o item da sacola e escolha outro.';

    protected $fillable = [
        'code',
        'short_name',
        'full_name',
        'description',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // O código não muda depois de criado: cores e peças guardam o código
        static::updating(function (Material $material): void {
            if ($material->isDirty('code')) {
                $material->code = (string) $material->getOriginal('code');
            }
        });

        // Material usado por cor ou peça não se apaga (desative); pedidos guardam só o nome
        static::deleting(function (Material $material): bool {
            return ! $material->isInUse();
        });
    }

    /**
     * Código gerado do nome curto: "PLA Silk" → "pla-silk" (só [a-z0-9-], até 30 caracteres).
     */
    public static function codeFromName(?string $shortName): string
    {
        $slug = Str::slug((string) $shortName);

        return trim(Str::limit($slug, self::CODE_MAX_LENGTH, ''), '-');
    }

    public function colors(): HasMany
    {
        return $this->hasMany(Color::class, 'material_type', 'code');
    }

    /**
     * Peças que têm este material em "Materiais Homologados".
     */
    public function productsCount(): int
    {
        return Product::query()->get(['id', 'allowed_materials'])
            ->filter(fn (Product $product): bool => in_array($this->code, Product::normalizeMaterialCodes($product->allowed_materials), true))
            ->count();
    }

    public function isInUse(): bool
    {
        return $this->colors()->exists() || $this->productsCount() > 0;
    }

    /**
     * @param  Builder<Material>  $query
     * @return Builder<Material>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('short_name');
    }

    /**
     * Materiais ativos indexados pelo código, buscados uma vez por requisição.
     *
     * @return Collection<string, Material>
     */
    public static function activeByCode(?Request $request = null): Collection
    {
        $request ??= request();
        $cached = $request->attributes->get('mimoo.active_materials');
        if ($cached instanceof Collection) {
            return $cached;
        }

        $materials = static::query()->where('is_active', true)->ordered()->get()->keyBy('code');
        $request->attributes->set('mimoo.active_materials', $materials);

        return $materials;
    }

    /**
     * Opções para os formulários do painel: os ativos e, se houver, os já marcados no registro
     * (desativado continua visível onde já estava, para não sumir sem aviso).
     *
     * @param  array<int, string>  $keep
     * @return array<string, string>
     */
    public static function panelOptions(array $keep = [], bool $long = false): array
    {
        return static::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereIn('code', $keep))
            ->ordered()
            ->get()
            ->mapWithKeys(fn (Material $material): array => [
                $material->code => ($long ? $material->full_name : $material->short_name).($material->is_active ? '' : ' (desativado)'),
            ])
            ->all();
    }
}
