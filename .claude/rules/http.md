---
path: "src/Http/**/*.php"
---

# HTTP Layer

## Controllers

- Inject contracts via `app(ContractClass::class)`, not constructor type-hinting
- Return `JsonResponse` — never return views or redirects (API-only package)
- Wrap external calls (Socialite, cache) in try-catch with `report($exception)`:
  ```php
  try {
      // operation
  } catch (Throwable $exception) {
      report($exception);
      $payload = ['message' => 'User-friendly message'];
      if (config('app.debug')) {
          $payload['error'] = $exception->getMessage();
      }
      return response()->json($payload, 500);
  }
  ```
- Use `AuthenticatesUsers` concern trait for shared auth response logic
- Set user resolver before returning response: `$request->setUserResolver()`

## Requests

- `authorize()` always returns `true` — auth handled by middleware, not form requests
- Build rules dynamically based on feature flags:
  - `Features::emailIdentity()` / `Features::phoneIdentity()` for login field rules
  - `required_without:phone` / `required_without:email` for dual-identity
- Use `new E164Phone` rule inline for phone validation (not regex string)
- Return `array<string, mixed>` from `rules()` with explicit types

## Resources

- Use `$this->when(Features::has*(), fn () => ...)` for feature-conditional fields
- Guard trait methods: `method_exists($this->resource, 'methodName')` before calling
- Wrap nested resources: `new TeamResource($this->getCurrentTeamOrPersonal())`
- Role resolution has 3-step fallback: owner check → pivot role → DB lookup
- Return typed: `toArray(Request $request): array<string, mixed>`
