---
name: kawaii-chunky-ui
description: Design system Kawaii Chunky da MIMOO (bordas #18181B, shadow-chunky, tokens mimoo-*, Fredoka/Nunito, mascote Vaquinha Mimoo). Use ao criar ou alterar qualquer componente React, modal, botão, formulário, estado vazio/erro/carregando ou estilo Tailwind em src/components.
---
# Kawaii Chunky
- Só tokens do `tailwind.config.js`: cores `mimoo-*` (purple, pink, yellow, mint, sky, dark…), sombras `shadow-chunky`, `-sm`, `-lg`, `-xl`, `-purple`, `-pink`, raios `rounded-3xl/4xl`. Nada de hex solto.
- Títulos em `font-fredoka`, texto em `font-nunito`. Bordas sólidas `border-mimoo-dark` (border-2/border-4). No `:active` o botão "afunda" (translate + sombra menor).
- Referência de padrão: `src/components/ProductCard.jsx` e `src/components/CartDrawer.jsx` (versões importadas pelo app).
- Acessibilidade: foco visível, `aria-*`, alvo de toque ≥ 44px, contraste AA (atenção a texto sobre pink/purple claros). Modais com focus trap, Esc e `aria-modal="true"`.
- Texto de UI em pt-BR, tom fofo porém claro; mensagens de erro dizem como resolver.
- Animações (confetti incluso) respeitam `prefers-reduced-motion`.
- Estados obrigatórios: carregando, vazio, erro (com a Vaquinha), sucesso.
- Verificação: `npm run lint` e `npm run build`. Se houver Playwright MCP, screenshot em 375px e 1280px.
