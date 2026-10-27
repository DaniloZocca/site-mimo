# Runbook — excluir a conta de um cliente (pedido de exclusão, LGPD)

A Política de Privacidade (M-41) diz que o cliente pede a exclusão da conta por e-mail, **a partir do e-mail da
própria conta**, e que a resposta sai em até 15 dias. A loja não tem botão de excluir conta nem lista de contas no
painel, então a exclusão é feita à mão com `docs/consultas/m-41-excluir-conta-cliente.sql`. O SQL é coberto por
`tests/Feature/LegalPages/ExcluirContaClienteTest.php`.

## O que é apagado e o que fica

| Apagado | Fica |
|---|---|
| Cadastro (`customers`: nome, e-mail, senha com hash) | Pedidos (`orders`, `order_items`, `order_payments`, `production_logs`) com o mesmo e-mail |
| Token de "nova senha" (`customer_password_reset_tokens`) | |
| Sessões de login (`sessions`), o que desloga o cliente em todo aparelho | |

Os pedidos ficam pela obrigação fiscal e de defesa do consumidor, como a política explica. Se o cliente criar outra
conta com o mesmo e-mail depois, os pedidos voltam a aparecer em "Meus pedidos" quando ele confirmar o e-mail.

## Passo a passo

1. **Confira quem pediu.** O e-mail com o pedido precisa ter vindo do mesmo endereço da conta. Se veio de outro
   endereço, responda pedindo que o cliente escreva do e-mail da conta e não exclua nada.
2. **Anote só o necessário.** No chamado ou na planilha, anote a data do pedido e a data da exclusão. Não copie o
   e-mail do cliente para o terminal do servidor, para o histórico do shell nem para mensagens de chat.
3. **Rode o SQL** no banco de produção (por exemplo, `psql` ou o cliente do banco da hospedagem): abra
   `docs/consultas/m-41-excluir-conta-cliente.sql`, troque todos os `EMAIL_DA_CONTA` pelo e-mail da conta e rode o
   arquivo inteiro. A última consulta precisa mostrar `contas_restantes = 0`.
   - Se mostrar `1`, alguma linha não foi trocada: rode `ROLLBACK;` no lugar do `COMMIT;` e revise.
   - Se o `DELETE FROM customers` disser `DELETE 0`, não havia conta com esse e-mail: responda ao cliente que não
     existe conta (os pedidos sem conta não são apagados por este roteiro).
4. **Responda ao cliente** em até 15 dias contados do pedido: a conta foi excluída e os pedidos já feitos ficam
   guardados pelo prazo legal.
