# Nizek Stock Import

## Design Decisions

- The `stock_prices` table intentionally omits `stock_import_id` and timestamp columns. We can reintroduce them later if auditing or traceability outweighs the additional storage and write overhead, but for now we prioritize leaner rows and simpler indexes.
- Stock prices are persisted as integer minor units (1/1,000,000th of a currency unit). This keeps the table compact, makes indexes cheaper for range scans, and sidesteps floating-point quirks when aggregating historical prices. Eloquent exposes a dedicated `Price` value object so callers continue to work with human-friendly decimals.
- Percentage calculations and conversions back to formatted strings rely on PHP's BCMath extension. BCMath gives us deterministic precision without drift, which is essential when we compare long-running series that were captured with sub-cent granularity.
