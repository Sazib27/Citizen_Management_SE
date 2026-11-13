# Testing Strategy

## 1. Principles

1. **The suite must never touch a real database.** A test run that can
   wipe production data is not a test suite, it is an incident.
2. **Every security control has a test.** If a mitigation is recorded as
   *Mitigated* in the risk register, there must be an automated test
   that fails without it.
3. **Unit tests for logic, feature tests for behaviour.** Pure decision
   logic (transition maps, validation rules) is tested without the
   framework; HTTP behaviour is tested through the framework.

---

## 2. Environment

`phpunit.xml` configures an isolated in-memory SQLite database:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

`SSLCZ_DEMO_MODE=true` is also set so the suite can never reach the
live SSL Commerz API.

**Prerequisite:** the PHP `pdo_sqlite` extension must be enabled. On
Windows this means uncommenting `extension=pdo_sqlite` and
`extension=sqlite3` in `php.ini`.

---

## 3. Test levels

### Unit tests — `tests/Unit/`

Extend `PHPUnit\Framework\TestCase` (no framework boot). Test pure
logic with no database.

| File | Covers |
|---|---|
| `ComplaintStatusTest` | The complaint lifecycle: transition map, closed-state semantics, labels, badge classes |
| `CertificateNumberTest` | Certificate number format and uniqueness |
| `SSLCommerzValidationRulesTest` | The gateway validation service's configuration and short-circuit behaviour |
| `ExampleTest` | The Laravel scaffold placeholder |

### Feature tests — `tests/Feature/`

Extend `Tests\TestCase` with `RefreshDatabase`. Exercise the HTTP
surface end-to-end: routing, middleware, controllers, models, views.

| File | Covers |
|---|---|
| `AuthenticationAndRoleTest` | Login success and failure, the login throttle, role separation, the email-verification gate, registration, logout |
| `CertificateVerificationTest` | Certificate issuance on approval, number entropy, the public verification endpoint, revocation, re-issue, PDF download |
| `CitizenSSLCommerzPaymentTest` | Starting a checkout, the unconfigured-gateway error path, transport-failure handling, the IPN paths |
| `SSLCommerzGatewayValidationTest` | **Adversarial:** forged amount, wrong currency, non-valid gateway status, replayed callback, missing shared secret, and that the IPN route carries no session middleware |
| `ComplaintWorkflowTest` | Submission, the ownership check, the transition guard, the rejection-reason requirement, reopening, the timeline, rating, citizen-side filtering and scoping |
| `PropertyManagementTest` | Add requests, the verification redirect, admin creation, the delete guards, search by address and owner |
| `PropertyValuationVisibilityTest` | The valuation panel is owner-only |
| `PropertyTransferNotificationTest` | Both parties are notified on a transfer |
| `RentAgreementTest` | Agreement generation, the owner-confirmation gate, the admin queue filter, distribution to both parties, PDF download, lookup by number, party scoping |
| `AdminTaxControllerTest` | Assessment issuance, manual payment recording, admin route protection |
| `CitizenReceiptTest` | Receipt access and cross-citizen denial |
| `AdminRevenueDashboardTest` | The dashboard, CSV exports, admin route protection |
| `FlagOverdueAssessmentsCommandTest` | The overdue command and its notification |
| `ExampleTest` | The Laravel scaffold placeholder |

---

## 4. Coverage matrix

| Objective | Module | Unit | Feature |
|---|---|---|---|
| 1 — Auth + RBAC | Authentication, roles | — | `AuthenticationAndRoleTest` (10) |
| 2 — Verification + certificate | Verification, certificates | `CertificateNumberTest` (2) | `CertificateVerificationTest` (9) |
| 3 — Property management | Properties, requests, search | — | `PropertyManagementTest` (8), `PropertyValuationVisibilityTest` (2), `PropertyTransferNotificationTest` (1) |
| 4 — Rental agreements | Agreements, distribution | — | `RentAgreementTest` (9) |
| 5 — Tax + payment gateway | Assessments, payments, gateway | `SSLCommerzValidationRulesTest` (4) | `CitizenSSLCommerzPaymentTest` (6), `SSLCommerzGatewayValidationTest` (8), `AdminTaxControllerTest` (2), `CitizenReceiptTest` (2), `FlagOverdueAssessmentsCommandTest` (1) |
| 6 — Complaints | Complaint workflow | `ComplaintStatusTest` (6) | `ComplaintWorkflowTest` (12) |
| 8 — Testing | The suite itself | `ExampleTest` (1) | `ExampleTest` (1) |

**Current total: 89 tests, 284 assertions.**

### Known gaps

| Gap | Why |
|---|---|
| No test for the `StripeCheckoutService` | The Stripe path is unrouted dead code pending removal |
| No test for `RevenueDashboardService` in isolation | Covered end-to-end through `AdminRevenueDashboardTest` |
| No test for `TaxAssessmentService` in isolation | Covered end-to-end through `AdminTaxControllerTest` |
| No negative test for the property transfer to an unregistered user | Deferred with R-PROP-05 |
| No test that complaint attachments are private | Deferred with R-COMP-07 — the control does not exist yet |

---

## 5. Running the suite

```bash
# Everything
php artisan test

# One suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# One class or method
php artisan test --filter=ComplaintWorkflowTest
php artisan test --filter=test_login_is_throttled_after_repeated_failures
```

---

## 6. Writing a test

### Feature test

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyTest extends TestCase
{
    use RefreshDatabase;

    public function test_something(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $this->actingAs($citizen)
            ->get(route('citizen.something'))
            ->assertOk();
    }
}
```

### Unit test

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MyLogicTest extends TestCase
{
    public function test_the_rule_holds(): void
    {
        $this->assertTrue(MyLogic::decide('input'));
    }
}
```

### Conventions

- Use `RefreshDatabase` on every feature test.
- Use factories rather than raw model creation wherever one exists.
- Assert on the **observable outcome** (a redirect target, a database
  row, rendered text), not on implementation details.
- For an authorisation test, assert the **project's actual convention**:
  the role middleware *redirects* rather than returning 403. Check
  `AdminMiddleware` before writing `assertForbidden()`.
- Use `Notification::fake()` to assert that a notification was sent.
- Use `Mockery` to mock the gateway service; call `Mockery::close()` in
  `tearDown()`.

---

## 7. Continuous integration (not yet wired)

The repository has no CI pipeline (R-TEST-04). The intended workflow:

```yaml
name: tests

on:
  push:
    branches: [master, main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, pdo_sqlite, curl, dom
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Copy environment
        run: cp .env.example .env

      - name: Run migrations
        run: php artisan migrate --force

      - name: Run tests
        run: php artisan test

      - name: Check code style
        run: vendor/bin/pint --test
```

The workflow assumes `pdo_sqlite` is present — which is why
`phpunit.xml` pins SQLite rather than relying on the CI image's default
database.

---

## 8. Quality gates

A change is not mergeable unless:

1. `php artisan test` passes with no failures.
2. `vendor/bin/pint --test` reports no style violations.
3. No new High or Critical risk is introduced without a register entry.
4. New behaviour has a test at the appropriate level.

See `docs/methodology/hybrid-agile-spiral.md` §5 for the full
Definition of Done.
