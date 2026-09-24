# Runbook — liberar manualmente um pedido em "Pagamento em revisão"

> **Provisório.** Vale só até existir a ação "Resolver revisão" no painel (PR de alertas).
> Depois disso, este procedimento deve ser apagado. O snippet abaixo **não tem teste automatizado**.

## Quando usar

Um pedido `pending_payment` aparece com o badge **Pagamento em revisão** no Filament
(filtro "Pagamento em revisão"). Isso acontece quando o `PaymentApprovalValidator` recebeu um pagamento
`approved`, mas com alguma divergência (valor, moeda, `external_reference`, id do pagamento ou `live_mode`).
Nesse caso a produção não é liberada, e desde o bug #14 **nenhuma ação do painel tira um pedido de
`pending_payment`**. A liberação é feita por um dev, com este procedimento, depois da conferência humana.

Não use este runbook para pedidos `cancelled` com registro `payment_after_cancellation`: esses são caso de
**estorno**, não de liberação.

## Pré-condições (todas obrigatórias, conferidas no painel do Mercado Pago)

O operador abriu o pagamento `<PAYMENT_ID>` no painel do Mercado Pago e confirmou que:

1. o status é **`approved`** (não `pending`, `in_process`, `refunded`, `charged_back`);
2. a moeda é **BRL**;
3. o valor pago, em centavos, é **exatamente igual** a `orders.total_cents` do pedido (ex.: R$ 123,45 ⇔ `12345`);
4. o `external_reference` é **igual** ao `order_number` do pedido (ex.: `MIMOO-ABC12345`);
5. em produção, o pagamento é **real** (`live_mode = true`), não sandbox.

Se qualquer item falhar, **não libere o pedido**: trate como estorno ou contato com o cliente.

Anote: `<ORDER_NUMBER>`, `<PAYMENT_ID>` e o nome de quem conferiu (`<OPERADOR>`). Não anote CPF, e-mail nem telefone
do cliente no chamado nem no terminal.

## Procedimento (`php artisan tinker`, no servidor, dentro de `backend/`)

Substitua os três valores no topo e cole o bloco inteiro:

```php
$orderNumber = '<ORDER_NUMBER>';
$paymentId   = '<PAYMENT_ID>';
$operador    = '<OPERADOR>';

$order = \App\Models\Order::where('order_number', $orderNumber)->firstOrFail();

\Illuminate\Support\Facades\DB::transaction(function () use ($order, $paymentId, $operador) {
    // Mesmo update condicional do webhook: só avança se o pedido ainda estiver pendente
    $updated = \App\Models\Order::whereKey($order->id)
        ->where('status', \App\Enums\OrderStatus::PENDING_PAYMENT->value)
        ->update([
            'status'  => \App\Enums\OrderStatus::IN_QUEUE->value,
            'paid_at' => now(),
        ]);

    if ($updated === 0) {
        // O pedido não está mais pendente (já liberado, cancelado ou alterado): nada é gravado
        throw new \RuntimeException('Pedido não está em pending_payment. Nada foi alterado.');
    }

    // Registro público (aparece na timeline de rastreio): mesmo code do webhook
    $order->productionLogs()->create([
        'previous_status' => \App\Enums\OrderStatus::PENDING_PAYMENT->value,
        'new_status'      => \App\Enums\OrderStatus::IN_QUEUE->value,
        'notes'           => "Liberado após conferência manual do pagamento {$paymentId} por {$operador}",
        'code'            => \App\Models\ProductionLog::CODE_PAYMENT_APPROVED,
        'reference'       => $paymentId,
        'is_internal'     => false,
        'created_at'      => now(),
    ]);

    // Registro interno (fora da timeline pública): quem conferiu e o quê
    $order->productionLogs()->create([
        'previous_status' => \App\Enums\OrderStatus::PENDING_PAYMENT->value,
        'new_status'      => \App\Enums\OrderStatus::IN_QUEUE->value,
        'notes'           => "Conferência manual por {$operador}: pagamento {$paymentId} approved, BRL, valor = total_cents, external_reference = order_number.",
        'code'            => 'payment_manual_release',
        'reference'       => $paymentId,
        'is_internal'     => true,
        'created_at'      => now(),
    ]);
});

$order->refresh()->status; // deve mostrar IN_QUEUE
```

Se aparecer a exceção `Pedido não está em pending_payment`, nada foi gravado: recarregue o painel e
veja o status atual antes de fazer qualquer outra coisa.

## Depois de liberar

- O pedido sai do filtro "Pagamento em revisão", porque o badge só vale para `pending_payment`/`cancelled`, e
  aparece em "Na fila" com o botão **Iniciar Impressão**.
- **Não é disparado o `OrderPaidEvent`**. Por isso o cliente **não recebe o e-mail de confirmação**, que de todo
  modo estaria quebrado pelo bug #13. Isso é aceitável até o PR "Resolver revisão". Se o cliente precisar de
  aviso, faça o contato manual pelo canal de atendimento.
- A timeline pública de rastreio passa a mostrar "Liberado após conferência manual do pagamento …". O
  registro interno com a conferência só aparece no banco/painel.
