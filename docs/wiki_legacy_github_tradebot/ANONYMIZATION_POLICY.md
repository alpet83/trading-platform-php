# Anonymization Policy

## Objective
Prevent leakage of real infrastructure and trading identities in code comments, docs, screenshots, and diagrams.

## Sensitive Data Classes
- Public and private hostnames, domains, and IP addresses.
- Service URLs and internal ports.
- Account numbers, wallet IDs, exchange user IDs.
- API keys, tokens, secrets, webhook endpoints.
- Personal names and contact details.
- Environment names revealing internal topology.

## Masking Dictionary
| Sensitive class | Replace with |
|---|---|
| Core backend host | `HOST_CORE` |
| API gateway host | `HOST_API` |
| Frontend host | `HOST_WEB` |
| Internal service URL | `SERVICE_API` |
| Exchange name | `EXCHANGE_X` |
| Trading account | `ACCOUNT_A` |
| Telegram/user identity | `USER_ALIAS_A` |
| Secret/token/key | `SECRET_TOKEN_A` |
| Port values (public docs) | `PORT_X` |

## Safe Editing Rules
1. Docs and comments: always mask sensitive values.
2. Runtime configs: do not blindly replace production values in executable files.
3. Examples/snippets: use synthetic values only.
4. Logs for docs: redact before pasting.
5. Keep a private mapping file outside repository when reverse mapping is required.

## Repository Scan Patterns
Use regex scans before publication:

- IPv4: `\b(?:\d{1,3}\.){3}\d{1,3}\b`
- URL: `https?://[^\s"'<>]+`
- Host-like tokens: `\b[a-zA-Z0-9.-]+\.(local|lan|internal|corp|vpn|com|net|org)\b`
- Secret-like keys: `(?i)(token|secret|apikey|api_key|password)\s*[:=]\s*["'][^"']+["']`
- Account-like IDs: `(?i)(account|wallet|uid|user_id|chat_id)[\s:=#-]*[0-9]{4,}`

## Final Validation Checklist
- No unmasked hosts/IPs in docs/wiki files.
- No real account identifiers in docs/comments.
- No secrets in examples or snippets.
- Mermaid diagrams include placeholders only.
- Peer review confirms masking policy compliance.
