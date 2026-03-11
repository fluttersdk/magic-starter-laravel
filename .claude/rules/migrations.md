---
path: "database/migrations/**/*.php"
---

# Migrations

- Use anonymous class syntax: `return new class extends Migration { ... }`
- Idempotency: wrap `Schema::create()` in `if (! Schema::hasTable('table_name'))` check
- Primary keys: `MigrationHelper::primaryKey($table)` — never raw `$table->id()` or `$table->uuid()`
- Foreign keys: `MigrationHelper::foreignKey($table, 'user_id')->constrained()->cascadeOnDelete()`
- Morph columns: `MigrationHelper::morphColumns($table, 'notifiable')` — handles UUID/int polymorphism
- String fields: use explicit max length (`string('field', 255)`)
- `profile_photo_path`: 2048 chars max (filesystem paths can be long)
- `phone_country`: `char(2)` fixed width (ISO 3166-1 alpha-2)
- Always include `$table->timestamps()` on entity tables
- Default migrations always published; feature migrations conditional per feature flag
