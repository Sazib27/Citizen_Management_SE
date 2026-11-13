# Citizen Services Portal

A municipal citizen-services platform: citizen verification with
digitally verifiable certificates, property registry and ownership
transfer, automated rental agreements, tax assessment with online
payment via SSL Commerz, and a tracked complaint workflow.

Built with **Laravel 11** on **PHP 8.2+**.

---

## Table of contents

- [Quick start](#quick-start)
- [Demo accounts](#demo-accounts)
- [Stack](#stack)
- [Features](#features)
- [Payment integration](#payment-integration)
- [Testing](#testing)
- [Project structure](#project-structure)
- [Documentation](#documentation)
- [Security](#security)
- [Docker](#docker)

---

## Quick start

### Requirements

- PHP **8.2+** with the `pdo_sqlite`, `mbstring`, `curl`, `dom` and
  `openssl` extensions
- Composer 2
- Node.js 18+ and npm (for the frontend build)
- MySQL 8 (or MariaDB 10.6+) — SQLite works for local development

### Install

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

### Configure the database

Edit `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=citizen_portal
DB_USERNAME=root
DB_PASSWORD=
```

### Migrate and seed

```bash
php artisan migrate --seed
php artisan storage:link
npm run build
```

The seeder creates two verified citizens, five properties, tax
assessments, and **one already-approved rent agreement** so the
dashboard has something to show immediately.

### Run

```bash
php artisan serve
```

Open <http://localhost:8000>.

### Enable SQLite for the test suite

The test suite runs against an in-memory SQLite database and needs the
`pdo_sqlite` extension. On Windows, uncomment these lines in `php.ini`:

```ini
extension=pdo_sqlite
extension=sqlite3
```

---

## Demo accounts

Created by `php artisan migrate --seed`:

| Role | Email | Password |
|---|---|---|
| **Admin** | `admin@citizen.gov` | `admin123` |
| **Tenant** | `test@gmail.com` | `12345678` |
| **Landlord** | `test1@gmail.com` | `12345678` |

The tenant and landlord already have an approved rent agreement between
them, so logging in as the tenant shows the agreement on the dashboard
under **My Active Rentals** with no setup required.

To seed the demo agreement on its own, without touching other data:

```bash
php artisan db:seed --class=RentAgreementDemoSeeder
```

---

## Stack

| | |
|---|---|
| Language | PHP 8.2+ |
| Framework | Laravel 11 |
| Front end | Blade + Tailwind + Vite |
| PDF | `barryvdh/laravel-dompdf` |
| Payments | SSL Commerz hosted checkout |
| Database | MySQL (production), SQLite (tests) |
| Tests | PHPUnit 10 via `php artisan test` |

---

## Features

### 1. Authentication and role-based access control

Two roles — `admin` and `citizen` — enforced by middleware rather than
by ad-hoc checks in controllers.

- Registration, login, logout, password reset
- Email verification by OTP (10-minute expiry, 5-attempt cap)
- `admin` middleware → the admin area
- `citizen` middleware → the citizen area
- `verified.citizen` → services that require an approved NID
- `verified.email` → services that require a confirmed email address
- Rate limiting: login (5/min per email+IP), registration (10/hr per
  IP), NID probe (20/min per IP)

### 2. Citizen verification and digital certificates

1. The citizen applies and submits their NID, date of birth and
   supporting documents.
2. The application is cross-checked against the NID registry — the
   full name and date of birth must match.
3. An admin reviews the application and the uploaded documents.
4. On approval, a **certificate is issued** with a random number
   (`VCN-XXXXXXXX`) and a persisted record.
5. The citizen downloads a PDF certificate carrying a QR code.
6. Anyone can scan the QR, or enter the certificate number, to confirm
   it is genuine — without seeing the holder's NID.

Certificates can be revoked and re-issued; re-issuing automatically
revokes the previous one.

### 3. Property management

- Citizens browse and search a public property registry — by title,
  address, city, state, postal code or owner name.
- Citizens request to add, modify or transfer a property; an admin
  approves each request.
- Ownership transfers notify both the old and new owner.
- Admins can create and edit properties directly.
- A property with active rent agreements or outstanding tax
  assessments **cannot be deleted** — the history would be destroyed.
  Deactivate it instead.

### 4. Rental agreements

1. A tenant requests to rent a property.
2. The property owner confirms the terms.
3. The admin approves, and the agreement is generated automatically with
   a unique number (`RA-YYYYMMDD-XXXXX`).
4. **Both the tenant and the landlord are notified** by email.
5. Both can download the agreement as a PDF, and find it again by its
   agreement number.

### 5. Tax assessment and online payment

- Admins issue per-property, per-fiscal-year assessments with a rate,
  an amount and a due date.
- Citizens see their outstanding balance, pay online through **SSL
  Commerz**, and download a receipt.
- A command flags overdue assessments and notifies the owner:

  ```bash
  php artisan tax:flag-overdue
  php artisan tax:flag-overdue --dry-run
  ```

### 6. Revenue dashboard and exports

- KPIs: issued, collected, outstanding, collection rate, average days
  to pay.
- Monthly issued-vs-collected trend.
- Delinquent owners ranking and ageing buckets for overdue assessments.
- Upcoming property valuation tracking.
- CSV exports for tax reconciliation and valuation schedules.

### 7. Complaint management

- Citizens file complaints against their own properties, with an
  optional attachment.
- The status follows a defined lifecycle: `open → in_progress →
  resolved | rejected`, with reopening allowed.
- **Every transition is recorded**, and the citizen sees the full
  timeline on the complaint.
- A rejection requires a stated reason.
- Once a complaint is closed, the citizen can rate the resolution out
  of five.
- Citizens can filter their own complaints by status and search by
  subject.

---

## Payment integration

SSL Commerz hosted checkout. The integration is written from scratch
against the two documented JSON endpoints rather than pulling in the
official SDK — the surface is small enough that the failure modes stay
visible.

**The controlling rule:** *every value arriving from the gateway is
treated as attacker-supplied until the gateway's own Validation API has
confirmed it against our records.*

The callback amount and currency arrive on the request and can be
forged. So before any payment is recorded, the server:

1. Calls the Validation API with the `val_id`.
2. Checks the gateway reports `VALID` or `VALIDATED`.
3. Checks the currency matches the one the session was opened in.
4. Checks the amount equals the assessment's `tax_amount` to the cent.
5. Checks the transaction id is the one we generated.

Only then is a `TaxPayment` written — using the **validated** amount,
not the one on the request. The same reconciliation runs on the IPN.

### Configuration

```dotenv
SSLCZ_STORE_ID=your-store-id
SSLCZ_STORE_PASSWORD=your-store-password
SSLCZ_SANDBOX=true
SSLCZ_CURRENCY=BDT

# Optional: shared secret required on the IPN endpoint.
# The gateway must send it as the X-SSLCommerz-Secret header.
SSLCZ_IPN_SECRET=

# Demo mode — no real money, no external calls. Useful for local
# development, demos and CI.
SSLCZ_DEMO_MODE=false
```

### Demo mode

With `SSLCZ_DEMO_MODE=true` the gateway is bypassed entirely: a local
confirmation page stands in for the hosted checkout, and the payment is
recorded locally. No money moves and no external API is contacted. The
test suite forces this on, so it can never reach the live API.

### The IPN

The IPN is called by SSL Commerz's servers, which carry no session
cookie — so the route sits **outside** the auth middleware. Because it
is unauthenticated, it is protected two ways: an optional shared-secret
header, and the mandatory gateway reconciliation described above.

### Callback URLs

```
Success:  {APP_URL}/citizen/taxes/payment/success
Fail:     {APP_URL}/citizen/taxes/payment/fail
Cancel:   {APP_URL}/citizen/taxes/payment/cancel
IPN:      {APP_URL}/citizen/taxes/payment/ipn
```

---

## Testing

```bash
php artisan test                              # everything
php artisan test --testsuite=Unit             # unit tests only
php artisan test --testsuite=Feature          # feature tests only
php artisan test --filter=ComplaintWorkflowTest
```

**89 tests, 284 assertions.**

The suite runs against an **in-memory SQLite** database, so it never
touches your development or production data and needs no database
server. See `docs/testing/strategy.md` for the coverage matrix and for
how to write a test in this codebase.

---

## Project structure

```
app/
├── Enums/ComplaintStatus.php        The complaint lifecycle
├── Http/
│   ├── Controllers/
│   │   ├── Admin*Controller.php     Admin area
│   │   ├── Citizen*Controller.php   Citizen area
│   │   ├── AuthController.php       Registration, login, reset, OTP
│   │   ├── SSLCommerzTaxPaymentController.php
│   │   └── VerificationController.php
│   └── Middleware/
│       ├── AdminMiddleware.php
│       ├── CitizenMiddleware.php
│       ├── EnsureCitizenVerified.php
│       └── EnsureEmailIsVerified.php
├── Models/                          Certificate, Complaint, ComplaintUpdate, …
├── Notifications/                   RentAgreementGenerated, …
└── Services/
    ├── SSLCommerzCheckoutService.php
    ├── TaxAssessmentService.php
    └── RevenueDashboardService.php

database/
├── factories/                       User, Property, Complaint, TaxAssessment, …
├── migrations/
└── seeders/                         UserSeeder, PropertySeeder, RentAgreementDemoSeeder, …

docs/
├── architecture/overview.md
├── methodology/
│   ├── hybrid-agile-spiral.md
│   └── spiral-iterations.md
├── risk/
│   ├── risk-analysis-method.md
│   ├── risk-register.md
│   ├── security-controls.md
│   └── threat-model.md
└── testing/strategy.md

tests/
├── Feature/                         HTTP-level tests
└── Unit/                            Pure logic tests
```

### Main domain entities

`User` · `FakeNid` · `Certificate` · `Property` · `PropertyRequest` ·
`RentalRequest` · `RentAgreement` · `TaxAssessment` · `TaxPayment` ·
`Complaint` · `ComplaintUpdate`

---

## Documentation

| Document | What it covers |
|---|---|
| [`docs/architecture/overview.md`](docs/architecture/overview.md) | Module map, domain model, middleware stack, key sequence diagrams |
| [`docs/methodology/hybrid-agile-spiral.md`](docs/methodology/hybrid-agile-spiral.md) | How the Spiral and Agile models compose, roles, Definition of Done |
| [`docs/methodology/spiral-iterations.md`](docs/methodology/spiral-iterations.md) | The seven loops — goal, risk analysis, engineering, outcome, risks retired |
| [`docs/risk/risk-analysis-method.md`](docs/risk/risk-analysis-method.md) | The scoring scales and the seven-step analysis procedure |
| [`docs/risk/risk-register.md`](docs/risk/risk-register.md) | Every identified risk, scored, with its mitigation, owner and status |
| [`docs/risk/threat-model.md`](docs/risk/threat-model.md) | STRIDE per feature, with trust boundaries |
| [`docs/risk/security-controls.md`](docs/risk/security-controls.md) | The control catalogue and the control-to-risk traceability matrix |
| [`docs/testing/strategy.md`](docs/testing/strategy.md) | Test levels, coverage matrix, conventions, intended CI pipeline |

---

## Security

Please report security issues privately rather than opening a public
issue. See the risk register for the full picture of what is controlled
and what is not.

**Key controls:**

- Role-based access control via middleware, not inline checks
- Login throttling, session regeneration, CSRF on every form
- Two-stage verification gates: email confirmed **and** NID approved
- Certificates with unguessable numbers, a full lifecycle, and public
  verification by number
- Payment callbacks reconciled against the gateway before anything is
  written
- DB-level idempotency on payment rows
- Verification documents served from a private disk through an
  authorised, field-allow-listed route

**Known gaps** — see `docs/risk/risk-register.md` for the scored list:

- Complaint attachments are stored on the public disk and served by a
  guessable URL (R-COMP-07, High)
- A citizen can submit the assessed value used for tax calculation
  (R-PROP-04, High)
- The rent agreement PDF has no detached cryptographic signature
  (R-RENT-03, High)
- The agreement goes live without a party-consent step (R-RENT-04, High)
- The overdue-assessment command is not scheduled (R-PAY-07, Medium)

---

## Docker

`docker-compose.yml` is included for `app + mysql`.

### Start

```bash
docker compose up -d --build
```

### First-time setup (Compose)

```bash
docker compose exec app php artisan key:generate --force
docker compose exec app php artisan storage:link
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed
```

### Logs

```bash
docker compose logs -f app
```

### Stop

```bash
docker compose down
```

### Reset the database volume

```bash
docker compose down -v
```

### Running a one-off command

```bash
docker compose exec app php artisan tax:flag-overdue --dry-run
```

---

## Environment notes

Important variables for enabled features:

- `APP_URL`
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
  `DB_PASSWORD`
- `MAIL_MAILER` and related mail credentials (for notifications)
- `SSLCZ_STORE_ID`, `SSLCZ_STORE_PASSWORD`, `SSLCZ_SANDBOX`,
  `SSLCZ_CURRENCY`, `SSLCZ_IPN_SECRET`, `SSLCZ_DEMO_MODE`

Tax rate and valuation settings are in `config/tax.php`.

---

## Licence

MIT
