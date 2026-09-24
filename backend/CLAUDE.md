# Backend Laravel — convenções

- `declare(strict_types=1);` em todo arquivo PHP novo.
- Models com `HasUuids`; PKs e FKs `uuid`. Colunas monetárias `*_cents` inteiras.
- DTOs readonly em `app/DTOs` (ver `CheckoutOrderDTO`). Controllers finos: validação em FormRequest (`app/Http/Requests`), lógica em `app/Services`, saída em `app/Http/Resources`.
- Enums em `app/Enums` implementam `HasLabel/HasColor/HasIcon` do Filament; labels em pt-BR.
- Erros de domínio viram exceções próprias em `app/Exceptions` (ver `InvalidOrderStatusTransitionException`).
- E-mails/notificações: listeners/jobs `ShouldQueue`, disparados após commit.
- Filament Resources (`app/Filament/Resources`) nunca editam `total_cents`, `shipping_cost_cents` nem snapshots de `OrderItem`; mudança de status pelo `ProductionWorkflowService`.
- Testes: PHPUnit em `tests/Feature` (Pest não instalado). Testes novos de comportamento: estender `Tests\Feature\Characterization\Support\CharacterizationTestCase` (SQLite `:memory:` com trava, `Http::preventStrayRequests()`, tempo congelado, config fixa). Nunca chamar API real: `Http::fake()` para Melhor Envio; para o Mercado Pago use `FakeMercadoPagoHttpClient` (o SDK não passa pelo `Http`). ViaCEP é chamado só pelo front (`src/hooks/useViaCep.js`).
- Bug conhecido corrigido: apague o teste `characterization` que afirmava o comportamento antigo e mova o gêmeo de `tests/Feature/KnownBugs` para `Characterization` (grupo `characterization`); atualize `docs/known-bugs.md`.
- Validação: não existe `lang/pt_BR`; toda regra que o usuário vê precisa de mensagem própria em `messages()` (objetos de regra como `Rule\Enum` ignoram a chave `campo.enum`).
- Formatação: `vendor/bin/pint --dirty` (roda automaticamente por hook após editar .php).
- Nunca leia nem edite `.env`; use `.env.example` como referência de chaves.
