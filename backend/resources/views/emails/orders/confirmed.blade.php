@extends('emails.layouts.master')

@section('title', "Encomenda #{$order->order_number} Confirmada! - MIMOO LAB")

@section('preheader', "Oba! Seu pagamento foi aprovado e sua peça #{$order->order_number} já entrou na fila da fazenda 3D.")

@section('content')
<table border="0" cellpadding="0" cellspacing="0" width="100%">
  
  <!-- Saudação e Status -->
  <tr>
    <td align="center" style="padding-bottom: 20px;">
      @include('emails.components.badge', ['type' => 'emerald', 'text' => 'Pagamento Confirmado'])
      
      <h1 style="margin: 14px 0 8px 0; font-size: 22px; font-weight: 800; color: #18181B; line-height: 1.3;">
        Oba, {{ explode(' ', trim($order->customer->name ?? 'Cliente'))[0] }}! 🎉<br/>
        Sua encomenda foi confirmada!
      </h1>
      
      <p style="margin: 0; font-size: 13px; color: #52525B; line-height: 1.6; max-width: 480px;">
        Recebemos a confirmação do seu pagamento para o pedido <strong style="color: #7C3AED;">#{{ $order->order_number }}</strong>. Nossos engenheiros já iniciaram a separação do filamento e a calibração digital dos modelos 3D!
      </p>
    </td>
  </tr>

  <!-- Callout de Manufatura Sob Demanda -->
  <tr>
    <td style="padding: 16px 0;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FEF3C7; border: 2px solid #FCD34D; border-radius: 16px;">
        <tr>
          <td style="padding: 14px 18px;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td width="36" valign="top" style="font-size: 22px; line-height: 1;">
                  ⏱️
                </td>
                <td valign="top" style="font-size: 12px; color: #92400E; line-height: 1.5;">
                  <strong style="display: block; font-size: 13px; color: #78350F; margin-bottom: 2px;">
                    Estimativa de Extrusão na Fazenda 3D:
                  </strong>
                  Suas peças levarão aproximadamente <strong>~{{ $order->estimated_total_print_time_hours ?? 4 }} horas</strong> de extrusão contínua em alta precisão.
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Botão Principal de Rastreio -->
  <tr>
    <td align="center" style="padding: 12px 0 24px 0;">
      @include('emails.components.button', [
          'url' => $trackingUrl,
          'slot' => 'Acompanhar Produção 3D em Tempo Real ✨'
      ])
      <span style="display: block; font-size: 10px; color: #A1A1AA; margin-top: 4px;">
        Link exclusivo e protegido por chave única criptográfica.
      </span>
    </td>
  </tr>

  <!-- Lista de Peças 3D Encomendadas -->
  <tr>
    <td style="padding: 16px 0 8px 0; border-top: 2px solid #F4F4F5;">
      <h3 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; color: #71717A;">
        Itens da sua Encomenda ({{ $order->orderItems->count() }})
      </h3>

      @foreach($order->orderItems as $item)
        @include('emails.components.item-row', ['item' => $item])
      @endforeach
    </td>
  </tr>

  <!-- Resumo Financeiro -->
  <tr>
    <td style="padding: 16px 0;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FAFAFA; border: 1px solid #E4E4E7; border-radius: 16px; padding: 14px 18px;">
        <tr>
          <td style="font-size: 12px; color: #71717A; padding: 4px 0;">Subtotal dos Produtos:</td>
          <td align="right" style="font-size: 12px; color: #18181B; font-weight: 600; padding: 4px 0;">{{ $subtotalFormatted }}</td>
        </tr>
        <tr>
          <td style="font-size: 12px; color: #71717A; padding: 4px 0;">Frete de Envio:</td>
          <td align="right" style="font-size: 12px; color: #10B981; font-weight: 700; padding: 4px 0;">{{ $shippingFormatted }}</td>
        </tr>
        <tr>
          <td style="font-size: 14px; font-weight: 800; color: #18181B; padding-top: 8px; border-top: 1px solid #E4E4E7;">Total Pago:</td>
          <td align="right" style="font-size: 16px; font-weight: 800; color: #7C3AED; padding-top: 8px; border-top: 1px solid #E4E4E7;">{{ $totalAmountFormatted }}</td>
        </tr>
      </table>
    </td>
  </tr>

</table>
@endsection
