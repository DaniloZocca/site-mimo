---
name: mimoo-migrations-uuid
description: Como criar migrations, models e colunas no backend da MIMOO (UUID, centavos, índices, SQLite/MariaDB). Use ao criar tabela, coluna, índice, foreign key, model Eloquent ou alterar schema.
---
# Migrations e models
- Sempre migration NOVA (`php artisan make:migration`); nunca edite as existentes.
- PK `$table->uuid('id')->primary()`; model com `HasVersion4Uuids` (UUID ordenado v4; o `HasUuids` do Laravel 12 gera v7). FKs `foreignUuid(...)->constrained()` com `cascadeOnDelete()`/`restrictOnDelete()` explícito.
- Dinheiro: `integer('*_cents')` (padrão atual do projeto). Nunca `decimal`/`float` para dinheiro.
- Índice em toda coluna de busca (order_number, tracking_token, slug, status).
- Compatível com SQLite e MariaDB 11.8 (o banco de produção, M-45): sem SQL cru específico de banco; `enum` do banco evitado (use string + enum PHP).
- `down()` funcional.
- Atualize model (`$fillable`, `$casts` com enums), factory e seeder se aplicável. Dados pessoais sensíveis (CPF) com cast `encrypted`.
- Verificação: `php artisan migrate:fresh --seed --env=testing` (nunca em produção) e `php artisan test`.
