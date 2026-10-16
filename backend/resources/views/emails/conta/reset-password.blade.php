@extends('emails.layouts.master')

@section('title', 'Crie uma nova senha - MIMOO LAB')

@section('preheader', 'Recebemos um pedido para trocar a senha da sua conta MIMOO LAB.')

@section('content')
<table border="0" cellpadding="0" cellspacing="0" width="100%">

  <!-- Saudação -->
  <tr>
    <td align="center" style="padding-bottom: 20px;">
      @include('emails.components.badge', ['type' => 'purple', 'text' => 'Conta MIMOO LAB'])

      <h1 style="margin: 14px 0 8px 0; font-size: 22px; font-weight: 800; color: #18181B; line-height: 1.3;">
        Bora criar uma senha nova? 🔑
      </h1>

      <p style="margin: 0; font-size: 13px; color: #52525B; line-height: 1.6; max-width: 480px;">
        Olá, {{ $firstName }}! Clique no botão, digite seu e-mail e escolha a nova senha.
      </p>
    </td>
  </tr>

  <!-- Botão de nova senha -->
  <tr>
    <td align="center" style="padding: 12px 0 16px 0;">
      @include('emails.components.button', [
          'url' => $resetUrl,
          'slot' => 'Criar nova senha 🐮'
      ])
    </td>
  </tr>

  <tr>
    <td align="center" style="padding: 0 0 8px 0; font-size: 12px; color: #71717A; line-height: 1.6;">
      O link vale por {{ $linkMinutes }} minutos e só pode ser usado uma vez. Se você não pediu a troca, ignore este e-mail: sua senha continua a mesma.
    </td>
  </tr>

</table>
@endsection
