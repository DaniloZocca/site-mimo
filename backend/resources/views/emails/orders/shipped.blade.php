@extends('emails.layouts.master')

@section('title', "Sua encomenda #{$order->order_number} foi enviada! - MIMOO LAB")

@section('preheader', "Pacote despachado! Seu código de rastreamento dos Correios é {$order->carrier_tracking_code}.")

@section('content')
<table border="0" cellpadding="0" cellspacing="0" width="100%">
  
  <!-- Saudação e Status -->
  <tr>
    <td align="center" style="padding-bottom: 20px;">
      @include('emails.components.badge', ['type' => 'emerald', 'text' => 'Pacote Despachado'])
      
      <h1 style="margin: 14px 0 8px 0; font-size: 22px; font-weight: 800; color: #18181B; line-height: 1.3;">
        Sua encomenda está a caminho! 📦🚀
      </h1>
      
      <p style="margin: 0; font-size: 13px; color: #52525B; line-height: 1.6; max-width: 480px;">
        Olá, {{ explode(' ', trim($order->customer->name ?? 'Cliente'))[0] }}! A impressão, acabamento e embalagem da encomenda <strong style="color: #7C3AED;">#{{ $order->order_number }}</strong> foram concluídos com sucesso. Seu pacote já foi entregue aos Correios/transportadora.
      </p>
    </td>
  </tr>

  <!-- Card do Código de Rastreio dos Correios -->
  @if($order->carrier_tracking_code)
  <tr>
    <td style="padding: 14px 0;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #ECFDF5; border: 2px solid #6EE7B7; border-radius: 16px; text-align: center;">
        <tr>
          <td style="padding: 18px 20px;">
            <p style="margin: 0 0 6px 0; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #065F46;">
              Código de Rastreamento Oficial
            </p>
            <div style="font-family: monospace, Courier, sans-serif; font-size: 20px; font-weight: 800; color: #064E3B; letter-spacing: 2px;">
              {{ $order->carrier_tracking_code }}
            </div>
            <p style="margin: 6px 0 0 0; font-size: 10px; color: #047857;">
              * Pode levar até 24h para as movimentações aparecerem no sistema dos Correios.
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  @endif

  <!-- Botões de Ação -->
  <tr>
    <td align="center" style="padding: 16px 0 24px 0;">
      <table border="0" cellpadding="0" cellspacing="0">
        <tr>
          @if($correiosUrl)
          <td align="center" style="padding: 0 6px;">
            @include('emails.components.button', [
                'url' => $correiosUrl,
                'color' => '#10B981',
                'slot' => 'Rastrear nos Correios 🚚'
            ])
          </td>
          @endif
          <td align="center" style="padding: 0 6px;">
            @include('emails.components.button', [
                'url' => $trackingUrl,
                'color' => '#7C3AED',
                'slot' => 'Linha do Tempo MIMOO 🐮'
            ])
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Dicas de Cuidado com a Peça 3D -->
  <tr>
    <td style="padding: 16px 0 8px 0; border-top: 2px solid #F4F4F5;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FAF5FF; border: 1px solid #DDD6FE; border-radius: 16px; padding: 14px 18px;">
        <tr>
          <td>
            <strong style="display: block; font-size: 12px; color: #5B21B6; margin-bottom: 6px;">
              ✨ Dicas de Cuidado com sua Peça 3D:
            </strong>
            <ul style="margin: 0; padding-left: 18px; font-size: 11px; color: #6D28D9; line-height: 1.6;">
              <li>Fabricada em <strong>PLA biodegradável</strong> de alta qualidade.</li>
              <li>Evite exposição direta e prolongada ao sol intenso ou temperaturas acima de 55°C.</li>
              <li>Para limpeza, utilize apenas um pano macio levemente umedecido em água.</li>
            </ul>
          </td>
        </tr>
      </table>
    </td>
  </tr>

</table>
@endsection
