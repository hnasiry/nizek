# Nizek Stock Import

## Design Decisions

- The `stock_prices` table intentionally omits `stock_import_id` and timestamp columns. We can reintroduce them later if auditing or traceability outweighs the additional storage and write overhead, but for now we prioritize leaner rows and simpler indexes.
- Stock prices are persisted as integer minor units (1/1,000,000th of a currency unit). This keeps the table compact, makes indexes cheaper for range scans, and sidesteps floating-point quirks when aggregating historical prices. Eloquent exposes a dedicated `Price` value object so callers continue to work with human-friendly decimals.
- Percentage calculations and conversions back to formatted strings rely on PHP's BCMath extension. BCMath gives us deterministic precision without drift, which is essential when we compare long-running series that were captured with sub-cent granularity.

## API Access

- Personal access tokens are issued via Laravel Sanctum. After running the new migration (`php artisan migrate`), authenticated users can call `POST /api/auth/login` with `email`, `password`, and an optional `token_name` to receive a bearer token.
- The token endpoint returns `{ "token": "plain-text-token" }`. Copy the response immediately—the server only stores a hashed version.
- Subsequent API calls must include `Authorization: Bearer <token>` when hitting routes under `/api/companies/{company}/stock-prices/*`.
- The dashboard now exposes **Settings -> API Tokens**, where users can generate, regenerate, and revoke tokens. Each token is shown only once; regenerating creates a fresh credential with the same name.
- Local testing in tools like Postman no longer requires reusing browser sessions. Optionally, set `SANCTUM_STATEFUL_DOMAINS` when working with a custom domain or port so session-authenticated SPAs continue to work.
