<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductionLog;
use App\Models\Size;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * M-23: no Laravel 12 o `HasUuids` passou a gerar UUID v7. Os models seguem com o UUID
 * ordenado v4 de antes (`HasVersion4Uuids`, que usa `Str::orderedUuid()`).
 */
class UuidVersionTest extends TestCase
{
    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function models(): array
    {
        return [
            'Category' => [Category::class],
            'Color' => [Color::class],
            'Order' => [Order::class],
            'OrderItem' => [OrderItem::class],
            'OrderPayment' => [OrderPayment::class],
            'Product' => [Product::class],
            'ProductImage' => [ProductImage::class],
            'ProductionLog' => [ProductionLog::class],
            'Size' => [Size::class],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('models')]
    public function test_id_novo_continua_uuid_versao_4(string $modelClass): void
    {
        /** @var Model $model */
        $model = new $modelClass;
        $id = $model->newUniqueId();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }
}
