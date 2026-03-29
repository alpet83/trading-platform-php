# Sanitization Backlog (Priority-Based)

## Scope
This backlog tracks files requiring anonymization of infrastructure and trading-account related data in comments, docs, and shareable examples.

## Priority P0 (immediate before publication)
- [admin_pos.php](../../admin_pos.php)
- [admin_trade.php](../../admin_trade.php)
- [bot_manager.php](../../bot_manager.php)
- [route_api.php](../../route_api.php)
- [last_pos.php](../../last_pos.php)
- [tech_doc.txt](../../tech_doc.txt)

Reason:
- Host allowlists and private network patterns.
- Hardcoded service endpoints in comments or runtime literals.
- Replication/network operation notes with environment-specific values.

## Priority P1 (next pass)
- [ext_signals.php](../../ext_signals.php)
- [pos_feed.php](../../pos_feed.php)
- [exec_report.php](../../exec_report.php)
- [trading_engine.php](../../trading_engine.php)
- [common.php](../../common.php)

Reason:
- External feed URLs and internal service references.
- Potential topology exposure through helper comments and fallback hosts.

## Priority P2 (security hygiene and normalization)
- [impl_binance.php](../../impl_binance.php)
- [impl_bitfinex.php](../../impl_bitfinex.php)
- [impl_bitmex.php](../../impl_bitmex.php)
- [impl_deribit.php](../../impl_deribit.php)
- [rest_api_common.php](../../rest_api_common.php)

Reason:
- Secret-like naming and auth/token handling snippets in logs/comments.
- Need consistent neutral examples in documentation excerpts.

## Editing Guardrails
1. In executable runtime config, avoid destructive blanket replacement.
2. In comments/docs/examples, replace values using dictionary in [ANONYMIZATION_POLICY.md](./ANONYMIZATION_POLICY.md).
3. Keep functional constants intact unless migration plan is approved.
4. Re-scan repository after each batch.

## Validation Gate
- Zero unmatched sensitive patterns in docs/wiki.
- Zero plain host/account literals in comments intended for sharing.
- Security reviewer sign-off.
