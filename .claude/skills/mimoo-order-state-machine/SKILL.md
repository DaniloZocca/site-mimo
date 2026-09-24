---
name: mimoo-order-state-machine
description: Máquina de estados de pedidos (OrderStatus) e ProductionWorkflowService da MIMOO. Use sempre que a tarefa envolver status de pedido, fila de produção, Filament OrderResource, ProductionLog, timeline de rastreio, ManufacturingStepper, e-mails de status ou eventos OrderPaid/OrderStatusUpdatedEvent/OrderShipped.
---
# Máquina de estados

Transições permitidas (qualquer outra lança `InvalidOrderStatusTransitionException`):
pending_payment → in_queue | cancelled
in_queue → printing | cancelled
printing → finishing_and_packaging | cancelled
finishing_and_packaging → shipped | cancelled
shipped → delivered
delivered, cancelled: finais

## Regras
- O mapa vive em `ProductionWorkflowService::ALLOWED_TRANSITIONS`; `transitionTo(Order, OrderStatus, notes, author)` é o ÚNICO ponto que muda status. Webhook, Filament e comandos chamam esse método.
- SQLite ignora `lockForUpdate()`. Use update condicional: `Order::whereKey($id)->where('status', $from)->update([...])`; 0 linhas afetadas = conflito → exceção, sem sobrescrever.
- Toda transição grava `ProductionLog` (previous_status, new_status, notes, autor) na mesma transação e dispara `OrderStatusUpdatedEvent` depois do commit.
- Notificações e e-mails são listeners enfileirados.
- Front (`src/components/tracking/ManufacturingStepper.jsx`) só exibe; labels vêm de `OrderStatus::getLabel()`.
- Há eventos duplicados (`OrderPaid` e `OrderPaidEvent`, `OrderShipped` e `OrderShippedEvent`): confira qual tem listener registrado antes de disparar.

## Ao adicionar um estado
Enum (label/cor/ícone) + `ALLOWED_TRANSITIONS` + Filament + stepper do front + teste cobrindo TODAS as transições válidas e inválidas (data provider do PHPUnit).
