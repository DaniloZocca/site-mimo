// Textos da Política de Privacidade e dos Termos de Uso (M-41).
// Marcações: **negrito** e {{campo}} (dado de src/config/loja.js). Cada afirmação sobre o sistema foi conferida
// no código (plano em planos/m-41-paginas-legais.md): não prometa aqui nada que o backend não faça.

// Endereços das páginas (a loja não tem rotas: abre pela query string, como o rastreio).
export const LEGAL_LINKS = {
  privacy: '?pagina=privacidade',
  terms: '?pagina=termos',
};

export const LEGAL_PAGES = {
  privacy: {
    badge: '🔒 Seus Dados, Nosso Cuidado',
    title: 'Política de Privacidade',
    intro:
      'Oi! 🐮 Aqui a gente explica, sem juridiquês, quais dados seus a MIMOO LAB usa, por que usa e como você pode pedir para ver, corrigir ou apagar.',
    sections: [
      {
        id: 'quem-cuida',
        title: '🐮 Quem cuida dos seus dados',
        paragraphs: [
          '{{razaoSocial}}, CNPJ {{cnpj}}, {{endereco}} ("MIMOO LAB").',
          'Fale com a gente sobre privacidade pelo e-mail **{{emailPrivacidade}}**. Encarregado de dados: {{encarregado}}.',
        ],
      },
      {
        id: 'pedido',
        title: '🛍️ Dados que usamos quando você faz um pedido',
        items: [
          '**Nome, e-mail, CPF e telefone:** para emitir o pagamento, falar com você sobre a encomenda e cumprir obrigações fiscais.',
          '**Endereço de entrega:** para calcular o frete e enviar a sua peça.',
          '**Peças, cores, tamanhos, valores e o andamento da produção** do pedido.',
          '**Dados do pagamento que o Mercado Pago nos devolve** (código do pagamento, situação, motivo de uma recusa). Os dados do cartão são digitados direto no formulário do Mercado Pago e não passam pelos nossos servidores.',
        ],
      },
      {
        id: 'conta',
        title: '💜 Dados da sua conta (só se você criar uma)',
        paragraphs: [
          'Nome, e-mail, senha (guardada de forma criptografada: nem a gente consegue ler) e a data em que você confirmou o e-mail. Com a conta logada, mostramos os pedidos feitos com o mesmo e-mail confirmado.',
          'Enquanto você está logado, guardamos a sessão com o endereço IP e o navegador usados, para manter você conectado com segurança. A sessão expira depois de um período sem uso ou quando você sai da conta.',
        ],
      },
      {
        id: 'tecnicos',
        title: '⚙️ Dados técnicos e cookies',
        paragraphs: [
          'Ao usar a loja, o seu endereço IP passa pelos nossos servidores e é usado por alguns minutos para limitar tentativas repetidas (proteção contra abuso). Nossos registros técnicos não guardam seu CPF, e-mail ou telefone.',
          'O carrinho e o frete escolhido ficam salvos só no seu navegador. Usamos apenas os cookies necessários para o login funcionar: não usamos cookies de propaganda nem ferramentas de análise de visitas.',
        ],
      },
      {
        id: 'compartilhamento',
        title: '🤝 Com quem compartilhamos (só o necessário)',
        items: [
          '**Mercado Pago:** nome, e-mail e CPF, para processar o PIX ou o cartão.',
          '**Melhor Envio:** só o CEP de entrega e as medidas do pacote, para cotar o frete.',
          '**ViaCEP:** o CEP que você digita, para preencher o endereço automaticamente.',
          '**Serviço de envio de e-mails ({{servicoEmail}}):** seu nome e e-mail, para os e-mails do pedido e da conta.',
          '**Hospedagem ({{hospedagem}}):** onde o site e o banco de dados ficam guardados.',
          '**Google Fonts:** as letrinhas da loja são carregadas do Google, que recebe o IP do seu navegador.',
          '**Transportadora escolhida:** nome, endereço e telefone, para a entrega.',
          '**Autoridades,** quando a lei exigir.',
        ],
        paragraphs: ['Não vendemos seus dados e não usamos para propaganda.'],
      },
      {
        id: 'bases-legais',
        title: '⚖️ Por que podemos usar esses dados',
        paragraphs: [
          'Pela Lei Geral de Proteção de Dados (LGPD, art. 7º): para cumprir o contrato da compra e da conta (inciso V), para cumprir obrigações legais e fiscais (inciso II) e, nos registros de segurança, pelo legítimo interesse de proteger a loja e você contra fraudes (inciso IX).',
        ],
      },
      {
        id: 'guarda',
        title: '🗂️ Por quanto tempo guardamos',
        items: [
          '**Pedidos:** pelo tempo que a lei fiscal e o Código de Defesa do Consumidor exigem.',
          '**Conta:** até você pedir a exclusão.',
          '**Sessão de login:** até expirar por falta de uso ou até você sair.',
        ],
      },
      {
        id: 'direitos',
        title: '✨ Seus direitos',
        paragraphs: [
          'Você pode pedir para confirmar se tratamos seus dados, ver uma cópia, corrigir dados errados, pedir a exclusão, saber com quem compartilhamos e reclamar na Autoridade Nacional de Proteção de Dados (ANPD, gov.br/anpd).',
        ],
      },
      {
        id: 'excluir-conta',
        title: '🗑️ Como pedir a exclusão da sua conta',
        paragraphs: [
          'Mande um e-mail para **{{emailPrivacidade}}** a partir do **e-mail da sua conta**, com o assunto "Excluir minha conta". Respondemos em até 15 dias.',
          'Apagamos o cadastro (nome, e-mail e senha) e as sessões de login. Os pedidos já feitos continuam guardados pelo prazo da lei fiscal e de defesa do consumidor, mesmo sem a conta.',
          'Para corrigir dados ou pedir uma cópia, o caminho é o mesmo.',
        ],
      },
      {
        id: 'seguranca',
        title: '🛡️ Segurança',
        paragraphs: [
          'Usamos senha criptografada, limite de tentativas, e o rastreio do pedido pede o CPF completo ou o link secreto do e-mail. Quem tiver o seu link de rastreio vê o primeiro nome, a cidade, as peças e o andamento, então compartilhe só com quem você quiser. 💜',
        ],
      },
      {
        id: 'mudancas',
        title: '📝 Mudanças nesta política',
        paragraphs: ['Se algo mudar, atualizamos esta página e a data lá em cima.'],
      },
    ],
  },
  terms: {
    badge: '📜 Combinados da Loja',
    title: 'Termos de Uso',
    intro:
      'Que bom ter você por aqui! ✨ Estes termos valem para quem usa a loja da MIMOO LAB ({{razaoSocial}}, CNPJ {{cnpj}}, {{endereco}}, contato **{{emailContato}}**).',
    sections: [
      {
        id: 'encomenda',
        title: '🖨️ Como funciona a encomenda',
        paragraphs: [
          'Cada peça é impressa em 3D sob encomenda, só depois que o pagamento é confirmado. O prazo de entrega mostrado no checkout soma o tempo de produção e o prazo da transportadora, e começa a contar depois da confirmação do pagamento.',
        ],
      },
      {
        id: 'pagamento',
        title: '💳 Preços e pagamento',
        paragraphs: [
          'Os preços são em reais. Aceitamos PIX (com 10% de desconto sobre as peças, sem desconto no frete) e cartão de crédito, processados pelo Mercado Pago.',
          'O valor cobrado é sempre o calculado pela loja no momento do pedido. Se o frete mudar entre a cotação e o pagamento, avisamos antes de criar o pedido. Pedido não pago não entra em produção.',
        ],
      },
      {
        id: 'pecas',
        title: '🎨 Peças impressas em 3D',
        paragraphs: [
          'Pequenas marquinhas de camada e leves variações de cor entre lotes de filamento são naturais da impressão 3D e não são defeito. As fotos são ilustrativas.',
        ],
      },
      {
        id: 'trocas',
        title: '🔄 Cancelamento, trocas e devoluções',
        items: [
          '**Arrependimento:** você pode desistir da compra em até 7 dias depois de receber a peça (art. 49 do Código de Defesa do Consumidor). Devolvemos todo o valor pago, frete incluído. Fale com a gente em {{emailContato}}.',
          '**Defeito de fabricação:** avise em até 90 dias depois de receber (art. 26 do Código de Defesa do Consumidor) e a gente conserta, troca ou devolve o valor.',
          '**Antes do envio:** pedido ainda não pago pode ser cancelado. Depois do pagamento, fale com a gente pelo e-mail.',
        ],
      },
      {
        id: 'conta',
        title: '💜 Sua conta',
        paragraphs: ['Você é responsável por manter a sua senha em segredo. Podemos suspender uma conta usada para fraude.'],
      },
      {
        id: 'propriedade',
        title: '🐮 Propriedade intelectual',
        paragraphs: [
          'Os modelos, fotos, textos e a Vaquinha Mimoo são da MIMOO LAB ou dos autores que licenciaram o uso. Não copie para uso comercial sem autorização.',
        ],
      },
      {
        id: 'privacidade',
        title: '🔒 Privacidade',
        paragraphs: ['O uso dos seus dados está explicado na nossa Política de Privacidade.'],
      },
      {
        id: 'foro',
        title: '⚖️ Lei e foro',
        paragraphs: ['Valem as leis brasileiras. Qualquer questão pode ser resolvida no foro do seu domicílio.'],
      },
    ],
  },
};
