export const FILAMENT_COLORS = [
  { id: 'lilac', name: 'Lilás MIMOO', hex: '#A855F7', border: '#7C3AED', textClass: 'text-purple-600', tag: 'Cor Oficial' },
  { id: 'pink', name: 'Rosa Algodão', hex: '#FF85B2', border: '#DB2777', textClass: 'text-pink-600', tag: 'Mais Pedido' },
  { id: 'mint', name: 'Verde Menta', hex: '#6EE7B7', border: '#059669', textClass: 'text-emerald-600', tag: 'Pastel' },
  { id: 'yellow', name: 'Amarelo Baunilha', hex: '#FDE047', border: '#D97706', textClass: 'text-amber-600', tag: 'Pastel' },
  { id: 'sky', name: 'Azul Bebê', hex: '#7DD3FC', border: '#0284C7', textClass: 'text-sky-600', tag: 'Pastel' },
  { id: 'silk_gold', name: 'Seda Dourada (Silk)', hex: '#F59E0B', border: '#B45309', textClass: 'text-yellow-700', tag: 'Brilho Acetinado' },
  { id: 'white', name: 'Branco Neve', hex: '#F8FAFC', border: '#94A3B8', textClass: 'text-slate-700', tag: 'Clássico' },
  { id: 'black', name: 'Preto Ônix Fosco', hex: '#1E293B', border: '#0F172A', textClass: 'text-slate-900', tag: 'Fosco' },
  { id: 'rainbow', name: 'Gradiente Sunset', hex: 'linear-gradient(135deg, #FF85B2, #A855F7, #38BDF8)', border: '#7C3AED', textClass: 'text-purple-600', tag: 'Multicolor' },
];

export const CATEGORIES = [
  { id: 'all', name: 'Todos os Mimos', icon: 'Sparkles', count: 18 },
  { id: 'decor', name: 'Decoração', icon: 'Heart', count: 7 },
  { id: 'geek', name: 'Suportes & Geek', icon: 'Gamepad2', count: 5 },
  { id: 'articulated', name: 'Articulados & Toys', icon: 'Activity', count: 4 },
  { id: 'desk', name: 'Acessórios de Mesa', icon: 'Monitor', count: 6 },
];

export const PRODUCTS = [];

export const MOCK_ORDERS = [
  {
    id: 'MIMOO-8492',
    customerName: 'Fernanda Oliveira',
    customerEmail: 'fernanda@exemplo.com',
    createdAt: '26/08/2026 às 14:20',
    total: 114.70,
    items: [
      {
        productName: 'Vasinho Mimoo Vaquinha para Suculentas',
        color: 'Lilás MIMOO',
        size: 'Médio Padrão (12cm)',
        quantity: 1,
        price: 67.90,
        image: '/assets/products/cow_planter.jpg'
      },
      {
        productName: 'Suporte de Celular Gatinho Dorminhoco',
        color: 'Rosa Algodão',
        size: 'Universal',
        quantity: 1,
        price: 39.90,
        image: '/assets/products/cat_phone_stand.jpg'
      }
    ],
    statusStep: 2, // 1: Recebido, 2: Na Impressora 3D, 3: Embalado com carinho, 4: Enviado
    statusText: 'Na Impressora 3D (Sendo fabricado)',
    estimatedCompletion: 'Hoje às 21:30',
    printerName: 'Impressora MIMOO Prusa #03 (Bico 0.4mm)',
    printProgress: 68,
    currentLayer: 748,
    totalLayers: 1100,
    remainingTime: '03h 42min restantes de extrusão',
    extruderTemp: '210°C',
    bedTemp: '60°C',
    trackingCode: 'BR984729104BR',
    carrier: 'Jadlog Express',
    shippingAddress: 'Rua das Flores, 450 - Apto 32 - São Paulo / SP'
  },
  {
    id: 'MIMOO-9104',
    customerName: 'Lucas Medeiros',
    customerEmail: 'lucas@exemplo.com',
    createdAt: '24/08/2026 às 09:15',
    total: 89.90,
    items: [
      {
        productName: 'Dragão de Cristal Articulado Flexível',
        color: 'Gradiente Sunset',
        size: 'Médio (35cm)',
        quantity: 1,
        price: 89.90,
        image: '/assets/products/dragon_articulated.jpg'
      }
    ],
    statusStep: 4,
    statusText: 'Enviado para Você!',
    estimatedCompletion: 'Chega em 28/08/2026',
    printerName: 'Impressora MIMOO Voron #01',
    printProgress: 100,
    currentLayer: 1450,
    totalLayers: 1450,
    remainingTime: 'Concluído com Sucesso ✨',
    extruderTemp: 'Desligado',
    bedTemp: 'Desligado',
    trackingCode: 'BR204918239BR',
    carrier: 'Correios Sedex',
    shippingAddress: 'Av. Paulista, 1000 - Bela Vista - São Paulo / SP'
  }
];

export const FAQS = [
  {
    question: 'Como funciona a compra de produtos feitos sob encomenda?',
    answer: 'Ao contrário de lojas comuns que vendem estoque parado, na MIMOO LAB cada peça é fatiada e impressa em nossas impressoras 3D especialmente para você na cor e tamanho que escolher! Isso garante personalização total, acabamento impecável e zero desperdício ambiental.'
  },
  {
    question: 'Qual é o tempo total até a chegada do meu pedido?',
    answer: 'O prazo total é composto por: [Tempo de Impressão e Acabamento da Peça: de 1 a 3 dias úteis] + [Prazo de Envio dos Correios/Transportadora: 1 a 5 dias úteis de acordo com seu CEP]. No carrinho e na página do produto nós calculamos tudo de forma 100% transparente!'
  },
  {
    question: 'O material PLA é resistente e seguro?',
    answer: 'Sim! Utilizamos filamento PLA (Ácido Polilático) de grau premium, um termoplástico biodegradável de origem vegetal (amido de milho e cana-de-açúcar). É totalmente atóxico, seguro para ambientes familiares, não tem cheiro e possui alta resistência mecânica para uso decorativo e funcional diário.'
  },
  {
    question: 'Posso acompanhar a fabricação da minha peça em tempo real?',
    answer: 'Com certeza! Assim que seu pedido é confirmado, você recebe um código de rastreio interno (ex: #MIMOO-8492) para acompanhar em nossa página de rastreamento cada etapa: aquecimento da impressora, progresso de camadas em tempo real e preparação do pacote com mimos.'
  },
  {
    question: 'Posso escolher cores diferentes das mostradas nas fotos?',
    answer: 'Sim! Cada modelo possui um seletor com mais de 8 opções de filamentos exclusivos (desde tons pastel como Lilás e Menta até filamentos com brilho Seda Dourada e Gradiente Multicolor). Você escolhe a combinação perfeita!'
  }
];

export const PRINT_FARM_TICKERS = [
  '🐮 Impressora #02 acabou de iniciar: Vasinho Mimoo em Lilás Pastel para Curitiba/PR',
  '✨ Impressora #05 imprimindo: Dragão de Cristal Articulado (Camada 820/1200) para Belo Horizonte/MG',
  '💖 Pedido #8492 de Fernanda O. está a 68% de conclusão na máquina!',
  '🌱 100% de energia limpa e filamento PLA biodegradável em todas as impressões hoje',
  '🚀 Envio expresso realizado para 14 clientes felizes esta tarde!'
];
