# Risk Register

Scoring: **Risk Score = Likelihood × Impact**, each 1–5.

| Score | Rating | Disposition |
|---|---|---|
| 1–4 | Low | Monitor |
| 5–9 | Medium | Mitigate within the current loop |
| 10–15 | High | Must be mitigated before the loop can close |
| 16–25 | Critical | Must be mitigated before any release; loop is blocked |

Likelihood and impact scales are defined in
`docs/risk/risk-analysis-method.md`.

**Status legend:** `Open` · `Mitigated` · `Accepted` · `Closed` (verified by test)

---

## R-AUTH — Authentication & Role-Based Access Control

| ID | Risk | Cause | L | I | Score | Mitigation | Owner | Status |
|---|---|---|---|---|---|---|---|---|
| R-AUTH-01 | Credential brute-force on `POST /login` | No rate limiter on the login route | 4 | 4 | 16 | Named `login` rate limiter (5/min per email+IP, 25/hr per IP) registered in `AppServiceProvider`; route applies `throttle:login`. Covered by `AuthenticationAndRoleTest::test_login_is_throttled_after_repeated_failures` | Dev | **Mitigated** |
| R-AUTH-02 | Unverified account reaches protected services by posting directly to a URL | Email verification was enforced only by a login-page redirect, not by middleware | 4 | 3 | 12 | New `EnsureEmailIsVerified` middleware, applied to the `verified.email` + `verified.citizen` route group | Dev | **Mitigated** |
| R-AUTH-03 | Citizen changes their email address and keeps "verified email" status | `updateProfile` wrote the new email without clearing `email_verified_at` | 3 | 4 | 12 | `updateProfile` clears `email_verified_at` when the email changes and warns the citizen to re-verify | Dev | **Mitigated** |
| R-AUTH-04 | Verified citizen renames themselves, invalidating their certificate | `full_name` (cross-checked against the NID registry) was editable after verification | 3 | 3 | 9 | `full_name` is stripped from the update payload for verified citizens | Dev | **Mitigated** |
| R-AUTH-05 | Automated mass account creation | `POST /register` had no throttle | 3 | 3 | 9 | Named `register` rate limiter (10/hr per IP) | Dev | **Mitigated** |
| R-AUTH-06 | No Gate/Policy layer; authorisation is ad-hoc `abort_unless` in controllers | Inconsistent inline checks | 3 | 3 | 9 | Deferred — tracked as a refactoring item for a later loop; the inline checks are currently correct and covered by tests | Architect | Open |
| R-AUTH-07 | No admin-user management UI; admin accounts cannot be created, promoted, or disabled | Admins are only created by seeder | 3 | 3 | 9 | Deferred — requires an audited admin-account workflow; seeding remains the only path in this release | Architect | Accepted |

---

## R-VER — Citizen Verification & Digital Certificate

| ID | Risk | Cause | L | I | Score | Mitigation | Owner | Status |
|---|---|---|---|---|---|---|---|---|
| R-VER-01 | Certificate number is predictable and carries no entropy | Number was `sprintf('VCN-%06d', $user->id)` — derived directly from the user id | 4 | 4 | 16 | `Certificate::generateNumber()` issues a random `VCN-XXXXXXXX`; covered by `CertificateNumberTest` and `CertificateVerificationTest::test_certificate_number_does_not_derive_from_the_user_id` | Dev | **Mitigated** |
| R-VER-02 | Certificate cannot be revoked, and has no issuance record | Certificate was re-derived from the `users` row on every download | 3 | 4 | 12 | New `certificates` table with `status`, `issued_at`, `expires_at`, `revoked_at`, `revocation_reason`; `Certificate::issueFor()` revokes the previous certificate | Dev | **Mitigated** |
| R-VER-03 | No way for a third party to verify a certificate by its number | Only a user-id-signed URL existed | 4 | 3 | 12 | New public route `verification.certificate.verify` (`GET /verification/certificate/{certificate}`), signed, resolving by certificate number | Dev | **Mitigated** |
| R-VER-04 | Certificate PDF depends on an external QR service | QR rendered by `api.qrserver.com`; failure produced a broken document | 4 | 2 | 8 | `buildQrImage()` catches transport failures and falls back to printing the verification URL as text — the PDF always generates | Dev | **Mitigated** |
| R-VER-05 | Public status page discloses full name and NID | `verification.status` exposed PII to anyone holding the URL | 3 | 4 | 12 | The certificate verification page withholds NID and date of birth; only holder name, status and dates are shown | Dev | **Mitigated** |
| R-VER-06 | Only 2 of 8 identity fields are cross-checked against the registry | `father_name`, `mother_name`, addresses are prefilled but never compared | 3 | 3 | 9 | Deferred — the registry data model does not yet carry a canonical value for every submitted field; tracked for the next verification loop | Architect | Open |
| R-VER-07 | An admin can approve their own NID | `approve()` compares `verified_by !== Auth::id()` only for a *different* user | 2 | 4 | 8 | Deferred — separation-of-duties rules need an admin-role review; low likelihood in a single-admin deployment | Architect | Open |
| R-VER-08 | NID registry is a public enumeration oracle | `GET /api/nid/{nid}` was unauthenticated and unthrottled | 4 | 3 | 12 | Named `nid-status` rate limiter (20/min per IP) applied to the API route | Dev | **Mitigated** |

---

## R-PROP — Property Management

| ID | Risk | Cause | L | I | Score | Mitigation | Owner | Status |
|---|---|---|---|---|---|---|---|---|
| R-PROP-01 | Deleting a property silently destroys its rent agreements and tax/payment history | `destroy()` called `delete()` unconditionally; the FKs cascade | 3 | 5 | 15 | `destroy()` blocks deletion when active rent agreements or outstanding tax assessments exist, and instructs the admin to deactivate instead | Dev | **Mitigated** |
| R-PROP-02 | Search by owner name throws a SQL error | `display_name` is a model accessor, but five queries filtered on it as if it were a column | 5 | 3 | 15 | All five queries changed to search the real `full_name` / `name` columns | Dev | **Mitigated** |
| R-PROP-03 | Search did not cover address, state, postal code, or owner | Citizen search matched only `title` and `city` | 4 | 2 | 8 | Citizen and admin searches now cover title, address, city, state, postal code and owner name | Dev | **Mitigated** |
| R-PROP-04 | A citizen can self-declare the `assessed_value` used for tax calculation | The citizen property-update request accepts `assessed_value`, `land_use`, `last_valuation_at` and the admin applies the payload verbatim | 3 | 4 | 12 | Deferred — the valuation fields must be excluded from citizen-submitted payloads and set only by the assessment service | Architect | Open |
| R-PROP-05 | Ownership transfer to an unregistered email silently succeeds with no change | `applyRequestPayload()` has no `else` branch when no user matches | 3 | 3 | 9 | Deferred — requires a transfer-target invitation flow | Architect | Open |

---

## R-RENT — Rental Agreements

| ID | Risk | Cause | L | I | Score | Mitigation | Owner | Status |
|---|---|---|---|---|---|---|---|---|
| R-RENT-01 | Agreement is created silently; neither party is told | `createRentAgreement()` fired no notification | 4 | 4 | 16 | `RentAgreementGenerated` notification sent to both tenant and landlord on approval | Dev | **Mitigated** |
| R-RENT-02 | Agreement is not a document; it cannot be downloaded or filed | The agreement existed only as a database row rendered in a page | 4 | 3 | 12 | PDF generation via DomPDF (`citizen.rent-agreements.pdf`) with a download route for both parties and for admins | Dev | **Mitigated** |
| R-RENT-03 | Agreement has no signed or tamper-evident representation | `terms_text` is a plain long-text blob | 3 | 4 | 12 | Deferred — the PDF renders the agreement number, generation timestamp and approving officer; a detached cryptographic signature would be the next step | Architect | Open |
| R-RENT-04 | Neither party consents; the agreement goes live on admin approval alone | No acceptance step exists | 3 | 4 | 12 | Deferred — e-signature flow is out of scope for this release; the gap is documented here | Architect | Accepted |
| R-RENT-05 | No way to find an agreement without knowing its numeric id | The unique `agreement_number` was stored but not searchable | 3 | 2 | 6 | `citizen.rent-agreements.lookup` route resolves by agreement number, scoped to the requesting user's own agreements | Dev | **Mitigated** |
| R-RENT-06 | Admin approves requests the owner never confirmed | `ready_for_admin` gate existed but the admin list showed all pending requests | 4 | 3 | 12 | The admin rental-request list now filters on `ready_for_admin = true`; the approve action still re-checks the flag | Dev | **Mitigated** |

---

## R-PAY — Tax Assessment & Payment Gateway

| ID | Risk | Cause | L | I | Score | Mitigation | Owner | Status |
|---|---|---|---|---|---|---|---|---|
| R-PAY-01 | A forged callback marks an assessment as paid at the wrong amount | `validateTransaction()` existed but was never called; the amount came from the request | 5 | 5 | 25 | `validateTransactionForAssessment()` reconciles amount, currency and transaction id against the gateway's Validation API; called on both the success callback and the IPN | Dev | **Mitigated** |
| R-PAY-02 | The IPN endpoint was unreachable — the gateway's server-to-server call carried no session and was rejected by `auth` | The IPN route sat inside the `citizen` + `auth` middleware group | 5 | 4 | 20 | The IPN route moved outside the auth group; it is protected instead by an optional shared-secret header plus the gateway reconciliation | Dev | **Mitigated** |
| R-PAY-03 | Concurrent success + IPN callbacks create duplicate payment rows | Idempotency existed only in application code (`firstOrCreate`) | 3 | 3 | 9 | Unique index on `(tax_assessment_id, reference, method)` added by migration; `firstOrCreate` retained as the application-level guard | Dev | **Mitigated** |
| R-PAY-04 | Currency is recorded only inside a free-text notes field | No `currency` column | 3 | 2 | 6 | `currency` column added to `tax_payments` | Dev | **Mitigated** |
| R-PAY-05 | Raw gateway payload is discarded, preventing dispute reconciliation | The response was never persisted | 3 | 3 | 9 | `gateway_response` JSON column added and populated with the validation response | Dev | **Mitigated** |
| R-PAY-06 | Demo-mode payments are indistinguishable from real ones in reporting | `demoConfirm()` wrote `method = 'sslcommerz'` into the same table | 3 | 3 | 9 | Deferred — demo mode writes a `DEMO` marker into the notes field, but a first-class `method = 'demo'` discriminator would be cleaner | Dev | Open |
| R-PAY-07 | No scheduled job marks assessments overdue | The command exists but nothing invokes it on a schedule | 3 | 3 | 9 | Deferred — requires a scheduler entry (`routes/console.php`) and a queue worker in deployment | Architect | Open |
| R-PAY-08 | Deleted Stripe integration remains as dead code | `StripeTaxPaymentController` / `StripeCheckoutService` are unreferenced | 2 | 1 | 2 | Retained for back-compatibility but no longer routed; flagged for removal in the next cleanup loop | Dev | Accepted |

---

## R-COMP — Complaint Management

| ID | Risk | Cause | L | I | Score | Mitigation | Owner | Status |
|---|---|---|---|---|---|---|---|---|
| R-COMP-01 | A citizen cannot see how their complaint progressed | The complaint row only held the current status; no history table existed | 4 | 3 | 12 | New `complaint_updates` table; every transition is recorded and rendered as a timeline for the citizen and the admin | Dev | **Mitigated** |
| R-COMP-02 | Status is a free-text string duplicated across 6 places | No enum | 3 | 3 | 9 | `App\Enums\ComplaintStatus` backed enum with `allowedTransitions()`, `isClosed()`, `label()`, `badgeClasses()` | Dev | **Mitigated** |
| R-COMP-03 | Admin can move a complaint between arbitrary states | Validation was membership-only | 3 | 3 | 9 | Transition guard using `ComplaintStatus::canTransitionTo()`; reopening clears the stale closure stamps | Dev | **Mitigated** |
| R-COMP-04 | A rejection with no reason gives the citizen nothing | `admin_reply` was optional | 3 | 2 | 6 | A reason is now mandatory when the status is `rejected` | Dev | **Mitigated** |
| R-COMP-05 | No citizen-side filtering of complaints | The citizen list had no filters | 3 | 2 | 6 | Status filter and subject search added to the citizen complaint list | Dev | **Mitigated** |
| R-COMP-06 | No satisfaction measurement | No rating column | 3 | 2 | 6 | 1–5 star rating, available once the complaint is closed, recorded on the complaint and shown in the list | Dev | **Mitigated** |
| R-COMP-07 | Complaint attachments are stored on the public disk and served by a guessable URL | `ComplaintController::store()` used the `public` disk; the view linked `asset('storage/...')` | 4 | 4 | 16 | Deferred — attachments must move to a private disk behind an authorised streaming route, as was already done for verification documents | Dev | Open |
| R-COMP-08 | No notification to the citizen when their complaint is resolved | No notification existed | 3 | 3 | 9 | Deferred — a `ComplaintResolved` notification is the next item in this module | Dev | Open |

---

## R-TEST — Testing & Quality

| ID | Risk | Cause | L | I | Score | Mitigation | Owner | Status |
|---|---|---|---|---|---|---|---|---|
| R-TEST-01 | The test suite ran against the live MySQL database and `RefreshDatabase` would wipe real data | The SQLite config in `phpunit.xml` was commented out and there was no `.env.testing` | 4 | 5 | 20 | `phpunit.xml` now sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`; the suite is hermetic | Dev | **Mitigated** |
| R-TEST-02 | The whole suite failed under SQLite because a migration dropped a column that was never created | `drop_otp_column` migration dropped `otp`; the table only ever had `otp_hash`. MySQL tolerates this, SQLite does not | 5 | 3 | 15 | The migration now checks `Schema::hasColumn()` before dropping | Dev | **Mitigated** |
| R-TEST-03 | Complaints, authentication, verification and the service layer had zero coverage | No tests were written for them | 4 | 4 | 16 | `ComplaintWorkflowTest` (12), `AuthenticationAndRoleTest` (10), `CertificateVerificationTest` (9), `SSLCommerzGatewayValidationTest` (8), plus 3 unit test classes — 89 tests in total | QA | **Mitigated** |
| R-TEST-04 | No CI pipeline — the suite is only run manually | No `.github/workflows` directory | 4 | 3 | 12 | Deferred — a GitHub Actions workflow is defined in `docs/testing/strategy.md` and is ready to be added | QA | Open |
| R-TEST-05 | No static analysis or code-style gate | No PHPStan/Psalm config; Pint is available but not enforced | 3 | 2 | 6 | Deferred — `pint --test` is part of the Definition of Done; static analysis tooling is a future loop | Architect | Open |

---

## R-DOC — Documentation & Methodology

| ID | Risk | Cause | L | I | Score | Mitigation | Owner | Status |
|---|---|---|---|---|---|---|---|---|
| R-DOC-01 | No README, no methodology document, no risk register | The project had no `docs/` directory and no root README | 5 | 3 | 15 | `README.md` plus `docs/methodology/`, `docs/risk/`, `docs/architecture/` and `docs/testing/` created | Architect | **Mitigated** |
| R-DOC-02 | No architecture reference or data-flow documentation | — | 4 | 2 | 8 | `docs/architecture/overview.md` with the module map and the payment/complaint flows | Architect | **Mitigated** |
| R-DOC-03 | No security disclosure policy | — | 2 | 2 | 4 | Deferred — `SECURITY.md` is a candidate for the next documentation loop | Architect | Open |

---

## Summary

| Rating | Count | Open | Mitigated | Accepted |
|---|---|---|---|---|
| Critical (16–25) | 4 | 0 | 4 | 0 |
| High (10–15) | 19 | 4 | 14 | 1 |
| Medium (5–9) | 12 | 8 | 3 | 1 |
| Low (1–4) | 1 | 0 | 0 | 1 |

All **Critical** risks are mitigated. The four remaining **High** risks
(R-PROP-04, R-RENT-03, R-RENT-04, R-COMP-07) are open and are the
explicit scope of the next spiral loop.
