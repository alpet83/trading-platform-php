# Trading Risk Controls and Limit Settings

## Scope

This document explains how trade size and execution aggressiveness are constrained by runtime risk settings.

The logic described here is engine-agnostic and applies to all exchange adapters integrated via the common trading loop.

Primary audience:
- operators tuning account limits
- developers debugging signal execution loops

## Observed Runtime Pattern

From runtime logs:
- repeated warnings about many non-compensated orders for one external signal
- amount repeatedly clamped near 0.001 BTC
- trade direction occasionally skipped as opposite to signal direction

Representative markers:
- WARN(CurrentDeltaPos): too many non-compensated orders
- SIGNAL_SKIP: opposite tradeable delta bias
- EXT_SIG_ADJUST: adjusted amount to 0.001
- Amount limit:max_abs with limit around 0.00149

## Why Size Drops to 0.001

The size is constrained by multiple layers:

1. Cost cap -> max amount conversion
- Trading loop computes cost_limit and max_amount from max_order_cost and price.
- Then TradingContext amount variable is constrained by Amount limit (max_abs).

Relevant code:
- src/trading_loop.php, SetupLimits
  - cost_limit and max_cost calculation
  - amount constrain max_abs via ctx.max_amount(...)
- src/trading_context.php, TradingContext::max_amount
  - converts cost_limit to max_qty/max_amount
- src/lib/smart_vars.php, BoundedValue::set
  - max_abs constraint enforcement

2. External signal overload behavior
- With many non-compensated orders, external signal path keeps cleanup/adjust logic active.
- Close amount can be adjusted to very small values.

Relevant code:
- src/ext_signals.php
  - CurrentDeltaPos / overload warnings
  - AdjustCloseAmount
- src/trading_loop.php
  - EXT_SIG_ADJUST path

3. Exchange lot/step formatting
- Exchange symbol filters load min lot and qty step constraints.
- Final order qty is formatted to exchange precision/step.

Relevant code:
- src/impl_*.php loaders
- src/trading_engine.php and engine-specific NewOrder paths

## Practical Meaning of the 0.001 Loop

In one observed case, amount limit around 0.00149 was visible. For BTCUSDT near ~67k, this corresponds to roughly 100 USD max order cost:

- qty_limit ~= max_order_cost / price ~= 100 / 66964 ~= 0.00149
- formatted execution step can result in 0.001

So the 0.001 behavior is expected when:
- max_order_cost is low relative to instrument price
- external signal has many pending/non-compensated orders
- correction logic keeps trying to reduce residual bias

## Settings That Control Risk and Size

## Account-level (bot config table)

These are the main operator knobs:
- position_coef
  - scales target position from source signal
  - lower value reduces aggressiveness globally
- min_order_cost
  - lower bound for trade relevance; too low allows tiny corrective trades
- max_order_cost
  - hard cap for single order size; directly limits max amount

Exposed in admin UI:
- src/web-ui/basic-admin.php

Loaded into runtime config:
- src/trade_config.php

## Pair-level (pairs map)

- trade_coef
  - per-symbol multiplier applied with position_coef
  - affects final target amount for each pair

Source/loading path:
- src/pos_feed.php (pair map loading)
- src/trading_engine.php (ticker trade_coef assignment)

## Global/advanced limits

- max_pos_cost
  - total position cap logic in trading loop
- max_lazy_order_cost
  - lower cap when insufficient funds / constrained mode
- ti.cost_limit and liquidity-specific caps
  - additional narrowing of cost_limit in SetupLimits

Main logic:
- src/trading_loop.php, SetupLimits

## Risk Control Playbook

If a bot is stuck around micro-sized repetitive corrections:

1. Confirm signal overload state
- Check WARN(CurrentDeltaPos) counts and order backlog per signal.
- If one signal has large stale order history, resolve backlog first.

2. Verify max_order_cost vs instrument price
- Compute expected max qty: max_order_cost / current_price.
- If value is around 0.001-0.002, tiny orders are expected.

3. Keep min_order_cost coherent with max_order_cost
- Do not keep min_order_cost close to or above practical order size while max_order_cost remains very low.
- Maintain a sensible gap where max_order_cost allows meaningful correction steps.

4. Validate pair multipliers
- Check position_coef and pair trade_coef product.
- Unexpectedly low product can make target changes too granular.

5. Reduce stale-order pressure
- Investigate repeated not found in cache events for same signal orders.
- If needed, perform controlled cleanup for overloaded external signal state.

6. Verify direction conflict behavior
- Repeated SIGNAL_SKIP with opposite bias means the engine intentionally blocks opposite-direction corrections.
- This is usually a symptom of stale/open order backlog and should be handled before relaxing limits.

## Suggested Baseline for Stable Behavior

Use as starting point, then tune by volatility and liquidity:
- position_coef: conservative and explicitly reviewed per account
- max_order_cost: large enough to avoid permanent micro-step behavior on high-price instruments
- min_order_cost: not too permissive for noise, but below normal corrective order cost

Do not tune these blindly in isolation. Always evaluate together:
- position_coef
- trade_coef
- min_order_cost
- max_order_cost
- current price regime
- external signal backlog health

## Fast Checklist Before Production Changes

1. No active overload warning storms for the same signal.
2. Computed max qty from max_order_cost is operationally meaningful.
3. min_order_cost and max_order_cost are internally consistent.
4. position_coef x trade_coef reviewed for each active pair.
5. Dry observation window after changes shows reduced micro-order churn.

## Admin UI Controls

Basic Admin exposes the main risk controls used by the trading loop:
- position_coef
- min_order_cost
- max_order_cost
- max_pos_cost
- max_lazy_order_cost
- hidden_cost_threshold

Location:
- src/web-ui/basic-admin.php

Recommended baseline:
- max_order_cost default baseline should be at least 5000 for general runtime safety, then tuned per account/instrument.

## Appendix: Useful Log Indicators

Look for these markers in core logs:
- LOAD_PAIRS_MAP with trade_coef values
- WARN(CurrentDeltaPos): too many non-compensated orders
- SIGNAL_SKIP and BIAS_DBG blocks
- EXT_SIG_ADJUST adjusted amount lines
- NEW_ORDER_BLOCK / TRADE(total) cadence
