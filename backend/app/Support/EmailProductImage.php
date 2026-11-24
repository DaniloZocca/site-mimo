<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;

/**
 * Foto do produto nos e-mails do pedido (M-68): o e-mail é lido fora da loja, então a imagem precisa
 * de URL absoluta e pública (APP_URL/storage/..., o mesmo endereço que o catálogo usa).
 *
 * É só ilustração: o nome, o tamanho, a cor e o preço continuam vindo do snapshot do OrderItem.
 * Item sem produto (apagado), produto sem foto ou endereço que não seja http(s) ficam fora do mapa,
 * e o e-mail mostra o ícone de sempre.
 */
final class EmailProductImage
{
    /**
     * @return array<string, string> id do OrderItem => URL absoluta da foto principal do produto
     */
    public static function urlsFor(Order $order): array
    {
        // Carregado aqui, antes de renderizar: o template não faz consulta ao banco (M-54).
        $order->loadMissing('items.product.primaryImage');

        $urls = [];

        foreach ($order->items as $item) {
            $url = self::absoluteUrl($item->product?->primaryImage?->image_path);

            if ($url !== null) {
                $urls[(string) $item->getKey()] = $url;
            }
        }

        return $urls;
    }

    private static function absoluteUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        $url = preg_match('#^https?://#i', $path) === 1
            ? $path
            : rtrim((string) config('app.url'), '/').'/storage/'.ltrim($path, '/');

        return preg_match('#^https?://[^/\s]+#i', $url) === 1 ? $url : null;
    }
}
