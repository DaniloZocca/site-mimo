@extends('emails.layouts.master')

@section('title', 'Confirme seu e-mail - MIMOO LAB')

@section('preheader', 'Falta só um clique para ver seus pedidos na sua conta MIMOO LAB.')

@section('content')
<table border="0" cellpadding="0" cellspacing="0" width="100%">

  <!-- Saudação -->
  <tr>
    <td align="center" style="padding-bottom: 20px;">
      @include('emails.components.badge', ['type' => 'purple', 'text' => 'Conta MIMOO LAB'])

      <h1 style="margin: 14px 0 8px 0; font-size: 22px; font-weight: 800; color: #18181B; line-height: 1.3;">
        Que bom ter você aqui! 🐮💜
      </h1>

      <p style="margin: 0; font-size: 13px; color: #52525B; line-height: 1.6; max-width: 480px;">
        Olá, {{ $firstName }}! Confirme seu e-mail para ver em "Meus pedidos" as encomendas feitas com este endereço.
      </p>
    </td>
  </tr>

  <!-- Botão de confirmação -->
  <tr>
    <td align="center" style="padding: 12px 0 16px 0;">
      @include('emails.components.button', [
          'url' => $verifyUrl,
          'slot' => 'Confirmar meu e-mail ✨'
      ])
    </td>
  </tr>

  <tr>
    <td align="center" style="padding: 0 0 8px 0; font-size: 12px; color: #71717A; line-height: 1.6;">
      O link vale por {{ $linkMinutes }} minutos. Se não foi você que criou a conta, é só ignorar este e-mail.
    </td>
  </tr>

</table>
@endsection
