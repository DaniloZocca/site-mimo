@extends('emails.layouts.master')

@section('title', 'Tentativa de cadastro - MIMOO LAB')

@section('preheader', 'Alguém tentou criar uma conta na MIMOO LAB com este e-mail. Sua conta continua protegida.')

@section('content')
<table border="0" cellpadding="0" cellspacing="0" width="100%">

  <!-- Saudação -->
  <tr>
    <td align="center" style="padding-bottom: 20px;">
      @include('emails.components.badge', ['type' => 'purple', 'text' => 'Conta MIMOO LAB'])

      <h1 style="margin: 14px 0 8px 0; font-size: 22px; font-weight: 800; color: #18181B; line-height: 1.3;">
        Oi, {{ $firstName }}! 🐮
      </h1>

      <p style="margin: 0; font-size: 13px; color: #52525B; line-height: 1.6; max-width: 480px;">
        Alguém tentou criar uma conta na MIMOO LAB com este e-mail, mas ele já tem uma conta: a sua! 💜
        Se foi você, é só entrar com sua senha ou usar "Esqueci minha senha" na loja.
      </p>
    </td>
  </tr>

  <!-- Botão para a loja -->
  <tr>
    <td align="center" style="padding: 12px 0 16px 0;">
      @include('emails.components.button', [
          'url' => $storeUrl,
          'slot' => 'Entrar na minha conta ✨'
      ])
    </td>
  </tr>

  <tr>
    <td align="center" style="padding: 0 0 8px 0; font-size: 12px; color: #71717A; line-height: 1.6;">
      Se não foi você, pode ignorar este e-mail: sua conta continua protegida e nada mudou nela.
    </td>
  </tr>

</table>
@endsection
