@extends('emails.layouts.master')

@section('title', "Sua peça #{$order->order_number} está sendo impressa! - MIMOO LAB")

@section('preheader', "As impressoras 3D começaram a trabalhar! Acompanhe a extrusão em camadas a 210°C.")

@section('content')
<table border="0" cellpadding="0" cellspacing="0" width="100%">
  
  <!-- Saudação e Status -->
  <tr>
    <td align="center" style="padding-bottom: 20px;">
      @include('emails.components.badge', ['type' => 'blue', 'text' => 'Extrusão 3D em Andamento'])
      
      <h1 style="margin: 14px 0 8px 0; font-size: 22px; font-weight: 800; color: #18181B; line-height: 1.3;">
        As máquinas estão trabalhando! 🖨️✨
      </h1>
      
      <p style="margin: 0; font-size: 13px; color: #52525B; line-height: 1.6; max-width: 480px;">
        Olá, {{ explode(' ', trim($order->customer->name ?? 'Cliente'))[0] }}! Nossa equipe técnica acabou de carregar o filamento ecológico e a impressora 3D já está fatiando a sua encomenda <strong style="color: #7C3AED;">#{{ $order->order_number }}</strong>.
      </p>
    </td>
  </tr>

  <!-- Card Técnico Fabril -->
  <tr>
    <td style="padding: 16px 0;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #EFF6FF; border: 2px solid #BFDBFE; border-radius: 16px;">
        <tr>
          <td style="padding: 16px 20px;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td width="36" valign="top" style="font-size: 24px; line-height: 1;">
                  🔥
                </td>
                <td valign="top" style="font-size: 12px; color: #1E40AF; line-height: 1.5;">
                  <strong style="display: block; font-size: 13px; color: #1E3A8A; margin-bottom: 2px;">
                    Parâmetros da Oficina 3D:
                  </strong>
                  Bico aquecido a <strong>210°C</strong> &bull; Mesa a <strong>60°C</strong> &bull; Resolução em camadas ultrafinas (<strong>0.16mm</strong>) para máxima definição.
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Botão de Rastreio da Linha do Tempo -->
  <tr>
    <td align="center" style="padding: 12px 0 24px 0;">
      @include('emails.components.button', [
          'url' => $trackingUrl,
          'slot' => 'Ver Linha do Tempo da Oficina 3D 🛠️'
      ])
    </td>
  </tr>

  <!-- Peças em Produção -->
  <tr>
    <td style="padding: 16px 0 8px 0; border-top: 2px solid #F4F4F5;">
      <h3 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; color: #71717A;">
        Peças Sendo Extrusadas Agora ({{ $order->orderItems->count() }})
      </h3>

      @foreach($order->orderItems as $item)
        @include('emails.components.item-row', ['item' => $item])
      @endforeach
    </td>
  </tr>

</table>
@endsection
