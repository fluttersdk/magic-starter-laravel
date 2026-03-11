---
path: "tests/**/*.php"
---

# Tests

- Extend `FlutterSdk\MagicStarter\Tests\TestCase` (wraps Orchestra Testbench)
- Use `RefreshDatabase` trait for database isolation (in-memory SQLite)
- Register models in `setUp()`: `MagicStarter::useUserModel(ConcreteUser::class)`, etc.
- Create schema manually in `setUp()` via `Schema::create()` — no migration runner
- Override config in `setUp()`: `config(['magic-starter.features' => [...]])`
- Use fixtures from `tests/Fixtures/`: ConcreteUser, ConcreteTeam, ConcreteTeamUser, ConcreteTeamInvitation
- Fixtures use UUID primary keys — match with `ConditionallyUsesUuids` trait
- Test method naming: `test_<action>_<expected_behavior>` (snake_case)
- Arrange-Act-Assert pattern with explicit assertions (`assertEquals`, `assertTrue`, `assertNull`)
- Feature tests: call endpoints via `$this->postJson()`, `$this->getJson()`, assert status + JSON structure
