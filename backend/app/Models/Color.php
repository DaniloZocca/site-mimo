<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MaterialCode;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Color extends Model
{
    use HasFactory, HasVersion4Uuids;

    protected $fillable = [
        'name',
        'hex_code',
        'material_type',
        'in_stock',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'material_type' => MaterialCode::class,
            'in_stock' => 'boolean',
        ];
    }

    /**
     * Material da bobina, pelo código (M-84).
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_type', 'code');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }
}
