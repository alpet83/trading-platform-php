# Review Cycle (Sanitized)

## Goal
Provide a repeatable review loop after structural cleanup, with special focus on broken references and regressions.

## Inputs
- Current repository tree.
- Previous baseline (if available).
- Runtime startup logs and smoke test outputs.

## 8-Step Cycle
1. Inventory current structure.
- Build a tree snapshot and identify deleted/moved modules.
- Record active vs legacy component groups.

2. Dependency/link consistency.
- Validate includes/imports and script paths.
- Flag references to removed files.

3. API and route integrity.
- Enumerate public API routes.
- Compare with expected contracts and docs.

4. Runtime configuration sanity.
- Validate required env keys and fallback behavior.
- Ensure secrets are not hardcoded.

5. Trading path smoke checks.
- Verify signal intake -> normalization -> order dispatch path.
- Verify account-scoped behavior with placeholders only.

6. Test pass and gap analysis.
- Run available tests and classify gaps.
- Add minimal regression tests for touched paths.

7. Documentation sync.
- Update architecture, runbook, and API docs.
- Link each changed behavior to concrete file ownership.

8. Release readiness gate.
- Confirm rollback path and post-deploy probes.
- Approve only if no high severity unresolved findings.

## Findings Format
Use this schema for each finding:

`severity | file | issue | impact | fix | status`

Where `severity` is one of: `critical`, `high`, `medium`, `low`.

## First Iteration Plan (Safe Minimum)
1. Fix startup blockers (missing include/import, fatal path errors).
2. Fix externally visible regressions (API routes/handlers).
3. Fix script/runbook drift (deploy and migration commands).
4. Add 3-5 regression checks for fixed paths.
5. Update wiki pages in this folder.
