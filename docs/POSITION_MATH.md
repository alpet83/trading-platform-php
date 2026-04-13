# Position Math and Anti-Chatter Design

## Purpose

This document defines a single, explicit math model for position control in derivatives bots, with focus on inverse/quanto contracts and anti-chatter behavior.

Goals:

- Keep position control stable under price movement when the signal is unchanged.
- Compute entry-anchor (average entry price) from the whole active position lifecycle, not from a recent local batch only.
- Reuse the same math for trading core and reports.
- Provide clear fallback behavior when history is incomplete.

## Problem Statement

A typical chatter pattern appears when target quantity in base asset is stable, but conversion into native contract amount is re-evaluated at current market price each cycle.

For inverse/quanto instruments this causes target contract amount to drift with price, which creates repetitive corrective micro-orders.

The correct economic anchor for an active position is not current ticker price and not "last batch" alone, but weighted entry cost of the current open leg since the last zero-cross (or explicit flat/open boundary).

## Current Code Situation

### Core

- Anchor conversion logic is centralized in [src/trading_math.php](src/trading_math.php).
- Core and trading loop both call the same resolver `resolvePositionAnchorPrices(...)`.
- Current primary anchor source is `last_batch_price(...)` and `last_batch_btc_price(...)`, with fallback to previous-session values.

### Reports

- Trade report already contains zero-cross aware accumulation in [src/web-ui/trades_report.php](src/web-ui/trades_report.php).
- Key classes:
  - `AccumPositionBase`
  - `AccumPosition`
- Logic:
  - Detect sign flip via previous and current position.
  - Reset/open new segment on zero-cross when `zero_reset = true`.
  - Keep weighted buy/sell volumes and quantities.

This confirms that the project already has position-lifecycle logic suitable as base for shared library extraction.

## Required Economic Model

### Definitions

- `pos_t`: signed current position in base quantity after trade `t`.
- `q_t`: signed trade quantity in base units.
- `p_t`: trade price in quote/base.
- `A_t`: native amount (contracts) where applicable.
- `side_t`: buy or sell.

### Position Segment

A segment starts at:

- first trade from flat (`pos` from 0 to non-zero), or
- first trade after sign change (zero-cross event).

A segment ends at:

- position returns to zero, or
- sign flips and previous side is fully closed.

### Entry Anchor

For current non-zero segment, compute weighted average entry from all opening trades that built current side.

Long side:

$$
P_{entry,long}=\frac{\sum_i q_i p_i}{\sum_i q_i}, \quad q_i>0
$$

Short side (absolute quantities):

$$
P_{entry,short}=\frac{\sum_i |q_i| p_i}{\sum_i |q_i|}, \quad q_i<0
$$

This anchor must include all fills in the active segment, even if the segment spans weeks/months and many batches.

### Conversion for Contract Target

When converting target base quantity to native amount for inverse/quanto contracts, use segment anchor price (and anchor BTC price when required) as reference, not current market ticker.

This suppresses price-only drift while signal is unchanged.

## Anti-Chatter Policy

### Rule 1: Stable conversion anchor

If no new position-building fills occurred for pair `X`, conversion anchor remains unchanged.

### Rule 2: Re-anchor events

Anchor is updated only when one of these happens:

1. New fill affecting active segment inventory.
2. Segment boundary event (flat or sign flip).
3. Explicit maintenance re-anchor (optional, low frequency).

### Rule 3: Maintenance correction frequency

Small correction once per day is acceptable.

Recommended policy:

- daily (or configurable) re-anchor check,
- execute correction only if deviation exceeds threshold.

### Rule 4: Hysteresis / deadband

Do not send correction orders when native amount delta is below threshold:

- `max(min_amount_threshold, min_cost_threshold_converted)`.

## Data Requirements

For each fill/order statistic row (existing table already stores most values):

- timestamp
- pair_id
- price
- matched/native amount
- matched qty in base units
- resulting position after fill (or enough fields to reconstruct)
- side

A deterministic segment scan requires a monotonic sequence and post-trade position value.

## Materialized Anchor Columns (Recommended)

Yes, this is a strong optimization.

Add persisted columns to the position state (preferred) or to order/batch rows (acceptable):

- `avg_pos_price`
- `avg_pos_btc_price` (for quanto)
- `pos_qty`
- `segment_id` (or equivalent zero-cross marker)
- `anchor_updated_ts`

Then runtime reads anchor in O(1) and does not rescan trade history every cycle.

### Update timing

Update these fields only on fill events (including partial fills), not in timer loops.

### Incremental update formulas

Let current signed position be `Q`, current average price be `Pavg`, incoming fill be `dq` at `Pf`.

Case A: same-side increase (`sign(Q) == sign(dq)`):

$$
Pavg' = \frac{|Q|\cdot Pavg + |dq|\cdot Pf}{|Q| + |dq|}, \quad Q' = Q + dq
$$

Case B: reduce without flip (`sign(Q) != sign(dq)` and $|dq| < |Q|$):

- `Pavg' = Pavg`
- `Q' = Q + dq`

Case C: full close (`|dq| = |Q|`):

- `Q' = 0`
- `Pavg' = NULL` (or `0`, by storage convention)
- new segment starts on next non-zero fill

Case D: flip through zero (`|dq| > |Q|`):

- closing part uses old segment,
- residual open part starts new segment with:

$$
Q' = Q + dq, \quad Pavg' = Pf
$$

The same logic applies for `avg_pos_btc_price` on quanto instruments.

### Why this helps

- Eliminates repeated scanning/conversion work in every control cycle.
- Avoids drift from market-price-based recalculation between fills.
- Keeps report/runtime consistent if both read the same persisted anchor state.

### Practical caveat

Column on orders alone is not enough for fast reads if you still need "latest relevant row" lookup each loop. Best practical shape is a dedicated per-pair position-state row (materialized current state), updated atomically when fills are written.

## Proposed Shared Library

Target file (future extraction): [src/position_math.php](src/position_math.php)

### Scope

Single reusable module for both:

- runtime control math (target conversion, anti-chatter)
- reporting math (entry average, RPnL/UPnL segmentation)

### Suggested API

- `scan_segments(fills): array`
- `active_segment(fills): Segment`
- `entry_anchor(segment): Anchor`
- `convert_target_qty(pair, qty, anchor): amount`
- `detect_zero_cross(prev_pos, curr_pos): bool`
- `calc_rpnl(segment): float`
- `calc_upnl(segment, mark_price): float`

Where `Anchor` includes:

- `entry_price`
- `entry_btc_price` (for quanto)
- `ts_anchor`
- `source` (`fills`, `prev_session`, `fallback`)

## Migration Plan

1. Keep current `trading_math` behavior as safe baseline.
2. Extract report segment logic from [src/web-ui/trades_report.php](src/web-ui/trades_report.php) into shared primitives.
3. Implement `position_math.php` read-only calculator first.
4. Validate against historical reports and known trades.
5. Switch runtime anchor resolver from `last_batch_*` to segment-based `entry_anchor(...)`.
6. Add daily maintenance re-anchor + deadband guard.

## Validation Checklist

- Stable signal + moving price => no repetitive micro-corrections.
- New fill in same segment => anchor changes only once per fill update.
- Zero-cross => segment reset and new anchor.
- Report and runtime produce same entry anchor for identical fill sequence.
- Quanto pairs use consistent BTC reference in both runtime and reports.

## Notes on Current Limitations

- `last_batch_price(...)` can be insufficient when active position spans many batches beyond last directional slice.
- Previous-session fallback is useful for resiliency but should be secondary to segment scan.

## Summary

The canonical anchor for derivatives position control must be computed from full active segment history since last zero-cross. This is the central anti-chatter requirement and should be implemented once in shared `position_math` primitives reused by both trading and reporting paths.
