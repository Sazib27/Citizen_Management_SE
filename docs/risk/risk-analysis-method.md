# Risk Analysis Method

This is the procedure followed during the **Risk Analysis** phase of each
spiral loop. It is the part of the hybrid model that does the heavy
lifting: it is what turns "we should be careful about payments" into a
scored, owned, tracked risk with a defined mitigation.

---

## 1. When it runs

- **At the start of every loop**, before any engineering begins.
- **Whenever a High or Critical risk changes state**, out of cycle.
- **At the end of every loop**, as part of the evaluation phase — the
  register is re-scored to reflect what actually shipped.

## 2. Participants

Architect / Risk Owner (facilitator), Security Reviewer, Product Owner
(risk appetite), and the engineers who will implement the mitigations.

## 3. Steps

### Step 1 — Identify

Risks are identified from four sources:

| Source | Method |
|---|---|
| **Feature-level threat analysis** | STRIDE applied per feature. See `docs/risk/threat-model.md`. |
| **Code and data-flow review** | Trace each write path end-to-end: *who can cause this write, and what exactly do they control?* |
| **Dependency and configuration review** | Third-party packages, environment configuration, storage locations. |
| **Test-suite audit** | What is not covered? What does the suite actually run against? |

The code-review step is deliberately not a surface reading of the
controller. The two most severe findings in this project — the dead
`validateTransaction()` call and the unreachable IPN route — were both
found by tracing what data reaches the database, not by reading the
method signatures.

### Step 2 — Describe

For each risk record:

- **ID** — `R-<AREA>-<NN>`
- **Description** — the concrete harm, not the abstract category
- **Cause** — why the risk exists
- **Consequence** — what actually goes wrong if it is not addressed

### Step 3 — Score

**Likelihood (L)** — how likely is it, given the current controls?

| L | Meaning |
|---|---|
| 1 | Rare — would require unusual conditions |
| 2 | Unlikely — plausible but not expected |
| 3 | Possible — could occur in normal operation |
| 4 | Likely — will occur without intervention |
| 5 | Almost certain — already happening, or trivially exploitable |

**Impact (I)** — how bad is it?

| I | Meaning |
|---|---|
| 1 | Negligible — cosmetic, no data or money affected |
| 2 | Minor — limited data exposure, recoverable by hand |
| 3 | Moderate — PII exposure, or manual rework for staff |
| 4 | Major — financial loss, or exposure of sensitive citizen records |
| 5 | Severe — systemic loss of money, destruction of the audit trail, or compromise of many citizens' identities |

**Score = L × I.** The rating bands and the required disposition are in
the register header.

### Step 4 — Prioritise

Risks are ordered by score descending. The highest-scoring risk defines
the primary concern of the loop; the loop's scope is drawn around
closing it.

### Step 5 — Choose a response

| Response | When |
|---|---|
| **Mitigate** | Reduce likelihood or impact through a control |
| **Avoid** | Change the design so the risk cannot occur |
| **Transfer** | Move the exposure to a third party (e.g. the payment gateway) |
| **Accept** | The cost of mitigation exceeds the harm. **Requires a written rationale and an explicit Product Owner sign-off.** |

### Step 6 — Assign and implement

Each risk gets an **owner** and a **mitigation**, and the mitigation
becomes a backlog item with acceptance criteria. A mitigation is only
"done" when it is covered by an automated test — the register's status
column moves to *Mitigated* only when a test proves the control holds.

### Step 7 — Re-assess

At the end of the loop, every risk is re-scored against what actually
shipped. Controls that were implemented but do not work are caught
here; the score goes back up and the loop does not close.

---

## 4. Escalation

- A **Critical** risk (16–25) that is discovered *during* engineering
  stops the current sprint. It is escalated to the Architect and the
  Product Owner immediately, and the loop's scope is re-planned.
- A **High** risk (10–15) discovered during engineering is added to the
  current loop unless the Product Owner explicitly accepts it.
- Any risk whose mitigation requires a schema change is flagged early —
  migrations are the hardest thing to change safely late in a loop.

---

## 5. Review cadence

| Trigger | Action |
|---|---|
| Start of a loop | Full re-score of the register |
| Sprint review | Check whether any risk's state changed |
| Deployment | Re-score every risk touched by the release |
| Incident | Add a new risk entry and score it the same day |

---

## 6. Worked example — R-PAY-01

**Description.** An attacker posts a forged callback to the payment
success route or the IPN, claiming a valid transaction for a low amount,
and the assessment is marked as paid.

**Cause.** `SSLCommerzCheckoutService::validateTransaction()` existed and
was documented as the thing to call, but nothing called it. The
controller read `amount` and `currency` from the request and used them
directly. The IPN likewise trusted `status` from the request body.

**Consequence.** Any unpaid citizen could mark their own tax assessment
as paid. Revenue loss on every assessment, and — because the recorded
payment amount would also be wrong — a broken audit trail.

**L = 5** (trivially exploitable: craft a POST with `status=VALID`)
**I = 5** (direct financial loss, systemic across every assessment)
**Score = 25 → Critical.**

**Response.** Mitigate.

**Mitigation.** `validateTransactionForAssessment()` re-queries the
gateway's Validation API with the `val_id` and compares the gateway's
own answer against our books: the status must be `VALID`/`VALIDATED`,
the currency must match the currency the session was opened in, the
amount must equal `tax_amount` to the cent, and the transaction id must
be the one we generated. The method is called on the success callback and
on the IPN. If it returns invalid, no payment row is written and the
assessment is left outstanding.

**Verification.** `SSLCommerzGatewayValidationTest` — five adversarial
tests covering a forged amount, a wrong currency, a non-valid gateway
status, a replayed callback, and a missing shared secret.

**Status.** Mitigated.
