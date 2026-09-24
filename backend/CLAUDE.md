# Backend Laravel — convenções

- `declare(strict_types=1);` em todo arquivo PHP novo.
- Models com `HasUuids`; PKs e FKs `uuid`. Colunas monetárias `*_cents` inteiras.
- DTOs readonly em `app/DTOs` (ver `CheckoutOrderDTO`). Controllers finos: validação em FormRequest (`app/Http/Requests`), lógica em `app/Services`, saída em `app/Http/Resources`.
- Enums em `app/Enums` implementam `HasLabel/HasColor/HasIcon` do Filament; labels em pt-BR.
- Erros de domínio viram exceções próprias em `app/Exceptions` (ver `InvalidOrderStatusTransitionException`).
- E-mails/notificações: listeners/jobs `ShouldQueue`, disparados após commit.
- Filament Resources (`app/Filament/Resources`) nunca editam `total_cents`, `shipping_cost_cents` nem snapshots de `OrderItem`; mudança de status pelo `ProductionWorkflowService`.
- Testes: PHPUnit em `tests/Feature`, trait `DatabaseTransactions` ou `RefreshDatabase`, `Http::fake()` para Mercado Pago, Melhor Envio e ViaCEP — nunca chamar API real em teste.
- Formatação: `vendor/bin/pint --dirty` (roda automaticamente por hook após editar .php).
- Nunca leia nem edite `.env`; use `.env.example` como referência de chaves.
