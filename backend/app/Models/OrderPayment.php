<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resultado da criação do pagamento no gateway e da resposta dada ao cliente no checkout.
 */
class OrderPayment extends Model
{
    use HasVersion4Uuids;

    protected $fillable = [
        'order_id',
        'provider',
        'method',
        'external_id',
        'status',
        'status_detail',
        'qr_code',
        'qr_code_base64',
        'ticket_url',
        'failure_message',
        'http_status',
        'order_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
