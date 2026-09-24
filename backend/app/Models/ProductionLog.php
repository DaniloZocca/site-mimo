<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionLog extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'previous_status',
        'new_status',
        'notes',
        'created_at',
        'is_internal',
        'code',
        'reference',
    ];

    /** Pagamento aprovado que liberou a produção (reference = id do pagamento). */
    public const CODE_PAYMENT_APPROVED = 'payment_approved';

    /** Pagamento aprovado com divergência: produção não liberada, revisão manual. */
    public const CODE_PAYMENT_REVIEW = 'payment_review';

    /** Pagamento aprovado para pedido já cancelado: verificar estorno. */
    public const CODE_PAYMENT_AFTER_CANCELLATION = 'payment_after_cancellation';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'is_internal' => 'boolean',
        ];
    }

    /**
     * Registros que podem aparecer na timeline pública de rastreio.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /**
     * Registros internos de pagamento que exigem revisão humana.
     */
    public function scopePaymentReview(Builder $query): Builder
    {
        return $query->where('is_internal', true)
            ->whereIn('code', [self::CODE_PAYMENT_REVIEW, self::CODE_PAYMENT_AFTER_CANCELLATION]);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
