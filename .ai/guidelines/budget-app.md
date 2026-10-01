# Budget app project rules

The product spec lives in `docs/SPEC.md` (Hungarian). Read the relevant section before starting a feature.

- Code, database, identifiers and commit messages in English; UI text in Hungarian via translation files (`lang/hu.json`, `lang/en.json`), always through `__()`.
- Money is always an `int` in the smallest currency unit of the user's base currency (HUF: forint, EUR: cent). Never use floats for money. Use the `App\Support\Money` value object / `MoneyCast`.
- All calculations live in plain, unit-tested services under `app/Services` (`BudgetCalculator`, `ForecastService`, `PeriodCloser`, `LoanCalculator`). Livewire components stay thin: no business math inside components.
- Every user-owned model is scoped by `user_id` through the `BelongsToUser` trait (global scope + auto-fill on create).
- Every service method gets a unit test, every screen a feature test. Tests use Pest.
- No N+1 queries: `Model::preventLazyLoading()` is enabled outside production.
- Use bun (never npm) for JS tooling: `bun install`, `bun run build`.
- Before finishing a change run `composer test` (Pint check, Rector dry-run, Larastan max, Pest).
