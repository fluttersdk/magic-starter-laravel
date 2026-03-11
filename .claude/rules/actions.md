---
path: "src/Actions/**/*.php"
---

# Actions

- Each action implements exactly one contract from `Contracts/`
- Public method name matches contract: `create()`, `update()`, `invite()`, `delete()`
- Validate input inline with `Validator::make($input, $rules)->validate()` — not in constructor
- Use numbered inline comments to explain flow steps: `// 1. Validate`, `// 2. Create user`, etc.
- Resolve models via `MagicStarter::userModel()`, `::teamModel()` — never hardcode class names
- Gate feature-specific logic: `if (Features::hasTeamFeatures()) { ... }`
- Use `Arr::get($input, 'key')` for optional field retrieval from validated input
- Return typed values matching contract signature (Authenticatable, Model, void)
- Newsletter: use `firstOrCreate()` for idempotent subscriber creation
- Two-factor: defer notifications via events, don't send directly from action
