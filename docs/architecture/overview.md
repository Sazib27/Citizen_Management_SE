# Architecture Overview

## 1. Stack

| Layer | Choice | Version |
|---|---|---|
| Language | PHP | ^8.2 (running on 8.4) |
| Framework | Laravel | ^11.0 |
| Templating | Blade | — |
| PDF generation | `barryvdh/laravel-dompdf` | ^2.0 |
| Payment gateway | SSL Commerz (hosted checkout) | — |
| Database | MySQL (production) / SQLite (tests) | — |
| Test runner | PHPUnit via `php artisan test` | ^10.5 |

Laravel 11 layout: no `app/Http/Kernel.php`. Middleware aliases are
registered in `bootstrap/app.php`.

---

## 2. Module map

```
routes/web.php
│
├── auth (guest)                    AuthController
│     register · login · password reset
│
├── email verification (auth)       AuthController
│     notice · signed verify · OTP verify
│
├── /citizen  [auth, citizen]      ── Citizen area ──
│   ├── /verification/*             VerificationController  (public + citizen)
│   ├── [auth, citizen, verified.email, verified.citizen]
│   │   ├── /properties             PropertyController
│   │   ├── /rent-agreements        CitizenRentAgreementController
│   │   ├── /taxes                  CitizenTaxController
│   │   │   └── /payment/*          SSLCommerzTaxPaymentController
│   │   └── /complaints             ComplaintController
│   └── /verification/certificate   VerificationController
│
├── /citizen/taxes/payment/ipn      SSLCommerzTaxPaymentController   [web only]
│     └─ the gateway's server-to-server callback
│
└── /admin   [auth, admin]          ── Admin area ──
      ├── /dashboard                closure
      ├── /properties               AdminPropertyController
      ├── /rent-agreements          AdminRentAgreementController
      ├── /taxes                    AdminTaxController
      ├── /revenue                  AdminRevenueController
      ├── /complaints               AdminComplaintController
      ├── /verification             VerificationController
      └── /users                    AdminUserController

routes/api.php
└── /nid/{nidNumber}                FakeNidStatusController  [throttle:nid-status]
```

---

## 3. Domain model

```
                    ┌──────────┐
                    │   User   │  role: admin | citizen
                    │          │  verification_status: pending|verified|rejected
                    └────┬─────┘
         ┌───────────────┼───────────────┬──────────────┐
         │               │               │              │
    ┌────▼─────┐   ┌─────▼──────┐  ┌─────▼─────┐  ┌─────▼──────┐
    │Property  │   │ Certificate│  │ Complaint │  │TaxPayment  │
    │          │   │ (1:N)      │  │          │  │            │
    └────┬─────┘   └────────────┘  └──────────┘  └─────┬──────┘
         │                                             │
    ┌────┴─────┬──────────────┐                  ┌─────▼──────┐
    │          │              │                  │TaxAssessment│
┌───▼──────┐ ┌─▼──────────┐ ┌─▼─────────────┐  └────────────┘
│RentAgree-│ │RentalReq-  │ │PropertyRequest│
│ment      │ │uest        │ │               │
└──────────┘ └────────────┘ └───────────────┘
```

### Key relationships

| From | To | Cardinality | Notes |
|---|---|---|---|
| `User` | `Certificate` | 1:N | Issuing a new certificate revokes the previous one |
| `Property` | `RentAgreement` | 1:N | Cascades on delete — hence the delete guard |
| `Property` | `TaxAssessment` | 1:N | Cascades on delete — hence the delete guard |
| `TaxAssessment` | `TaxPayment` | 1:N | Unique on `(tax_assessment_id, reference, method)` |
| `Complaint` | `ComplaintUpdate` | 1:N | The status timeline |
| `RentalRequest` | `RentAgreement` | 1:1 | `rental_request_id` on the agreement |
| `User` | `Property` | 1:N | As owner |

---

## 4. Middleware stack

| Alias | Class | Enforces |
|---|---|---|
| `admin` | `AdminMiddleware` | `role === 'admin'`, else redirect to the citizen dashboard |
| `citizen` | `CitizenMiddleware` | `role === 'citizen'`, else redirect to the admin dashboard |
| `verified.citizen` | `EnsureCitizenVerified` | `verification_status === 'verified'` |
| `verified.email` | `EnsureEmailIsVerified` | `hasVerifiedEmail()`; admins are exempt |

Named rate limiters (registered in `AppServiceProvider`):

| Name | Limit |
|---|---|
| `login` | 5/min per email+IP, 25/hr per IP |
| `register` | 10/hr per IP |
| `nid-status` | 20/min per IP |

---

## 5. Key flows

### 5.1 Payment (the security-critical path)

```
 Citizen                Browser              SSL Commerz             Our server
   │                      │                      │                        │
   │ POST /taxes/pay      │                      │                        │
   │─────────────────────>│                      │                        │
   │                      │                      │   POST session API     │
   │                      │                      │───────────────────────>│
   │                      │                      │   {GatewayPageURL}     │
   │                      │      redirect       │                        │
   │<─────────────────────│<─────────────────────│                        │
   │                      │                      │                        │
   │  (user pays)         │                      │                        │
   │                      │                      │                        │
   │ GET /payment/success │                      │                        │
   │─────────────────────>│                      │                        │
   │                      │                      │                        │
   │                      │                      │   POST validation API  │
   │                      │                      │───────────────────────>│
   │                      │                      │   {amount, currency,    │
   │                      │                      │    status, tran_id}     │
   │                      │                      │<───────────────────────│
   │                      │                      │                        │
   │                      │   reconcile against OUR books                   │
   │                      │   amount == tax_amount? currency == BDT?         │
   │                      │   status == VALID? tran_id == ours?            │
   │                      │                      │                        │
   │                      │   ✗ fail  → no payment row, assessment unpaid   │
   │                      │   ✓ pass  → TaxPayment::firstOrCreate           │
   │                      │                      │                        │
   │                      │                      │   POST /ipn (no session)│
   │                      │                      │───────────────────────>│
   │                      │                      │   same reconciliation  │
   │                      │                      │   → idempotent         │
```

**The controlling principle:** every value arriving from the gateway is
treated as attacker-supplied until the gateway's own Validation API has
confirmed it against our books. The amount recorded is the *validated*
amount, never the one on the request.

### 5.2 Rental agreement

```
Tenant                Owner                Admin              Both parties
  │                     │                    │                     │
  │ POST rental-request │                    │                     │
  │────────────────────>│                    │                     │
  │  (status=pending,   │                    │                     │
  │   ready_for_admin=  │                    │                     │
  │   false)            │                    │                     │
  │                     │ POST owner-confirm│                     │
  │                     │───────────────────>│                     │
  │                     │  (ready_for_admin=│                     │
  │                     │   true)           │                     │
  │                     │                    │ POST approve       │
  │                     │                    │────────────────────>│
  │                     │                    │  RentAgreement      │
  │                     │                    │  created            │
  │                     │                    │  + notification ───>│
  │                     │                    │  + PDF available    │
```

### 5.3 Complaint

```
Citizen                Admin                Timeline
  │                      │                     │
  │ POST /complaints     │                     │
  │─────────────────────>│  (status=open)      │
  │                      │                     │──> entry: null → open
  │                      │                     │
  │                      │ PUT status=in_progress
  │                      │────────────────────>│──> entry: open → in_progress
  │                      │ PUT status=resolved
  │                      │────────────────────>│──> entry: in_progress → resolved
  │                      │                     │
  │ GET /complaints/{id} │                     │
  │<─────────────────────│  sees the full timeline
  │                      │                     │
  │ POST rating          │                     │
  │─────────────────────>│
```

---

## 6. Services

| Service | Responsibility |
|---|---|
| `SSLCommerzCheckoutService` | Session API, Validation API, demo-mode short circuit. Pure HTTP client — no framework coupling beyond the injected config. |
| `TaxAssessmentService` | Assessment generation and overdue flagging. |
| `RevenueDashboardService` | Aggregation for the admin revenue dashboard and CSV exports. |
| `StripeCheckoutService` | Legacy. Unrouted; retained for back-compatibility. |

---

## 7. Storage

| Disk | Contents | Access |
|---|---|---|
| `local` (private) | Verification NID/passport images | Streamed through `VerificationController::document()` with a field allow-list |
| `public` | Property images, **complaint attachments** | Public URLs — the complaint attachment path is an open gap (R-COMP-07) |
| `public` | Demo-mode payment markers | n/a |

---

## 8. Notifications

| Notification | Trigger | Recipients |
|---|---|---|
| `NewVerificationApplication` | Citizen submits a verification application | Admins |
| `PropertyTransferCompleted` | Admin approves an ownership transfer | Old and new owner |
| `RentAgreementGenerated` | Admin approves a rental request | Tenant and landlord |
| `TaxAssessmentOverdue` | The overdue flagging command runs | Property owner |

---

## 9. Scheduled work

`app/Console/Commands/FlagOverdueAssessments` marks past-due
assessments as `overdue` and notifies the owner. **It is not yet
scheduled** — a scheduler entry in `routes/console.php` and a queue
worker are required in deployment (R-PAY-07).

---

## 10. Test strategy

See `docs/testing/strategy.md`.
