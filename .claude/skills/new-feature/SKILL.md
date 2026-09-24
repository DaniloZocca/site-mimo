---
name: new-feature
description: Fluxo para feature grande na MIMOO — entrevista, SPEC.md, depois implementação em sessão nova.
disable-model-invocation: true
---
# Nova feature
Feature: $ARGUMENTS

1. Leia CLAUDE.md, os arquivos envolvidos e as skills relacionadas.
2. Me entreviste com AskUserQuestion sobre as partes difíceis: casos de borda, UX, dinheiro, status, LGPD, trade-offs. Não pergunte o óbvio.
3. Escreva `SPEC.md` com: arquivos e interfaces envolvidos; contrato de API (request/response com exemplos em centavos); fora de escopo; critérios de aceitação numerados; verificação ponta a ponta.
4. Não escreva código nesta sessão. Termine sugerindo: `/clear` e "Implemente SPEC.md; escreva os testes dos critérios primeiro, rode-os e mostre a saída".
