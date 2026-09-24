---
paths:
  - "src/components/**"
  - "src/index.css"
  - "src/App.css"
---
# Componentes React
- Siga a skill `kawaii-chunky-ui`: só tokens `mimoo-*`, `shadow-chunky*`, fontes Fredoka/Nunito do `tailwind.config.js`.
- Formulários: React Hook Form + schema Zod em `src/schemas/`. Conteúdo vindo da API renderizado como HTML passa por `src/utils/sanitize.js` (DOMPurify).
- Valores monetários exibidos com `formatMoney` de `src/utils/pricing.js`; nunca calcule total final no componente.
- Antes de editar, confira qual versão do componente é importada (há duplicatas em `components/` e `components/<pasta>/`).
