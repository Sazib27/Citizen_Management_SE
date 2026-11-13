# Threat Model — STRIDE per Feature

STRIDE applied to each feature area, with the trust boundaries and the
controls that address each threat. The risk IDs reference
`docs/risk/risk-register.md`.

---

## 1. Trust boundaries

```
┌──────────────────────────────────────────────────────────────┐
│  PUBLIC INTERNET                                            │
│  (unauthenticated browsers, SSL Commerz servers)            │
└───────────────┬──────────────────────────────────────────────┘
                │  boundary A — session establishment
                │  controls: rate limiting, CSRF, password hashing,
                │            session regeneration, email verification
┌───────────────▼──────────────────────────────────────────────┐
│  AUTHENTICATED ZONE                                         │
│  ┌──────────────┐        ┌──────────────────────────────┐    │
│  │ CITIZEN      │        │ ADMIN                       │    │
│  │ role=citizen │        │ role=admin                  │    │
│  └──────┬───────┘        └──────────────┬───────────────┘    │
│         │ boundary B — role check         │                  │
│         │ boundary C — resource ownership │                 │
│         ▼                                 ▼                  │
│  ┌──────────────────────────────────────────────────────┐    │
│  │  DOMAIN: properties, agreements, taxes, complaints,  │    │
│  │  certificates, payments                             │    │
│  └───────────────┬──────────────────────────────────────┘    │
└──────────────────┼───────────────────────────────────────────┘
                   │  boundary D — payment gateway
                   │  controls: server-side gateway validation,
                   │            shared secret, DB idempotency
┌──────────────────▼───────────────────────────────────────────┐
│  SSL COMMERZ (external, semi-trusted)                       │
│  We trust its Validation API response; we do NOT trust the  │
│  amount/currency/status arriving on its callbacks.          │
└──────────────────────────────────────────────────────────────┘

                   ┌───────────────────────────────────────────┐
                   │  STORAGE (trusted)                          │
                   │  private disk: NID images, documents        │
                   │  public disk: complaint attachments (gap)   │
                   │  database: citizens, agreements, payments   │
                   └───────────────────────────────────────────┘
```

---

## 2. Authentication and session management

| Threat | STRIDE | Scenario | Control | Risk |
|---|---|---|---|---|
| Credential stuffing | Spoofing | Attacker replays stolen credentials across many accounts | `login` rate limiter: 5/min per email+IP, 25/hr per IP | R-AUTH-01 |
| Password reuse against other services | Spoofing | Credentials leaked elsewhere are tried here | No breach-password check — **accepted gap** | — |
| Account enumeration via login errors | Information disclosure | Response differs for unknown email vs wrong password | Uniform error message on both paths | — |
| Session fixation | Spoofing | Attacker plants a session id before login | `session()->regenerate()` on login; `invalidate()` + `regenerateToken()` on logout | — |
| CSRF on state-changing routes | Tampering | Forged POST from a third-party page | Laravel's `web` middleware group applies `VerifyCsrfToken`; all forms carry `@csrf` | — |
| Privilege escalation by role tampering | Elevation of privilege | Citizen sets `role=admin` on their own profile | `role` is not in the profile update payload; `updateProfile` only accepts name, email, phone, addresses | R-AUTH-04 |
| Use of an unverified account | Elevation of privilege | Unverified citizen posts directly to a protected route | `EnsureEmailIsVerified` middleware on the service route group | R-AUTH-02 |
| Email takeover after a profile change | Spoofing | Citizen changes email to one they do not control, keeping "verified" status | `email_verified_at` cleared on email change | R-AUTH-03 |
| Automated account creation | Denial of service / Tampering | Bot mass-registers accounts | `register` rate limiter: 10/hr per IP | R-AUTH-05 |

---

## 3. Citizen verification and certificate

| Threat | STRIDE | Scenario | Control | Risk |
|---|---|---|---|---|
| Forged certificate number | Spoofing | Attacker enumerates `VCN-000001`, `VCN-000002`… to claim certificates | Random 8-character number (`VCN-XXXXXXXX`); a dedicated test asserts it is not derived from the user id | R-VER-01 |
| Certificate cannot be withdrawn | Repudiation | A revoked identity keeps a certificate that still looks valid | `status`, `revoked_at`, `revocation_reason` on the `certificates` table; the public page shows "not valid" | R-VER-02 |
| Third party cannot verify authenticity | Repudiation | Employer cannot confirm a certificate is genuine | Public signed route `verification.certificate.verify` | R-VER-03 |
| Identity mismatch between certificate and registry | Tampering | Citizen edits `full_name` after verification, so the certificate shows a different name | `full_name` is not editable once verified | R-AUTH-04 |
| NID registry enumeration | Information disclosure | Attacker probes `GET /api/nid/{nid}` to enumerate valid NIDs | `nid-status` rate limiter, 20/min per IP | R-VER-08 |
| PII disclosed to anyone with a URL | Information disclosure | The public status page reveals full name and NID | The certificate verification page withholds NID and date of birth | R-VER-05 |
| Document upload used for malware storage | Tampering | Malicious file uploaded as a NID scan | Validation restricted to image mime types and a 4 MB cap | — |
| Admin approves their own NID | Elevation of privilege | Compromised admin self-approves | Not yet prevented — **accepted gap** | R-VER-07 |
| Cross-check covers only 2 of 8 identity fields | Tampering | Applicant alters father's name, address, etc. | `full_name` and `date_of_birth` are compared against the registry; the remaining fields are **not** | R-VER-06 |

---

## 4. Property management

| Threat | STRIDE | Scenario | Control | Risk |
|---|---|---|---|---|
| Admin deletes a property and destroys its agreements and tax history | Denial of service | A single delete cascades through `rent_agreements` and `tax_assessments` into `tax_payments` | `destroy()` blocks deletion when active agreements or outstanding assessments exist | R-PROP-01 |
| Citizen edits another citizen's property | Tampering | Citizen posts to the update route with another property id | `authorizeOwner()` → `abort_unless($property->owner_id === Auth::id(), 403)` on both the form and the store | — |
| Citizen edits a property they do not own via a forged request | Elevation of privilege | Citizen submits a `PropertyRequest` for a property they do not own | The update request path re-checks ownership before creating the request | — |
| Citizen self-declares the assessed value used for tax | Tampering | Citizen submits `assessed_value` in a property update request | **Not yet controlled** — the admin applies the payload verbatim | R-PROP-04 |
| Transfer to a non-existent user silently no-ops | Denial of service | Admin approves a transfer to an unregistered email; ownership never changes and no one is notified | **Not yet controlled** | R-PROP-05 |
| Search leaks another citizen's valuation | Information disclosure | Non-owner reads `assessed_value` from the property page | The valuation panel is gated on ownership (`PropertyValuationVisibilityTest`) | — |

---

## 5. Rental agreements

| Threat | STRIDE | Scenario | Control | Risk |
|---|---|---|---|---|
| Neither party is told the agreement exists | Repudiation | Agreement is created; the tenant never learns of it | `RentAgreementGenerated` notification to both parties | R-RENT-01 |
| Agreement document altered after issue | Tampering | `terms_text` is a plain blob with no integrity check | **Not yet controlled** — the PDF renders the number and timestamp, but there is no detached signature | R-RENT-03 |
| Agreement is live without either party's consent | Repudiation | Admin approves; the tenant never agreed to the terms | **Accepted gap** — no e-signature step | R-RENT-04 |
| Third party reads an agreement | Information disclosure | Agreement URL is guessable by id | `show()` and `download()` both check the requester is tenant or landlord | — |
| Admin approves a request the owner never confirmed | Tampering | Admin acts on terms the owner did not agree to | `ready_for_admin` gate on both the queue and the approve action | R-RENT-06 |
| Agreement lookup leaks other people's agreements | Information disclosure | User guesses an agreement number | Lookup is scoped to the requesting user's own agreements; others get 403 | R-RENT-05 |

---

## 6. Tax assessment and payment gateway

The most security-critical module. The gateway is treated as
**semi-trusted**: it is assumed to be reachable and honest in the
direction we control, but every value arriving *at* us from it is
treated as attacker-supplied until reconciled.

| Threat | STRIDE | Scenario | Control | Risk |
|---|---|---|---|---|
| Forged callback marks an assessment paid | Tampering | Attacker POSTs `status=VALID&amount=1` to the success route or the IPN | `validateTransactionForAssessment()` reconciles amount, currency and tran id against the gateway's Validation API on both paths | R-PAY-01 |
| Forged callback records the wrong amount | Tampering | Callback claims 1 BDT for a 15,000 BDT assessment | Same reconciliation; a mismatch aborts before any row is written | R-PAY-01 |
| Wrong currency accepted | Tampering | Callback claims USD for a BDT assessment | Currency is compared against the session currency | R-PAY-01 |
| Gateway cannot reach the IPN | Denial of service | The IPN sat behind `auth`; the gateway's server-to-server call carries no session | IPN moved outside the auth group | R-PAY-02 |
| Unauthenticated writes to the IPN | Tampering | Anyone can POST to the IPN | Optional shared-secret header check (`SSLCZ_IPN_SECRET`) plus the mandatory gateway reconciliation | R-PAY-02 |
| Duplicate payment rows from concurrent callbacks | Tampering | IPN and success callback land simultaneously | Unique index on `(tax_assessment_id, reference, method)` plus `firstOrCreate` | R-PAY-03 |
| Amount recorded from an untrusted field | Tampering | `confirmPayment()` read `amount` from the request | The amount now comes from the validated gateway response | R-PAY-01 |
| Currency only recorded in free-text notes | Repudiation | Cannot audit or aggregate by currency | `currency` column added to `tax_payments` | R-PAY-04 |
| Raw gateway payload discarded | Repudiation | Cannot reconcile against the gateway's settlement report | `gateway_response` JSON column stores the validation response | R-PAY-05 |
| Demo payments indistinguishable from real ones | Repudiation | A demo payment appears in revenue reporting as real money | **Partially controlled** — a `DEMO` marker is written to the notes; a first-class method discriminator is still open | R-PAY-06 |
| Overdue assessments are never flagged | Denial of service | Tax revenue is not collected on time | **Not yet controlled** — no scheduler invokes the command | R-PAY-07 |
| Citizen reads another citizen's tax records | Information disclosure | Tax assessment id is guessed | `authorizeAssessment()` checks `owner_id` | — |
| Citizen reads another citizen's receipt | Information disclosure | Receipt id is guessed | `authorizePayment()` checks the payer | — |

---

## 7. Complaint management

| Threat | STRIDE | Scenario | Control | Risk |
|---|---|---|---|---|
| Citizen cannot see how their complaint progressed | Denial of service | Only the current status is visible | `complaint_updates` timeline rendered for the citizen | R-COMP-01 |
| Admin sets an arbitrary status | Tampering | Any string accepted as a status | `ComplaintStatus` enum + transition guard | R-COMP-02, R-COMP-03 |
| Complaint rejected with no reason | Repudiation | Citizen cannot challenge the decision | A reason is required for the `rejected` status | R-COMP-04 |
| Citizen reads another citizen's complaint | Information disclosure | Complaint id is guessed | `show()` checks `user_id === Auth::id()` | — |
| Complaint attachments publicly enumerable | Information disclosure | Files on the `public` disk at a guessable path | **Not yet controlled** — must move to a private disk behind an authorised route | R-COMP-07 |
| Citizen files a complaint for someone else's property | Tampering | `property_id` set to another citizen's property | Ownership check before the row is created | — |
| Citizen is never told their complaint was resolved | Repudiation | Resolution happens silently | **Not yet controlled** | R-COMP-08 |

---

## 8. Testing and data protection

| Threat | STRIDE | Scenario | Control | Risk |
|---|---|---|---|---|
| Test suite wipes the live database | Denial of service | `RefreshDatabase` runs against the `.env` MySQL database | `phpunit.xml` sets in-memory SQLite | R-TEST-01 |
| Test suite silently passes while the code is broken | Repudiation | Tests assert nothing meaningful | Every module now has a named test class; the coverage matrix is in `docs/testing/strategy.md` | R-TEST-03 |
| Verification documents reachable via the public symlink | Information disclosure | NID images stored on the `public` disk | The `document()` route streams from the private path and allow-lists the three fields | — |
| Complaint attachments on the public disk | Information disclosure | Attachments at a guessable URL | **Not yet controlled** | R-COMP-07 |

---

## 9. Summary of open gaps

The following threats have **no control today** and are carried in the
register as Open or Accepted:

| Threat | Risk | Status |
|---|---|---|
| Complaint attachments publicly enumerable | R-COMP-07 | Open — next loop |
| Citizen self-declares assessed value | R-PROP-04 | Open — next loop |
| No cryptographic signature on the agreement document | R-RENT-03 | Open — next loop |
| No party-consent step on the agreement | R-RENT-04 | Accepted |
| No admin separation of duties on NID approval | R-VER-07 | Open |
| Identity cross-check covers 2 of 8 fields | R-VER-06 | Open |
| No overdue-assessment scheduler | R-PAY-07 | Open |
| Demo payments not first-class in reporting | R-PAY-06 | Open |
| No admin-user management workflow | R-AUTH-07 | Accepted |
| No policy layer (inline `abort_unless` checks only) | R-AUTH-06 | Open |
