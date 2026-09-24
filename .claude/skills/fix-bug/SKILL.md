---
name: fix-bug
description: Correção de bug com reprodução por teste antes da correção.
disable-model-invocation: true
---
# Corrigir bug
Bug: $ARGUMENTS

1. Leia os arquivos prováveis e explique a causa raiz (não o sintoma). Se não conseguir reproduzir, pare e diga o que falta.
2. Escreva um teste que reproduz o bug e FALHA; rode e mostre a falha.
3. Corrija com o menor diff possível; não suprima erros nem altere testes existentes.
4. Rode o teste novo + testes do módulo; mostre a saída.
5. Reporte `Status:` e liste outros problemas vistos, sem corrigi-los.
