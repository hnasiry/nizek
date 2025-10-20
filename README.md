# Nizek Stock Import

A Laravel 12 stock reporting application built for the Nizek interview task. Authenticated users can upload large historical stock spreadsheets, queue them for background processing, and access reporting APIs that surface percentage changes over custom and preset time horizons.

---

## Highlights

- **Company management & imports:** Livewire Volt dashboards (Flux UI components) let you register companies, upload XLSX/CSV files up to 50 MB, and monitor the latest 10 imports in real time.
- **Streaming Excel pipeline:** `spatie/simple-excel` streams uploads and dispatches chunked jobs (`PrepareStockImport` -> `ProcessStockImportChunk`) on the `imports` queue. Redis + Horizon keeps throughput predictable even with thousands of rows.
- **Precise price storage:** Prices are stored as integer minor units (1 x 10^-6) via a dedicated `Price` value object, preventing floating-point drift in long-running comparisons.
- **Reporting APIs:** Sanctum-secured endpoints return custom date comparisons and predefined performance periods (`1D`, `1M`, ..., `MAX`). Responses include raw change ratios and human-friendly percentage strings.
- **Caching & resilience:** Performance summaries cache for `STOCK_REPORT_CACHE_TTL` seconds and automatically invalidate when new prices arrive. Missing history gracefully returns `change: null` / `formatted: "none"`.
- **Developer tooling:** Dockerised with Laravel Sail, Horizon for queue oversight, Pest test suite, and a ready-to-import Postman collection (`postman_collection.json`).

---

## Architecture in Brief

- **Domain Layer**: Actions encapsulate business flows (e.g. `QueueStockImport`, `BuildStockPerformanceSummary`). Jobs are idempotent and safe to retry.
- **Data Model**: `companies`, `stock_imports`, and `stock_prices` tables track source metadata, import lifecycle, and price history. Import statuses cycle through `pending -> queued -> processing -> completed/failed`.
- **Import Lifecycle**: Uploads are stored on `STOCK_IMPORT_DISK` (local by default). `PrepareStockImport` streams the file, sanitises each row (expects `date` + `stock_price` headers), then batches chunk jobs that upsert prices and advance counters.
- **Reporting**: Reports leverage indexed lookups plus helper calculators for percentage deltas. Cache keys include the company `updated_at` timestamp so new imports bust stale data automatically.

---

## Prerequisites

- PHP 8.4+, Composer, and Node 18+ (or Docker with Laravel Sail)
- Redis (bundled with Sail; required for queues and cache)
- SQLite (default), MySQL, or another database supported by Laravel
- npm or pnpm to build the Flux UI assets

---

## Local Environment Setup

> **Tip:** The quickest path is Laravel Sail (Docker). Native setup instructions follow afterwards.

### 1. Clone & install dependencies

```bash
git clone git@github.com:hnasiry/nizek.git nizek-stock-prices
cd nizek-stock-prices
composer install
npm install
```

### 2. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Review `.env` and adjust as needed:

- `APP_URL` - match your local host/port.
- `DB_CONNECTION` - defaults to SQLite; set MySQL credentials if you prefer another driver.
- `QUEUE_CONNECTION=redis` - required for background imports.
- `STOCK_IMPORT_DISK`, `STOCK_IMPORT_QUEUE`, `STOCK_IMPORT_CHUNK_SIZE` - tune storage, queue name, and chunk size.
- `STOCK_REPORT_CACHE_TTL` - caching window in seconds (default 300).

### 3. Start the stack (Laravel Sail)

```bash
./vendor/bin/sail up -d      # boots PHP, MySQL, Redis, MinIO, etc.
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan storage:link
./vendor/bin/sail npm run dev   # or `npm run build` for a production build
./vendor/bin/sail artisan horizon
```

- Horizon supervises the `imports` queue. If you prefer a plain worker: `./vendor/bin/sail artisan queue:work --queue=imports`.
- Horizon dashboard is available at `/horizon` while the app is running.
- Shut the stack down with `./vendor/bin/sail down` when you finish.

### Running with Laravel Sail after setup

Once dependencies and the `.env` file are in place, you can boot the full Docker environment with:

```bash
./vendor/bin/sail up
```

Visit `http://localhost` (or the URL configured in your `.env`) to use the app. Use `./vendor/bin/sail up -d` to run it in the background and `./vendor/bin/sail down` to stop the containers.

### 4. Native (non-Docker) alternative

1. Ensure PHP extensions (`bcmath`, `redis`) and Redis server are installed.
2. Create a database (or rely on the default SQLite file).
3. Run:
   ```bash
   php artisan migrate
   php artisan storage:link
   npm run dev
   php artisan horizon   # or `php artisan queue:work --queue=imports`
   php artisan serve
   ```
4. Update `.env` with your local Redis host/port if not default.

---

## Testing

Automated tests cover the core workflows. Run them natively with:

```bash
php artisan test
```

If you're using Sail, prefix the command instead:

```bash
./vendor/bin/sail artisan test
```

## Using the Dashboard

1. **Register / sign in** - the auth scaffolding is powered by Laravel Fortify.
2. **Create companies** - visit `/dashboard/companies` to add a name + ticker symbol. Slugs are generated automatically.
3. **Queue imports** - on `/dashboard` choose a company, upload an `.xlsx` or `.csv` file (50 MB limit), and submit. Required columns: `Date` (or `date`) and `stock_price` (or `price`).
4. **Monitor progress** - the Recent Imports table refreshes every 15 seconds. Status colours map to `pending`, `queued`, `processing`, `completed`, or `failed`.
5. **Investigate issues** - Horizon and application logs (`storage/logs/laravel.log`) capture batch IDs and failure reasons.

Uploaded spreadsheets are stored under `storage/app/imports` (or the disk you configure). Imports stream row-by-row to keep memory usage flat and upsert prices idempotently, so re-uploading the same data simply updates the latest price.

---

## API Reference

All reporting endpoints require a Sanctum bearer token.

### 1. Issue an API token

- **Endpoint**: `POST /api/auth/login`
- **Payload**:

  ```json
  {
    "email": "dev@example.com",
    "password": "top-secret",
    "token_name": "Postman"   // optional
  }
  ```

- **Response** (`201 Created`):

  ```json
  {
    "token": "plain-text-token"
  }
  ```

Store the token immediately; the server retains only a hashed version. Attach it to subsequent requests:

```
Authorization: Bearer plain-text-token
```

### 2. Compare two custom dates

- **Endpoint**: `GET /api/companies/{company}/stock-prices/comparison`
- **Query params**:
  - `from` - required `Y-m-d`
  - `to` - required `Y-m-d`, must be >= `from`
- **Example**:

  ```
  GET /api/companies/1/stock-prices/comparison?from=2025-04-29&to=2025-04-30
  ```

- **Response** (`200 OK`):

  ```json
  {
    "data": {
      "change": "-0.011700",
      "formatted": "-1.17%"
    }
  }
  ```

If either date is missing in the history, `change` becomes `null` and `formatted` returns `"none"`.

### 3. Performance summary across periods

- **Endpoint**: `GET /api/companies/{company}/stock-prices/performance`
- **Query params**:
  - `as_of` - optional `Y-m-d` (default: latest available price)
  - `periods` - optional list/CSV of period codes. Valid values:
    `1D`, `1M`, `3M`, `6M`, `YTD`, `1Y`, `3Y`, `5Y`, `10Y`, `MAX`
- **Examples**:
  - `GET /api/companies/1/stock-prices/performance`
  - `GET /api/companies/1/stock-prices/performance?as_of=2025-04-30&periods=1D,1M,1Y`
- **Response** (`200 OK`):

  ```json
  {
    "data": {
      "periods": [
        { "period": "1D", "change": "-0.011700", "formatted": "-1.17%" },
        { "period": "1M", "change": "0.068300", "formatted": "6.83%" },
        { "period": "3M", "change": "0.158900", "formatted": "15.89%" },
        { "period": "6M", "change": "0.298500", "formatted": "29.85%" },
        { "period": "YTD", "change": "0.421200", "formatted": "42.12%" },
        { "period": "1Y", "change": "0.552300", "formatted": "55.23%" },
        { "period": "3Y", "change": "0.904500", "formatted": "90.45%" },
        { "period": "5Y", "change": "1.562700", "formatted": "156.27%" },
        { "period": "10Y", "change": "6.284500", "formatted": "628.45%" },
        { "period": "MAX", "change": "24.509600", "formatted": "2450.96%" }
      ]
    }
  }
  ```

Periods without sufficient history return `{"period": "...", "change": null, "formatted": "none"}`. Responses are cached per company + parameter set for faster repeated queries.

---

## Configuration Cheat Sheet

| Key | Purpose | Default |
| --- | --- | --- |
| `QUEUE_CONNECTION` | Queue backend | `redis` |
| `STOCK_IMPORT_QUEUE` | Queue name used by import jobs | `imports` |
| `STOCK_IMPORT_DISK` | Filesystem disk for uploaded spreadsheets | `local` |
| `STOCK_IMPORT_CHUNK_SIZE` | Rows per queued chunk | `500` |
| `STOCK_REPORT_CACHE_TTL` | Cache time (seconds) for performance API | `300` |

Adjust these in `.env` and restart workers after changes.

---

## Testing

1. Ensure your test database is configured (`php artisan migrate --env=testing` or use the default in-memory SQLite).
2. Run the full suite:

   ```bash
   php artisan test
   ```

3. Targeted suites:
   - Import pipeline: `php artisan test tests/Feature/Stocks/StockImportPipelineTest.php`
   - Dashboard upload: `php artisan test tests/Feature/Stocks/StockImportDashboardTest.php`
   - Reporting APIs: `php artisan test tests/Feature/Stocks/StockReportingApiTest.php`

The suite uses Pest and fakes storage/queues where appropriate, so you can run it without a live Redis instance.

---

## Tooling & Extras

- **Horizon dashboard**: `/horizon` (requires running Horizon process).
- **Postman collection**: Import `postman_collection.json` for ready-made requests.
- **Sample dataset**: `tests/Fixtures/dummy_stock_prices.xlsx` mirrors the provided spreadsheet and powers end-to-end tests.
- **Formatting**: `vendor/bin/pint --dirty` keeps PHP code styled; `npm run lint` or `npm run build` ensures front-end assets compile cleanly.

---

## Troubleshooting

- Imports stuck in `queued`? Make sure Horizon or a queue worker is running against the `imports` queue and Redis is reachable.
- Missing percentages? Verify the uploaded file includes both `Date` and `stock_price` columns and the rows are parsable.
- Tokens not working? Remember that each login call issues a new Sanctum token; revoke or rotate under **Settings -> API Tokens**.

Happy importing!
