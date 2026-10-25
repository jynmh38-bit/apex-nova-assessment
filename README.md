# Apex Nova PHP Engineer Assessment

## Project structure

- `question.php`: PHP membership lookup exercise.
- `stage2-code-review.txt`: review feedback for the supplied OrderController.
- `inventory-api/`: Laravel 11 product inventory REST API.
- `inventory-api/docs/`: OpenAPI specification and Postman collection.

## Question 1: PHP lookup

From the repository root:

```sh
php -d memory_limit=2000M question.php
```

The script generates 100 million random numbers and records each distinct number as an array key. A direct key lookup determines whether the target exists. The supplied benchmark starts after population, so the reported time measures lookup rather than total execution time. Memory usage and elapsed time depend on the runtime and machine.

## Question 2: Product Inventory API

Senior PHP Engineer assessment, Question 2. Laravel **11.57.0**, Sanctum 4, SQLite by default. The framework constraint stays within Laravel 11; `composer.lock` records the tested versions.

## Local setup (Laragon / Windows)

Requirements: PHP 8.4 with PDO SQLite, mbstring, XML, curl, fileinfo, and Composer. PHP 8.4 matches the committed dependency lock. No Node or frontend build is required.

```powershell
cd inventory-api
composer install
Copy-Item .env.example .env
php artisan key:generate
if (!(Test-Path database/database.sqlite)) { New-Item -ItemType File database/database.sqlite }
php artisan migrate --seed
php artisan serve
```

For an existing installation, run `php artisan serve` from `inventory-api`. API base URL: `http://127.0.0.1:8000/api`. Do not overwrite an existing `.env` during normal use. For Laragon virtual hosts, point the document root at `inventory-api/public`.

Demo login: `interviewer@example.com` / `DemoPassword123!`. These are local demonstration credentials. No public registration endpoint is exposed. All authenticated users manage the same inventory; roles and tenant isolation are outside this brief.

## Try the API in PowerShell

```powershell
$base = 'http://127.0.0.1:8000/api'
$login = Invoke-RestMethod -Method Post -Uri "$base/login" -ContentType 'application/json' -Body '{"email":"interviewer@example.com","password":"DemoPassword123!"}'
$headers = @{ Authorization = "Bearer $($login.token)"; Accept = 'application/json' }
Invoke-RestMethod -Uri "$base/categories" -Headers $headers
Invoke-RestMethod -Uri "$base/suppliers" -Headers $headers
$body = @{ name = 'Demo Keyboard'; sku = 'DEMO-001'; category_id = 1; price = '79.90'; stock = 12; supplier_ids = @(1) } | ConvertTo-Json
$created = Invoke-RestMethod -Method Post -Uri "$base/products" -Headers $headers -ContentType 'application/json' -Body $body
Invoke-RestMethod -Uri "$base/products/$($created.data.id)" -Headers $headers
Invoke-RestMethod -Uri "$base/products?category_id=1&min_price=10&max_price=100&min_stock=1&max_stock=20&per_page=10" -Headers $headers
Invoke-RestMethod -Method Patch -Uri "$base/products/$($created.data.id)" -Headers $headers -ContentType 'application/json' -Body '{"stock":0,"supplier_ids":[]}'
Invoke-RestMethod -Method Delete -Uri "$base/products/$($created.data.id)" -Headers $headers
Invoke-RestMethod -Method Post -Uri "$base/logout" -Headers $headers
```

Use a new unique SKU when repeating the create example. Obtain category and supplier IDs from their endpoints if the seed data has changed.

## Endpoints

| Method | Path (under `/api`) | Result |
| --- | --- | --- |
| POST | `/login` | Email/password exchange for an 8-hour bearer token |
| POST | `/logout` | Revoke the current token, 204 |
| GET | `/user` | Current user, excluding password |
| GET | `/products` | Filtered, paginated products |
| POST | `/products` | Create product with optional supplier IDs, 201 |
| GET | `/products/{id}` | Product and relationships |
| PUT/PATCH | `/products/{id}` | Update supplied fields, 200 |
| DELETE | `/products/{id}` | Soft delete, 204 |
| GET | `/categories` | Paginated reference data |
| GET | `/suppliers` | Paginated reference data |

All endpoints except login require `Authorization: Bearer <token>`. Send `Accept: application/json`; API errors also render JSON without it. JSON bodies require `Content-Type: application/json`.

Product input: `name`, `sku`, `category_id`, `price`, `stock`; optional nullable `description` and optional `supplier_ids` array. Create requires all five main fields. PUT and PATCH both use partial-update semantics: omitted fields are preserved. Omitted `supplier_ids` preserves links; `[]` clears them. Supplier IDs must exist and be distinct. SKU is trimmed and uppercased before validation and again by the model mutator. SKU remains reserved after soft deletion.

List query: `category_id`, `min_price`, `max_price`, `min_stock`, `max_stock`, `page`, `per_page` (default 15, maximum 100). Bounds are inclusive and combined with AND. `max_stock=0` selects out-of-stock items. Inverted ranges return 422. Lists are ordered by ID and contain `data`, `links`, `meta`; detail responses use `data`. Prices are decimal strings to avoid binary float response formatting. `in_stock` is a computed boolean accessor.

Errors: 401 unauthenticated, 404 missing or deleted product, 422 validation (including invalid login), 429 rate limit. Login is limited to 5 attempts/minute/IP; other endpoints to 60 requests/minute/user. Limits use Laravel's configured cache store (database locally). Product responses are deliberately read from the database so stock updates are immediately visible; response caching is not implemented.

## Tests and formatting

Run from `inventory-api`:

```powershell
php artisan test
php vendor/bin/pint --test
php artisan --version
```

Feature tests use an isolated in-memory SQLite database and real Sanctum bearer tokens. They cover login/logout, invalid credentials, unauthenticated routes, creation, validation, SKU normalization/uniqueness, combined filters, pagination, zero-stock boundaries, show/404, partial updates, supplier replacement/removal, soft deletes, reference lists, and login rate limiting.

## Implementation notes

The design uses standard Laravel controllers, Eloquent models, Form Requests and Resources without additional repository/service abstractions for this small CRUD API.

The product and supplier pivot write happen in one database transaction. Eager loading prevents per-product relationship queries. Foreign keys protect category/supplier references; a composite pivot primary key prevents duplicate supplier links. Indexes support category, price, and stock filtering. Soft deletion keeps the product and its supplier links available for a future restore feature, which is not exposed here. Large datasets may need query-plan-driven composite indexes and cursor pagination.

## Docker (optional local demo)

```sh
cd inventory-api
docker compose up --build
```

The entrypoint creates a persistent SQLite database, migrates and seeds it, then serves port 8000. `docker compose exec api php artisan test` runs tests. The image uses PHP 8.4 and includes dev dependencies so assessment tests are available. This uses PHP's development server and is a local demonstration setup, not production hosting.

Verification on this checkout: 16 tests passed (82 assertions), Pint passed, and a live HTTP login/list/logout smoke check passed. The Compose configuration parsed successfully. The image build and container run could not be verified because the local Docker daemon was unavailable.

## OpenAPI

Import [OpenAPI specification](inventory-api/docs/openapi.json) into Swagger Editor or Postman. It documents the bearer token flow, product schemas, filters, pagination, and errors.

## Version and dependency audit

Laravel 11 is retained because the assessment explicitly requires it. The installed version is 11.57.0. At build time, `composer audit` reported five advisory entries affecting `laravel/framework`, including two entries for the same email-validation issue. See [dependency audit](inventory-api/composer-audit.json) for the captured results. No advisories have been suppressed. Keep debug disabled outside local development; a production deployment should first agree on upgrading to a supported framework release and rerun the audit.

## Postman collection

Import [Apex Nova Inventory API.postman_collection.json](inventory-api/docs/Apex%20Nova%20Inventory%20API.postman_collection.json) into Postman. Start the API and seed the database, then run the 17 requests in numbered order, individually or using Collection Runner.

The collection's `base_url` defaults to `http://127.0.0.1:8000/api`. Login stores `auth_token` automatically; create stores `product_id` and generates a unique SKU. The remaining requests cover CRUD, each filter, pagination, validation, missing authentication, soft deletion, logout and token revocation. The sequence creates a demo product and soft-deletes it. It does not delete the seeded products.

Collection variables should not be overridden by environment variables with the same names. No live bearer token is stored in the exported file.

## Repository contents

Include `composer.lock`, migrations, seeders, tests and API documentation. Local `.env` files, dependencies, SQLite databases and runtime logs are excluded through `.gitignore`.
