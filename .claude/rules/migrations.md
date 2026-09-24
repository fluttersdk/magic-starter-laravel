---
path: "database/migrations/**/*.php"
---

# Migrations

- Use anonymous class syntax: `return new class extends Migration { ... }`
- Idempotency: wrap `Schema::create()` in `if (! Schema::hasTable('table_name'))` check
- Primary keys: `MigrationHelper::primaryKey($table)` — never raw `$table->id()` or `$table->uuid()`
  - The one table keyed by a UUID in both modes is `notifications`, whose id is always `$table->uuid('id')->primary()`, because Laravel's `database` channel writes the notification's own UUID there and an integer key refuses every insert. Its `notifiable` morph columns still follow `use_uuids`; `rekey_notifications_table_by_uuid` repairs a table built before this.
- Foreign keys: `MigrationHelper::foreignKey($table, 'user_id')->constrained()->cascadeOnDelete()`
- Morph columns: `MigrationHelper::morphColumns($table, 'notifiable')` — handles UUID/int polymorphism
- String fields: use explicit max length (`string('field', 255)`)
- `profile_photo_path`: 2048 chars max (filesystem paths can be long)
- `phone_country`: `char(2)` fixed width (ISO 3166-1 alpha-2)
- Always include `$table->timestamps()` on entity tables
- Default migrations always published; feature migrations conditional per feature flag
