# Security Controls

The control catalogue, and the traceability matrix from each control to
the risks it addresses.

---

## 1. Control catalogue

### C-AUTH — Authentication

| ID | Control | Implementation | Verification |
|---|---|---|---|
| C-AUTH-01 | Password hashing | `User::$casts` maps `password => 'hashed'`; bcrypt via the `HASH_DRIVER` env | Indirect — passwords are never stored in plain text |
| C-AUTH-02 | Login rate limiting | `RateLimiter::for('login')` — 5/min per email+IP, 25/hr per IP. `AppServiceProvider::registerRateLimiters()` | `AuthenticationAndRoleTest::test_login_is_throttled_after_repeated_failures` |
| C-AUTH-03 | Registration rate limiting | `RateLimiter::for('register')` — 10/hr per IP | Route inspection |
| C-AUTH-04 | Session regeneration on login | `AuthController::login()` calls `$request->session()->regenerate()` | Code inspection |
| C-AUTH-05 | Session invalidation on logout | `invalidate()` + `regenerateToken()` | `AuthenticationAndRoleTest::test_logout_ends_the_session` |
| C-AUTH-06 | CSRF protection | Laravel's `web` middleware group; every form carries `@csrf` | Framework default |
| C-AUTH-07 | Role-based access control | `AdminMiddleware` / `CitizenMiddleware` registered as `admin` / `citizen` aliases in `bootstrap/app.php` | `AuthenticationAndRoleTest::test_citizen_cannot_reach_admin_routes`, `test_admin_cannot_reach_citizen_only_routes` |
| C-AUTH-08 | Citizen verification gate | `EnsureCitizenVerified` (`verified.citizen`) on the service route group | `AuthenticationAndRoleTest::test_unverified_citizen_is_blocked_from_verified_only_routes` |
| C-AUTH-09 | Email verification gate | `EnsureEmailIsVerified` (`verified.email`) on the service route group | `AuthenticationAndRoleTest::test_citizen_without_a_verified_email_is_sent_to_the_verification_notice` |
| C-AUTH-10 | Email change invalidates verification | `updateProfile()` clears `email_verified_at` when the email changes | Code inspection |
| C-AUTH-11 | Verified identity fields are immutable | `full_name` stripped from the profile update payload once verified | Code inspection |
| C-AUTH-12 | OTP attempt cap and expiry | `AuthController::verifyOtp()` — 10-minute expiry, max 5 attempts, hashed OTP comparison | Code inspection |
| C-AUTH-13 | Route-level throttling on verification and reset | `throttle:6,1` and `throttle:5,1` on the verification routes; `throttle:6,1` on password reset | Route inspection |
| C-AUTH-14 | HTTPS enforced in production | `URL::forceScheme('https')` in `AppServiceProvider::boot()` | Code inspection |
| C-AUTH-15 | NID registry probe throttled | `RateLimiter::for('nid-status')` — 20/min per IP on `GET /api/nid/{nid}` | Route inspection |

### C-VER — Verification and certificates

| ID | Control | Implementation | Verification |
|---|---|---|---|
| C-VER-01 | Registry cross-check | `VerificationController::store()` compares `full_name` and `date_of_birth` against `fake_nids` | Code inspection |
| C-VER-02 | Transactional approval with row locks | `approve()` runs in a transaction with `lockForUpdate()` on both the user and the registry row | Code inspection |
| C-VER-03 | One NID, one citizen | `approve()` refuses when the NID is already verified by a different user | Code inspection |
| C-VER-04 | Blocked-NID rejection | `approve()` refuses when the registry row is blocked | Code inspection |
| C-VER-05 | Unguessable certificate number | `Certificate::generateNumber()` → `VCN-XXXXXXXX` | `CertificateNumberTest`, `CertificateVerificationTest::test_certificate_number_does_not_derive_from_the_user_id` |
| C-VER-06 | Certificate lifecycle | `certificates` table with `status`, `issued_at`, `expires_at`, `revoked_at`, `revocation_reason` | `CertificateVerificationTest::test_issuing_a_new_certificate_revokes_the_previous_one` |
| C-VER-07 | Public verification endpoint | `GET /verification/certificate/{certificate}`, signed, resolves by number | `CertificateVerificationTest::test_public_endpoint_confirms_a_valid_certificate` |
| C-VER-08 | Signature required on the verification URL | `signed` middleware; unsigned requests get 403 | `CertificateVerificationTest::test_public_endpoint_rejects_an_unsigned_request` |
| C-VER-09 | PII minimisation on the public page | The verification view withholds NID and date of birth | Code inspection |
| C-VER-10 | Private document storage and streaming | `document()` allow-lists three fields and streams from the private path; `abort(404)` otherwise | Code inspection |
| C-VER-11 | QR generation is failure-tolerant | `buildQrImage()` catches transport errors and falls back to text | `CertificateVerificationTest::test_verified_citizen_can_download_the_certificate_pdf` |

### C-PROP — Property management

| ID | Control | Implementation | Verification |
|---|---|---|---|
| C-PROP-01 | Ownership enforcement | `PropertyController::authorizeOwner()` → `abort_unless($property->owner_id === Auth::id(), 403)` | `PropertyManagementTest` |
| C-PROP-02 | Self-rental blocked | `submitRentalRequest()` → `abort_if($property->owner_id === Auth::id(), 403)` | Code inspection |
| C-PROP-03 | Delete guards | `destroy()` refuses when active agreements or outstanding assessments exist | `PropertyManagementTest::test_admin_cannot_delete_a_property_with_an_active_rent_agreement`, `test_admin_cannot_delete_a_property_with_an_outstanding_tax_assessment` |
| C-PROP-04 | Valuation visibility | The valuation panel renders only for the owner | `PropertyValuationVisibilityTest` |
| C-PROP-05 | Request-based mutations for citizens | Citizens submit `PropertyRequest` rows; only an admin applies them | `PropertyManagementTest::test_citizen_can_submit_property_add_request` |
| C-PROP-06 | Transfer notification | `notifyTransferCompleted()` notifies both parties | `PropertyTransferNotificationTest` |
| C-PROP-07 | Search across the real columns | Searches use `full_name` / `name`, never the `display_name` accessor | `PropertyManagementTest::test_citizen_search_matches_the_owner_name` |

### C-RENT — Rental agreements

| ID | Control | Implementation | Verification |
|---|---|---|---|
| C-RENT-01 | Owner confirmation gate | `ready_for_admin` set only by the property owner; the admin queue and the approve action both require it | `RentAgreementTest::test_admin_cannot_approve_before_owner_confirmation`, `test_pending_rental_requests_are_hidden_until_owner_confirms` |
| C-RENT-02 | Party scoping | `show()`, `download()` and `lookup()` all check the requester is tenant or landlord | `RentAgreementTest::test_a_stranger_cannot_download_the_agreement_pdf`, `test_lookup_by_number_refuses_an_agreement_belonging_to_someone_else` |
| C-RENT-03 | Unique agreement number | `agreement_number` has a unique index | Migration |
| C-RENT-04 | Distribution on creation | `RentAgreementGenerated` notification to both parties | `RentAgreementTest::test_generating_an_agreement_notifies_both_parties` |
| C-RENT-05 | Downloadable document | DomPDF rendering with a download route for citizens and admins | `RentAgreementTest::test_tenant_can_download_the_agreement_pdf` |

### C-PAY — Payments

| ID | Control | Implementation | Verification |
|---|---|---|---|
| C-PAY-01 | Server-side gateway reconciliation | `validateTransactionForAssessment()` checks status, currency, amount and transaction id against the gateway's Validation API | `SSLCommerzGatewayValidationTest` (5 adversarial cases) |
| C-PAY-02 | Reconciliation on every callback path | Called on the success callback *and* the IPN | `SSLCommerzGatewayValidationTest::test_ipn_records_the_payment_when_the_gateway_confirms_it` |
| C-PAY-03 | IPN reachable without a session | The IPN route sits outside the `auth` / `citizen` groups | `SSLCommerzGatewayValidationTest::test_ipn_route_does_not_require_a_session` |
| C-PAY-04 | Shared-secret header on the IPN | Optional `X-SSLCommerz-Secret` check using `hash_equals` | `SSLCommerzGatewayValidationTest::test_ipn_requires_the_shared_secret_when_one_is_configured` |
| C-PAY-05 | DB-level idempotency | Unique index on `(tax_assessment_id, reference, method)` | `SSLCommerzGatewayValidationTest::test_ipn_is_idempotent_when_the_gateway_calls_back_twice` |
| C-PAY-06 | Amount never taken from the request | `confirmPayment()` uses the validated gateway amount | Code inspection |
| C-PAY-07 | Currency recorded explicitly | `tax_payments.currency` column | `SSLCommerzGatewayValidationTest::test_ipn_records_the_payment_when_the_gateway_confirms_it` |
| C-PAY-08 | Raw gateway payload retained | `tax_payments.gateway_response` JSON column | Code inspection |
| C-PAY-09 | Assessment ownership checks | `authorizeAssessment()` / `authorizePayment()` | `CitizenReceiptTest::test_citizen_cannot_access_someone_elses_receipt` |
| C-PAY-10 | Demo mode is env-gated and test-safe | `SSLCZ_DEMO_MODE`; forced on in `phpunit.xml` so the suite never reaches the live API | `SSLCommerzValidationRulesTest` |

### C-COMP — Complaints

| ID | Control | Implementation | Verification |
|---|---|---|---|
| C-COMP-01 | Typed status lifecycle | `App\Enums\ComplaintStatus` with `allowedTransitions()` | `ComplaintStatusTest` |
| C-COMP-02 | Transition guard | `AdminComplaintController::update()` refuses invalid transitions | `ComplaintWorkflowTest::test_resolved_complaint_can_be_reopened` |
| C-COMP-03 | Rejection requires a reason | Validation error when `rejected` without an `admin_reply` | `ComplaintWorkflowTest::test_admin_can_reject_a_complaint_but_must_give_a_reason` |
| C-COMP-04 | Status history | `complaint_updates` table; every transition logged with author and note | `ComplaintWorkflowTest::test_admin_can_progress_a_complaint_and_the_timeline_records_it` |
| C-COMP-05 | Reopening clears stale stamps | `resolved_at`, `resolved_by`, `closed_at` reset to null | `ComplaintWorkflowTest::test_resolved_complaint_can_be_reopened` |
| C-COMP-06 | Ownership checks | `show()` and `rate()` check `user_id`; `store()` checks property ownership | `ComplaintWorkflowTest::test_citizen_cannot_view_someone_elses_complaint`, `test_citizen_cannot_submit_a_complaint_for_a_property_they_do_not_own` |
| C-COMP-07 | Attachment type and size validation | `file|max:4096` on upload | Code inspection |
| C-COMP-08 | Citizen-side scoping | The list filters on `user_id` | `ComplaintWorkflowTest::test_citizen_only_sees_their_own_complaints` |

### C-TEST — Testing

| ID | Control | Implementation | Verification |
|---|---|---|---|
| C-TEST-01 | Hermetic test database | `phpunit.xml` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` | The suite passes without a MySQL server running |
| C-TEST-02 | Portable migrations | The `drop_otp_column` migration checks `Schema::hasColumn()` first | The suite runs under SQLite |
| C-TEST-03 | Gateway never contacted from tests | `SSLCZ_DEMO_MODE=true` in `phpunit.xml` | `SSLCommerzValidationRulesTest` |
| C-TEST-04 | Coverage across all modules | 5 new test classes | `docs/testing/strategy.md` |
| C-TEST-05 | No live data in tests | `RefreshDatabase` against in-memory SQLite only | Manual inspection |

---

## 2. Control-to-risk traceability matrix

| Risk | Score | Controls that mitigate it | Residual |
|---|---|---|---|
| R-AUTH-01 | 16 | C-AUTH-02 | Low |
| R-AUTH-02 | 12 | C-AUTH-09 | Low |
| R-AUTH-03 | 12 | C-AUTH-10 | Low |
| R-AUTH-04 | 9 | C-AUTH-11, C-VER-01 | Medium |
| R-AUTH-05 | 9 | C-AUTH-03 | Low |
| R-AUTH-06 | 9 | — | Medium |
| R-AUTH-07 | 9 | — | Accepted |
| R-VER-01 | 16 | C-VER-05 | Low |
| R-VER-02 | 12 | C-VER-06 | Low |
| R-VER-03 | 12 | C-VER-07 | Low |
| R-VER-04 | 8 | C-VER-11 | Low |
| R-VER-05 | 12 | C-VER-09 | Low |
| R-VER-06 | 9 | C-VER-01 (partial) | Medium |
| R-VER-07 | 8 | — | Medium |
| R-VER-08 | 12 | C-AUTH-15 | Low |
| R-PROP-01 | 15 | C-PROP-03 | Low |
| R-PROP-02 | 15 | C-PROP-07 | Low |
| R-PROP-03 | 8 | C-PROP-07 | Low |
| R-PROP-04 | 12 | — | Medium |
| R-PROP-05 | 9 | — | Medium |
| R-RENT-01 | 16 | C-RENT-04 | Low |
| R-RENT-02 | 12 | C-RENT-05 | Low |
| R-RENT-03 | 12 | — | Medium |
| R-RENT-04 | 12 | — | Accepted |
| R-RENT-05 | 6 | C-RENT-02 | Low |
| R-RENT-06 | 12 | C-RENT-01 | Low |
| R-PAY-01 | 25 | C-PAY-01, C-PAY-02, C-PAY-06 | Low |
| R-PAY-02 | 20 | C-PAY-03, C-PAY-04 | Low |
| R-PAY-03 | 9 | C-PAY-05 | Low |
| R-PAY-04 | 6 | C-PAY-07 | Low |
| R-PAY-05 | 9 | C-PAY-08 | Low |
| R-PAY-06 | 9 | — | Medium |
| R-PAY-07 | 9 | — | Medium |
| R-PAY-08 | 2 | — | Accepted |
| R-COMP-01 | 12 | C-COMP-04 | Low |
| R-COMP-02 | 9 | C-COMP-01 | Low |
| R-COMP-03 | 9 | C-COMP-02 | Low |
| R-COMP-04 | 6 | C-COMP-03 | Low |
| R-COMP-05 | 6 | C-COMP-08 | Low |
| R-COMP-06 | 6 | C-COMP-08 | Low |
| R-COMP-07 | 16 | — | Medium |
| R-COMP-08 | 9 | — | Medium |
| R-TEST-01 | 20 | C-TEST-01 | Low |
| R-TEST-02 | 15 | C-TEST-02 | Low |
| R-TEST-03 | 16 | C-TEST-04 | Low |
| R-TEST-04 | 12 | — | Medium |
| R-TEST-05 | 6 | — | Medium |
| R-DOC-01 | 15 | — (documentation artefact) | Closed |
| R-DOC-02 | 8 | — (documentation artefact) | Closed |
| R-DOC-03 | 4 | — | Low |

**Every Critical risk has at least two controls.** The largest remaining
exposure is R-COMP-07 (complaint attachments on the public disk), which
is a High with no control — it is first in the queue for Loop 8.
