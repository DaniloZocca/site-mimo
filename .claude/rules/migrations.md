---
paths:
  - "backend/database/**"
---
# Migrations
- Nunca edite migration existente (o hook bloqueia). Crie uma nova com `php artisan make:migration`.
- Siga a skill `mimoo-migrations-uuid`. Toda migration precisa de `down()` funcional e rodar em SQLite e PostgreSQL.
