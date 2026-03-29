# TradeBot Wiki (Sanitized)

## Purpose
This wiki contains a sanitized technical map of the project for review, onboarding, and safe external sharing.

## Core Pages
1. [Review Cycle](./REVIEW_CYCLE.md) - repeatable code review workflow.
2. [Anonymization Policy](./ANONYMIZATION_POLICY.md) - rules for masking infrastructure and account data.
3. [Architecture Diagrams](./ARCHITECTURE_DIAGRAMS.md) - Mermaid diagrams of components and flows.

## Suggested Wiki Map (10 pages)
| Page | Goal | Key Sections |
|---|---|---|
| 1. Overview | Explain system boundaries | Goals, scope, glossary |
| 2. Component Map | Describe subcomponents | Runtime roles, ownership, dependencies |
| 3. Trading Signal Flow | Describe data path | Signal source, normalization, routing, execution |
| 4. API Contracts | Stable integration points | Endpoints, payload shapes, error model |
| 5. Runtime Configuration | Safe config model | Required vars, defaults, secret boundaries |
| 6. Review Cycle | Quality process | Gates, checks, acceptance criteria |
| 7. Testing Strategy | Confidence model | Smoke, regression, rollback checks |
| 8. Deployment Runbook | Release process | Preflight, deploy, post-check, rollback |
| 9. Incident Response | Operational recovery | Detection, triage, communication, restore |
| 10. Security and Sanitization | Data leak prevention | Masking dictionary, scan rules, publication checks |

## Definition of Done for Documentation Cycle
- All pages use anonymized placeholders (`HOST_CORE`, `SERVICE_API`, `ACCOUNT_A`, `EXCHANGE_X`).
- No raw IP/domain/account/token values in text, code snippets, or comments.
- Every architecture claim is traceable to actual code paths or runtime behavior.
- Mermaid diagrams render without syntax errors.
- Review cycle and anonymization checklist executed and signed off.
