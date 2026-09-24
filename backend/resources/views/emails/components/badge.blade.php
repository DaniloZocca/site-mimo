@props([
    'type' => 'purple',
    'text' => ''
])

@php
    $styles = match($type) {
        'emerald', 'green' => [
            'bg' => '#ECFDF5',
            'border' => '#A7F3D0',
            'text' => '#065F46'
        ],
        'amber', 'yellow' => [
            'bg' => '#FEF3C7',
            'border' => '#FDE68A',
            'text' => '#92400E'
        ],
        'blue' => [
            'bg' => '#EFF6FF',
            'border' => '#BFDBFE',
            'text' => '#1E40AF'
        ],
        'orange' => [
            'bg' => '#FFF7ED',
            'border' => '#FFEDD5',
            'text' => '#9A3412'
        ],
        'pink' => [
            'bg' => '#FDF2F8',
            'border' => '#FBCFE8',
            'text' => '#9D174D'
        ],
        default => [
            'bg' => '#FAF5FF',
            'border' => '#DDD6FE',
            'text' => '#5B21B6'
        ]
    };
@endphp

<table border="0" cellpadding="0" cellspacing="0" style="display: inline-block; vertical-align: middle;">
  <tr>
    <td style="background-color: {{ $styles['bg'] }}; border: 1px solid {{ $styles['border'] }}; border-radius: 20px; padding: 4px 10px; font-size: 10px; font-weight: 700; color: {{ $styles['text'] }}; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1;">
      {{ $text ?: $slot }}
    </td>
  </tr>
</table>
