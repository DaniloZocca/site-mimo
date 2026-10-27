<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="pt-BR">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="x-apple-disable-message-reformatting" />
  <title>@yield('title', 'MIMOO LAB - Atualização da sua Encomenda 3D')</title>
  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <![endif]-->
  <style type="text/css">
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
    table { border-collapse: collapse !important; }
    body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #FAF5FF; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #18181B; }
    
    @media screen and (max-width: 600px) {
      .email-container { width: 100% !important; max-width: 100% !important; }
      .fluid-padding { padding-left: 20px !important; padding-right: 20px !important; }
      .stack-column { display: block !important; width: 100% !important; max-width: 100% !important; }
      .hide-mobile { display: none !important; }
    }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #FAF5FF; -webkit-font-smoothing: antialiased;">

  <!-- Pré-visualização do Inbox (Preheader Invisível) -->
  <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden; mso-hide: all;">
    @yield('preheader', 'Acompanhe em tempo real a extrusão da sua peça personalizada na fazenda 3D da MIMOO LAB.')
    &#847; &zwnj; &nbsp; &#8199; &shy; &#847; &zwnj; &nbsp; &#8199; &shy;
  </div>

  <!-- Tabela de Fundo Global -->
  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FAF5FF; table-layout: fixed;">
    <tr>
      <td align="center" style="padding: 24px 12px 36px 12px;">

        <!-- Container Central do E-mail (Max 600px) -->
        <!--[if (gte mso 9)|(IE)]>
        <table align="center" border="0" cellspacing="0" cellpadding="0" width="600">
        <tr>
        <td align="center" valign="top" width="600">
        <![endif]-->
        <table border="0" cellpadding="0" cellspacing="0" width="100%" class="email-container" style="max-width: 600px; background-color: #FFFFFF; border: 2px solid #18181B; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 25px rgba(124, 58, 237, 0.08);">
          
          <!-- Faixa Superior de Destaque em Degradê -->
          <tr>
            <td height="8" style="height: 8px; background: linear-gradient(90deg, #7C3AED 0%, #EC4899 50%, #FBBF24 100%); background-color: #7C3AED; font-size: 0; line-height: 0;">
              &nbsp;
            </td>
          </tr>

          <!-- Header Institucional -->
          <tr>
            <td align="center" style="padding: 32px 24px 20px 24px; background-color: #FFFFFF; border-bottom: 2px solid #F4F4F5;">
              <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td align="center">
                    <!-- Badge / Mascote MIMOO -->
                    <table border="0" cellpadding="0" cellspacing="0" align="center">
                      <tr>
                        <td align="center" style="padding: 0 0 12px 0;">
                          <div style="display: inline-block; background-color: #7C3AED; color: #FFFFFF; border: 2px solid #18181B; border-radius: 16px; padding: 8px 14px; font-weight: 800; font-size: 16px; box-shadow: 3px 3px 0px #18181B;">
                            🐮 MIMOO LAB
                          </div>
                        </td>
                      </tr>
                    </table>
                    
                    <p style="margin: 4px 0 0 0; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #71717A;">
                      Fazenda de Impressão 3D Sob Demanda
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Slot Dinâmico do Conteúdo da Mensagem -->
          <tr>
            <td style="padding: 32px 28px;" class="fluid-padding">
              @yield('content')
            </td>
          </tr>

          <!-- Seção de Suporte Rápido -->
          <tr>
            <td style="padding: 0 28px 24px 28px;" class="fluid-padding">
              <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #FAF5FF; border: 1px dashed #DDD6FE; border-radius: 16px;">
                <tr>
                  <td style="padding: 16px 20px; text-align: center;">
                    <p style="margin: 0 0 4px 0; font-size: 12px; font-weight: 700; color: #5B21B6;">
                      Precisa de ajuda ou deseja alterar algum detalhe?
                    </p>
                    <p style="margin: 0; font-size: 11px; color: #6D28D9; line-height: 1.5;">
                      Nossos artesãos e engenheiros 3D estão à disposição no WhatsApp: 
                      <a href="https://wa.me/5511999999999" target="_blank" style="color: #7C3AED; font-weight: 700; text-decoration: underline;">
                        (11) 99999-9999
                      </a>
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Rodapé Institucional & Conformidade LGPD -->
          <tr>
            <td style="padding: 24px 28px 32px 28px; background-color: #F8FAFC; border-top: 2px solid #F1F5F9; text-align: center;" class="fluid-padding">
              <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td align="center" style="font-size: 11px; line-height: 1.6; color: #64748B;">
                    
                    <p style="margin: 0 0 8px 0; font-weight: 700; color: #334155; font-size: 12px;">
                      MIMOO LAB • Manufatura Aditiva & Objetos de Design
                    </p>
                    
                    @php
                      $dadosDaLoja = array_filter([
                          config('loja.razao_social'),
                          config('loja.cnpj') ? 'CNPJ: '.config('loja.cnpj') : '',
                          config('loja.cidade_uf') ? config('loja.cidade_uf').' — Brasil' : '',
                      ]);
                    @endphp
                    @if ($dadosDaLoja !== [])
                    <p style="margin: 0 0 12px 0; font-size: 10px; color: #94A3B8;">
                      {{ implode(' • ', $dadosDaLoja) }}
                    </p>
                    @endif

                    <p style="margin: 0 0 14px 0; font-size: 10px; color: #94A3B8; max-width: 460px; line-height: 1.5;">
                      🛡️ <strong>Privacidade & LGPD:</strong> Você está recebendo este e-mail porque realizou uma encomenda em nossa loja. Esta é uma comunicação transacional essencial para a execução e entrega do seu pedido. Seus dados cadastrais permanecem confidenciais e protegidos.
                    </p>

                    <!-- Links Institucionais -->
                    <p style="margin: 0; font-size: 11px; font-weight: 600;">
                      <a href="{{ config('app.frontend_url') }}/?pagina=termos" target="_blank" style="color: #7C3AED; text-decoration: none; margin: 0 8px;">
                        Termos de Uso
                      </a>
                      <span style="color: #CBD5E1;">•</span>
                      <a href="{{ config('app.frontend_url') }}/?pagina=privacidade" target="_blank" style="color: #7C3AED; text-decoration: none; margin: 0 8px;">
                        Política de Privacidade
                      </a>
                      <span style="color: #CBD5E1;">•</span>
                      <a href="{{ config('app.frontend_url') }}" target="_blank" style="color: #7C3AED; text-decoration: none; margin: 0 8px;">
                        mimoolab.com.br
                      </a>
                    </p>

                  </td>
                </tr>
              </table>
            </td>
          </tr>

        </table>
        <!--[if (gte mso 9)|(IE)]>
        </td>
        </tr>
        </table>
        <![endif]-->

      </td>
    </tr>
  </table>

</body>
</html>
