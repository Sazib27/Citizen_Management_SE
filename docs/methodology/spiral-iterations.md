# Spiral Iteration Plan

Seven loops. Each loop is a full Spiral cycle — **Plan → Risk Analysis
→ Engineering → Evaluation** — with Agile sprints inside the
Engineering phase.

The "Loop N" rows record the *scoping* decision made at the risk
analysis phase of that loop. The "Retired risks" column links to the
register in `docs/risk/risk-register.md`.

---

## Loop 1 — Foundation: authentication and role-based access control

**Objective addressed:** *"To implement a secure authentication system
with role-based access control distinguishing citizen and administrator
privileges."*

| Phase | Content |
|---|---|
| **Plan** | Establish the auth skeleton: registration, login, logout, password reset, email verification, role model, middleware stack. |
| **Risk analysis** | Reviewed the auth surface. Identified that the login endpoint had no throttle (R-AUTH-01) and that email verification was advisory only (R-AUTH-02). |
| **Engineering** | Sprints 1.1–1.3. Introduced `AdminMiddleware`, `CitizenMiddleware`, `EnsureCitizenVerified`, `EnsureEmailIsVerified`; named rate limiters for `login`, `register`, `nid-status`; `updateProfile` hardened against email/name tampering. |
| **Evaluation** | Client confirmed the citizen/admin split works. Gate passed. |
| **Retired risks** | R-AUTH-01, R-AUTH-02, R-AUTH-03, R-AUTH-04, R-AUTH-05, R-AUTH-08 |

---

## Loop 2 — Citizen verification and the digital certificate

**Objective addressed:** *"To develop a citizen verification workflow
that cross-checks submitted identification data and issues digitally
verifiable certificates."*

| Phase | Content |
|---|---|
| **Plan** | Build the apply → review → approve/reject workflow on top of the NID registry, and turn the certificate into a real, verifiable artefact. |
| **Risk analysis** | Identified that the certificate number was `VCN-{user_id}` — a sequential guess. Also that the certificate could not be revoked (R-VER-01, R-VER-02) and could not be checked by a third party (R-VER-03). |
| **Engineering** | Sprints 2.1–2.2. Added the `certificates` table and `Certificate` model with a random number and a full lifecycle; issued the certificate on admin approval; added the public `verification.certificate.verify` route and a PII-minimal verification view; made the QR step failure-tolerant. |
| **Evaluation** | Verified end-to-end: approve a citizen → certificate issued → PDF downloaded → QR scanned → third party sees "valid". Gate passed. |
| **Retired risks** | R-VER-01, R-VER-02, R-VER-03, R-VER-04, R-VER-05 |

---

## Loop 3 — Property management

**Objective addressed:** *"To design a property management system that
supports the addition, modification, transfer, and search of property
records."*

| Phase | Content |
|---|---|
| **Plan** | Consolidate the citizen request / admin approval model, and make search genuinely useful. |
| **Risk analysis** | Found that `destroy()` cascaded into rent agreements and tax history (R-PROP-01) — a data-destruction path a single admin click could trigger. Also found that owner-name search filtered on `display_name`, an accessor rather than a column, so the query would fail at runtime (R-PROP-02). |
| **Engineering** | Sprints 3.1–3.2. Delete guards on active agreements and outstanding assessments; search widened to title, address, city, state, postal code and owner across both the citizen and admin lists. |
| **Evaluation** | Gate passed. R-PROP-04 (citizen-supplied valuation) remains open and was deliberately deferred to a later loop — it needs the assessment service to own valuation, not the property request form. |
| **Retired risks** | R-PROP-01, R-PROP-02, R-PROP-03 |

---

## Loop 4 — Rental agreements

**Objective addressed:** *"To automate the generation and distribution
of digital rental agreements between property owners and tenants."*

| Phase | Content |
|---|---|
| **Plan** | Make the agreement a distributable digital document that both parties actually receive. |
| **Risk analysis** | The agreement was created silently (R-RENT-01) and existed only as a database row (R-RENT-02). Separately, the admin's rental-request queue showed requests the owner had not confirmed, so the admin saw rows they could not act on (R-RENT-06). |
| **Engineering** | Sprints 4.1–4.2. `RentAgreementGenerated` notification to both parties on approval; DomPDF document with a download route for both parties and admins; lookup by agreement number; admin queue filtered on `ready_for_admin`. |
| **Evaluation** | Verified the whole flow: tenant request → owner confirm → admin approve → both parties notified → both can download the PDF. Gate passed. |
| **Retired risks** | R-RENT-01, R-RENT-02, R-RENT-05, R-RENT-06 |

---

## Loop 5 — Tax assessment and the online payment gateway

**Objective addressed:** *"To build a tax assessment and online payment
module integrated with a secure third-party payment gateway."*

| Phase | Content |
|---|---|
| **Plan** | Replace the Stripe integration with SSL Commerz and make the payment path genuinely secure. |
| **Risk analysis** | This loop carried the highest risk in the project. The `validateTransaction()` method existed but **was never called** — the payment amount and currency came straight off the request, so a forged callback could mark any assessment as paid (R-PAY-01, score 25). The IPN route sat behind the `auth` middleware, so the gateway's server-to-server call carried no session and could never reach the handler (R-PAY-02, score 20). Both were found by tracing the write path, not by reading the code surface. |
| **Engineering** | Sprints 5.1–5.3. `validateTransactionForAssessment()` reconciles amount, currency and transaction id against the gateway and is called on the success callback and the IPN; the IPN moved outside the auth group and is protected by an optional shared-secret header plus the reconciliation; unique index for DB-level idempotency; `currency` and `gateway_response` columns added. |
| **Evaluation** | Adversarial tests written: forged amount, wrong currency, non-valid gateway status, replayed callback, missing secret. All rejected without writing a payment row. Gate passed. |
| **Retired risks** | R-PAY-01, R-PAY-02, R-PAY-03, R-PAY-04, R-PAY-05 |

---

## Loop 6 — Complaint management

**Objective addressed:** *"To provide a structured complaint management
workflow enabling citizens to submit, track, and receive resolutions to
grievances."*

| Phase | Content |
|---|---|
| **Plan** | Turn a status field into a workflow: a defined lifecycle, a visible history, and a resolution the citizen can rate. |
| **Risk analysis** | The citizen could see only the *current* status (R-COMP-01) — "track" was not satisfied. The status was a free-text string duplicated across six places (R-COMP-02) and could be set to any value (R-COMP-03). |
| **Engineering** | Sprints 6.1–6.2. `ComplaintStatus` backed enum with a transition map; `complaint_updates` table recording every transition with its author and note; `rejected` status requiring a stated reason; reopening clears stale closure stamps; citizen-side status filter and search; 1–5 star rating once closed. |
| **Evaluation** | Verified: submit → progress → resolve → citizen sees the full timeline → citizen rates the resolution. Gate passed. |
| **Retired risks** | R-COMP-01, R-COMP-02, R-COMP-03, R-COMP-04, R-COMP-05, R-COMP-06 |

---

## Loop 7 — Testing, documentation and hardening

**Objective addressed:** *"To apply a hybrid Agile-Spiral development
methodology…"* and *"…to evaluate the developed system through unit and
feature-level testing."*

| Phase | Content |
|---|---|
| **Plan** | Make the suite trustworthy, cover the untested modules, and write down the methodology the project claims to follow. |
| **Risk analysis** | The single most dangerous finding in the whole project: **the test suite ran against the live MySQL database.** `RefreshDatabase` would have wiped real data on every test run (R-TEST-01). The SQLite configuration in `phpunit.xml` had been commented out. Underneath that, complaints, authentication, verification and the service layer had no coverage at all (R-TEST-03), and the project had no methodology or risk documentation (R-DOC-01). |
| **Engineering** | Sprints 7.1–7.2. Enabled `pdo_sqlite` and set the suite to in-memory SQLite; fixed the migration that made the whole suite fail under SQLite; added 5 test classes covering auth, complaints, certificates and gateway validation; wrote `README.md` and the `docs/` tree. |
| **Evaluation** | Suite green: 89 tests, 284 assertions, hermetic. Gate passed. |
| **Retired risks** | R-TEST-01, R-TEST-02, R-TEST-03, R-DOC-01, R-DOC-02 |

---

## Loop 8 (planned) — Not yet started

**Focus:** the four remaining High risks plus the accepted gaps.

| Item | Risk |
|---|---|
| Move complaint attachments to a private disk behind an authorised streaming route | R-COMP-07 |
| Exclude valuation fields from citizen-submitted property payloads | R-PROP-04 |
| Detached cryptographic signature on the rent agreement document | R-RENT-03 |
| Citizen/landlord acceptance (e-signature) step on the agreement | R-RENT-04 |
| `ComplaintResolved` notification to the citizen | R-COMP-08 |
| GitHub Actions CI pipeline | R-TEST-04 |
| PHPStan / Psalm static analysis | R-TEST-05 |
| `SECURITY.md` disclosure policy | R-DOC-03 |
| Laravel Policies / Gates to replace the inline `abort_unless` checks | R-AUTH-06 |
| Extend the identity cross-check to the remaining registry fields | R-VER-06 |
