# Execution Story — bybit_bot BTCUSDT anomaly (2026-04-05)

**Session log:** `/app/var/log/bybit_bot/10001/2026-04-05/engine_12-51.log`  
**Observed symptom:** Position needs to reduce from −0.740 to −0.500 BTC (buy ≈ +0.240),  
but bot appeared stuck with sell-direction pending orders and no new buy orders posted.

---

## Context

| Parameter | Value |
|---|---|
| Signal | ID:250, SELL BTCUSDT, target = −0.500 BTC |
| Real position at session start | −0.740 BTC (over-sold) |
| Required action | BUY ≈ +0.240 BTC to reach target |
| Active batch | 222 (MM@BTCUSDT, start=−0.70, target=−0.50) |

---

## Root Cause Chain

### Step 1 — Stale SELL entries in pending_orders from old batches (12:51–13:11)

At startup, batches 206–221 were loaded from DB. Some contained SELL orders recorded as
`active` in the DB but already executed or cancelled on exchange (no WS session existed
before this run). `TradingNow()` saw non-zero `PendingAmount()` in the SELL direction and
returned `true` continuously, preventing `ScanForTrade()` from reaching the counter-phase
cancel path.

**`TrackOrders` eventually cleared these at 13:11:45:**
```
#OK(TrackOrders): no pending orders now
```

### Step 2 — MM creates batch 222 (BUY direction — correct)

After cleanup, MM posted batch 222 to reduce position from −0.70 toward −0.50.
- `IID_4568909`: BUY 0.060 BTC → filled at 13:14:17 via WS push
- `IID_4568929`: BUY 0.003 BTC @ 66719.6 → posted immediately after

### Step 3 — SIGNAL_WAIT on micro-pending resets target_pos (13:15:43)

With a 0.003 BTC BUY order still open, `SIGNAL_WAIT` fired:
```
#SIGNAL_WAIT: signal 250 have pending amount 0.003
  saldo/sig/batch dir [1, 1, 0]
  order: id#4568929 buy BTCUSDT 0.003 @ 66719.6, batch=222, owner:mm_exec
```

Consequence: target_pos was reset from −0.500 back to −0.740 (current position),
bias dropped to 0, trade direction set to 0. **The remaining 0.177 BTC BUY requirement
was fully suppressed by a micro-order.**

### Step 4 — Batch 222 lock not cleared (13:16:45)

Next cycle, exec=0, pending=0. But:
```
#BLOCK_MM_EXEC: MM MM@BTCUSDT exec=0, have active 0 orders,
  skip trade, bias=0.134, cost=4000, accel=5
```
`batch->lock` was still set after the fill. `batch->Update()` must unlock when
`exec_orders = 0` and `TargetLeft() != 0`, but the condition was not met.

---

## Full Flow Diagram

```
Start: pos=-0.74, signal target=-0.50 (need BUY +0.24)
│
├── [12:51–13:11] stale SELL pending from batches 206-221
│       TradingNow()=true → BUY counter-phase cancel never reached
│
├── [13:11:45] TrackOrders clears stale entries
│
├── [13:03:30] MM creates batch 222 (BUY direction ✓)
│       IID_4568899 filled 0.020 (12:52, prior session order)
│       IID_4568909: BUY 0.060 → filled 13:14 ✓
│       IID_4568929: BUY 0.003 → posted
│
├── [13:15:43] SIGNAL_WAIT fires on pending=0.003
│       target_pos: -0.50 → -0.74 (bias killed)
│       Trade() produces 0 new orders
│
└── [13:16:45] BLOCK_MM_EXEC (batch 222 lock not released)
        bias=0.134 (correct), accel=5, but trade blocked
```

---

## Why "sell orders blocking buy"

The apparent paradox (batch 222 = BUY, older pending = SELL) stems from stale DB state.
The code path that cancels counter-phase orders is inside `ScanForTrade()` which is only
reached when `TradingNow()` returns false. Since `TradingNow()` returned `true` due to
stale DB entries, the cancel logic was never executed — not because it was wrong, but
because it was never called.

---

## Identified Bugs

### Bug 1 — SIGNAL_WAIT micro-pending suppresses full bias

**Location:** `trading_loop.php`, `SIGNAL_WAIT` handling in `ScanForTrade()`  
**Problem:** Any pending amount, however small, resets `target_pos` to `curr_pos`,
zeroing the bias. A 0.003 BTC order blocked 0.177 BTC remaining need.  
**Fix:** Add a threshold — only suppress bias when `pending_amount > min_lot_size`
(or `pending_cost > min_cost`). If the micro-pending is in the same direction as the
required trade, allow the remainder to proceed.

### Bug 2 — Batch lock not released after partial fill with exec=0

**Location:** `orders_batch.php`, `batch->Update()`  
**Problem:** After the exec order fills and exec_orders drops to 0, `batch->lock`
is not cleared if `TargetLeft() > 0` (batch is partially complete).  
**Fix:** In `batch->Update()`, when `exec_orders == 0` and `TargetLeft() != 0`,
clear `lock` so the next Trade() cycle can post a follow-up order.

---

## Signal delta vs sum-of-signals-delta prioritization

Current behaviour: if a signal has any pending orders, its `target_pos` contribution 
is collapsed to `curr_pos`, effectively eliminating the delta for that signal from the
global sum — even when the pending order is in the correct direction and much smaller
than the remaining delta.

Desired behaviour:
1. **Same-direction micro-pending**: do NOT suppress delta; only set `SIGNAL_WAIT` 
   if `pending / delta_remaining > 0.5` (majority is covered).
2. **Opposite-direction pending**: full SIGNAL_WAIT is correct — counter-phase is 
   actively harmful.
3. **Sum-of-signals delta** (global balance) should override per-signal delta only 
   when the per-signal completion is substantially in-flight (>50% of its target).

This preserves the fast convergence property while eliminating the micro-order deadlock.
