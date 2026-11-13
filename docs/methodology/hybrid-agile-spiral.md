# Hybrid Agile-Spiral Development Methodology

## 1. Purpose

This document describes the development methodology used to build the
Citizen Services Portal. It is a **hybrid** of two models:

- The **Spiral Model** (Boehm, 1988) provides the *macro* frame. It is
  used because this system carries significant technical and security
  risk — identity verification, a public payment gateway, and personally
  identifiable data. Spiral forces an explicit **risk analysis before
  engineering**, which is exactly what a civic system with fraud
  exposure needs and what a pure Agile process tends to defer.
- **Agile / Scrum** provides the *micro* frame *inside* each spiral
  loop. Sprints give the team short, demonstrable increments; Scrum's
  ceremonies keep the work visible and the stakeholders engaged.

The two are not in tension. Spiral decides **what risk to retire and
when it is safe to move on**; Scrum decides **how the work inside a
loop is sequenced and delivered**.

---

## 2. How the two models compose

```
        ┌───────────────────────────────────────────────────────────┐
        │                  SPIRAL (macro frame)                     │
        │                                                           │
        │   ┌─────────────────────────────────────────────────┐     │
        │   │  LOOP 1  ──►  LOOP 2  ──►  LOOP 3  ──►  ...   │     │
        │   │      ▲           ▲           ▲                 │     │
        │   │      │           │           │                 │     │
        │   │   ┌──┴──┐     ┌──┴──┐     ┌──┴──┐              │     │
        │   │   │Scrum│     │Scrum│     │Scrum│  (micro)     │     │
        │   │   └─────┘     └─────┘     └─────┘              │     │
        │   └─────────────────────────────────────────────────┘     │
        │                                                           │
        │   1. Plan  ─────────────────────────────────────►        │
        │   2. Risk analysis  ◄── the gate that drives everything  │
        │   3. Engineering (Sprint cycles)                         │
        │   4. Evaluation (customer review + risk re-assessment)   │
        └───────────────────────────────────────────────────────────┘
```

Each spiral loop produces four artefacts:

| Spiral phase | Artefact produced |
|---|---|
| **Plan** | Iteration plan (`docs/methodology/spiral-iterations.md`) + updated backlog |
| **Risk analysis** | Updated risk register (`docs/risk/risk-register.md`) with re-scored entries |
| **Engineering** | Merged, tested code passing the Definition of Done |
| **Evaluation** | Sprint review notes + retrospective log + go/no-go decision for the next loop |

### The gate

The Spiral "risk analysis" phase is the **gate** between loops. A loop
is only closed when:

1. The risks it was opened to retire are closed or have an accepted,
   documented residual rating.
2. The acceptance criteria for its backlog items are met.
3. The evaluation phase has been completed with the client.

There is no fixed iteration length. A loop lasts as long as the risk
analysis demands — a payment-gateway integration will take longer than
a static content page, because the risks differ.

---

## 3. Roles

| Role | Spiral responsibility | Agile responsibility |
|---|---|---|
| **Product Owner** (municipal stakeholder) | Approves the risk appetite for each loop; signs off at the evaluation gate | Prioritises the backlog; accepts stories |
| **Architect / Risk Owner** | Runs the risk analysis workshop; owns the register | Technical lead; reviews design within each sprint |
| **Scrum Master** | — | Runs the daily stand-up, sprint planning, review, retrospective; removes blockers |
| **Developer** | Performs the risk mitigations identified for the loop | Delivers sprint increments |
| **QA / Test Engineer** | Verifies mitigations actually reduce the scored risk | Writes the unit and feature tests in the Definition of Done |
| **Security Reviewer** | Reviews STRIDE analysis for the loop's features | Reviews any change touching auth, uploads, or payments |

---

## 4. Cadence

- **Daily stand-up** — 15 minutes, within a sprint.
- **Sprint planning** — at the start of every sprint; the sprint
  backlog is carved out of the loop's backlog.
- **Sprint review** — demonstration to the Product Owner; feeds the
  evaluation phase.
- **Sprint retrospective** — recorded in
  `docs/methodology/retrospective-log.md`.
- **Risk review** — held at the start of every loop *and* whenever a
  High or Critical risk changes state.

---

## 5. Definition of Done

A story is only done when **all** of the following hold:

1. Code is merged to `master` behind a reviewed pull request.
2. It has automated test coverage at the appropriate level — pure logic
   gets a unit test (`tests/Unit`), HTTP behaviour gets a feature test
   (`tests/Feature`).
3. The full suite passes: `php artisan test`.
4. `vendor/bin/pint --test` reports no style violations.
5. No new High or Critical risk is introduced without a register entry
   and an agreed mitigation.
6. Any user-facing change is demonstrated in the sprint review.
7. Migrations are reversible (`down()` implemented and correct).

---

## 6. Mapping the objectives to loops

| Loop | Objective addressed | Principal risks retired |
|---|---|---|
| **Loop 1** | Foundation: authentication, RBAC, roles | R-AUTH-01 (no login throttle), R-AUTH-02 (no email-verification gate) |
| **Loop 2** | Citizen verification + digital certificate | R-VER-01 (predictable certificate numbers), R-VER-02 (no revocation) |
| **Loop 3** | Property management + search | R-PROP-01 (cascading delete destroys records), R-PROP-02 (broken owner search) |
| **Loop 4** | Rental agreements + distribution | R-RENT-01 (silent agreement creation), R-RENT-02 (no downloadable artefact) |
| **Loop 5** | Tax assessment + payment gateway | R-PAY-01 (no server-side gateway validation), R-PAY-02 (unreachable IPN), R-PAY-03 (no DB-level idempotency) |
| **Loop 6** | Complaint workflow + tracking | R-COMP-01 (no status history), R-COMP-02 (free-text statuses) |
| **Loop 7** | Testing, documentation, hardening | R-TEST-01 (non-hermetic test database), R-DOC-01 (no methodology or risk artefacts) |

See `docs/methodology/spiral-iterations.md` for the detailed plan of
each loop.

---

## 7. Risk management

The risk analysis phase is described in full in
`docs/risk/risk-analysis-method.md`. In summary:

- Risks are identified at the start of every loop, with security
  threats analysed per-feature using STRIDE.
- Each risk is scored on **likelihood × impact** and given an owner.
- The register (`docs/risk/risk-register.md`) is a living document and
  is the primary input to the next loop's scope.
- A risk that is not mitigated and not explicitly accepted is a
  blocker for closing the loop.

See also: `docs/risk/threat-model.md` for the per-feature STRIDE
analysis and `docs/risk/security-controls.md` for the control
catalogue with the control-to-risk traceability matrix.
