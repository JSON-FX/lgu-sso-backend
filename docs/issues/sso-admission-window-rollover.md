# Root cause analysis: false work-budget rejection at minute rollover

Direct production test report, October 17, 2026. No GitHub issue ID was supplied.

## Assessment

- Severity: high. An authorized request can receive 429 despite available capacity.
- Complexity: medium. One shared counter helper serves batch-item and cold-comparison scopes; accounting and failure behavior must remain conservative.
- Confidence: high for the reproducible code defect; production response attribution is inferential because the response does not identify the failing work scope.

## Evidence and reproduction

Run C stopped on a batch 429 near 06:27:00 UTC. The immediate 06:27:03 snapshot showed HTTP attempts 280/800 with six seconds left in that application's current timer; retained batch counters were 46/50 in the previous app/global window and 1/1 in the new window. Cold counters were 1 in both windows. Runtime settings and stores were independently verified unchanged. No worker saturation or browser request failure occurred. All 23 accepted messages reached all five users without duplicates. Existing operational access logs cannot identify the application or exception branch by themselves.

At source `29e8139`, `SsoWorkBudget.php:33–41` captures a minute key and increments its counter. Lines 49–50 reject with 429 if the clock moves to another window, before usage is compared with the limit at lines 52–53. `SsoCapacityAdmissionTest.php:36–51` explicitly reproduces this with an increment of one against a limit of ten while advancing the clock two seconds.

## Evidence chain

1. A below-quota request can be rejected because its reservation spans a wall-clock boundary.
2. The selected old generation no longer proves admission in the current generation.
3. The helper currently resolves that uncertainty by emitting an overload response immediately.
4. A fresh reservation against the current generation can resolve it without trusting the old reservation, refunding work or resetting a counter.
5. The specific fix is one bounded fresh-window retry, requiring a stable and within-limit increment before admission.

The behavior was introduced in the original bounded-authorization PR #9. Two read-only PIV explorers independently traced every applicable 429 path and reviewed the retry strategy. HTTP exhaustion, genuine work exhaustion and rollover must remain distinct. The production snapshot supports rollover; the isolated regression establishes the defect independently.

## Fix and acceptance

Change only the shared helper, its focused regressions and the contract documentation. Retain every old reservation. If the first atomic increment crosses a window, reserve once in the current generation. Admit only a stable valid integer result within quota. A second crossing fails closed with 503; cache failure/malformed results fail immediately. Stable true exhaustion stays 429 with its current Retry-After. Never refund, reset or delete counters. Sequential global/application reservations retain their existing per-scope fixed-window semantics; this does not promise simultaneous reservation, a sliding-minute limit or delayed authorization reuse.

Tests: retained old/new database rows; exhausted old window with new room; exhausted new window; exactly two attempts on repeated rollover; invalid/exceptional retry; existing stable success/exhaustion and atomic concurrency. Run the full isolated Pest suite, Pint and review. Keep approved 800 limits, cold 5/10, one batch worker and six-second hard termination unchanged. No migrations, dependency, employee authorization cache or staging activation.
