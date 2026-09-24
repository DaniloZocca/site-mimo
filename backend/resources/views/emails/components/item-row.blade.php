@props([
    'item' => null,
    'title' => '',
    'size' => '',
    'color' => '',
    'quantity' => 1,
    'price' => 'R$ 0,00',
    'imageUrl' => null
])

@php
    $itemTitle = $item ? ($item->product_title_snapshot ?? $item->title ?? 'Peça 3D') : $title;
    $itemSize = $item ? ($item->size_name_snapshot ?? $item->size ?? 'Padrão') : $size;
    $itemColor = $item ? ($item->color_name_snapshot ?? $item->color ?? 'Original') : $color;
    $itemQty = $item ? ($item->quantity ?? 1) : $quantity;
    
    if ($item && isset($item->total_price_cents)) {
        $itemPriceFormatted = 'R$ ' . number_format($item->total_price_cents / 100, 2, ',', '.');
    } elseif ($item && isset($item->unit_price_cents)) {
        $itemPriceFormatted = 'R$ ' . number_format(($item->unit_price_cents * $itemQty) / 100, 2, ',', '.');
    } else {
        $itemPriceFormatted = $price;
    }
@endphp

<table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-bottom: 1px solid #F1F5F9; padding: 12px 0;">
  <tr>
    <!-- Miniatura ou Ícone 3D -->
    <td width="48" valign="middle" align="center" style="padding-right: 14px;">
      <table border="0" cellpadding="0" cellspacing="0" width="48" height="48" style="background-color: #FAF5FF; border: 1px solid #DDD6FE; border-radius: 12px; text-align: center;">
        <tr>
          <td align="center" valign="middle" style="font-size: 20px;">
            🧱
          </td>
        </tr>
      </table>
    </td>

    <!-- Detalhes do Produto (Título, Escala, Cor) -->
    <td valign="middle" style="font-size: 13px; color: #18181B; line-height: 1.4;">
      <strong style="font-size: 13px; color: #18181B; display: block; margin-bottom: 2px;">
        {{ $itemTitle }}
      </strong>
      
      <span style="font-size: 11px; color: #64748B;">
        Tamanho: <strong>{{ $itemSize }}</strong> &bull; Cor do Filamento: <strong style="color: #7C3AED;">{{ $itemColor }}</strong>
      </span>
    </td>

    <!-- Quantidade e Preço -->
    <td width="90" align="right" valign="middle" style="font-size: 12px; color: #18181B; font-weight: 700; white-space: nowrap;">
      <span style="display: block; font-size: 11px; color: #94A3B8; font-weight: 600;">
        Qtd: x{{ $itemQty }}
      </span>
      <span style="color: #5B21B6; font-size: 13px;">
        {{ $itemPriceFormatted }}
      </span>
    </td>
  </tr>
</table>
