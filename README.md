# Nizek Stock Import

## Design Decisions

- The `stock_prices` table intentionally omits `stock_import_id` and timestamp columns. We can reintroduce them later if auditing or traceability outweighs the additional storage and write overhead, but for now we prioritize leaner rows and simpler indexes.
