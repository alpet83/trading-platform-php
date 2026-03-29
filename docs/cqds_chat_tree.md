# CQDS Chat Tree For Trading Platform PHP

Date: 2026-03-28
Project: P:\opt\docker\trading-platform-php

## Purpose

This tree is meant to keep the migration work split by concern so CQDS chats stay focused and searchable.
The root chat should hold architecture decisions, while child chats execute and document narrow workstreams.

## Recommended tree

1. Root: `trading-platform-php migration`
   - scope: global decisions, sequencing, blockers, acceptance criteria
   - outputs: approved phase ordering, cross-cutting constraints, handoff notes

2. Child: `infra compose bootstrap`
   - scope: `docker-compose.yml`, `Dockerfile`, `.env.example`, volume layout, service naming
   - outputs: minimal bootable stack with `php-apache`, `php-bot`, `mariadb`

3. Child: `php runtime normalization`
   - scope: working directories, writable paths, `/tmp/<bot>` replacements, log locations, shell wrappers
   - outputs: deterministic runtime paths and reduced host-specific assumptions

4. Child: `web-ui and http api`
   - scope: Apache docroot, includes, API entrypoints under `src/web-ui/api`, health checks
   - outputs: browser UI and at least one verified API route inside containers

5. Child: `bot instance lifecycle`
   - scope: launching `bot_instance.php`, exchange-specific commands, restart policy, activity/log verification
   - outputs: one bot instance running cleanly in compose

6. Child: `db and schema bootstrap`
   - scope: MariaDB initialization, required schemas, seed data, migration notes, replication assumptions to drop or retain
   - outputs: reproducible DB startup for local development

7. Child: `config and secrets extraction`
   - scope: environment variables, extracted config files, secret placeholders, host-to-container config mapping
   - outputs: documented config contract for fresh deployments

8. Child: `tests and safety rails`
   - scope: smoke tests, unit-test triage, regression checks, startup validation scripts
   - outputs: minimum validation set for each phase

9. Child: `optional mcp integration`
   - scope: role of `mcp_server.py`, sandbox-only use, future external-IP switching in CQDS `mcp-tool`
   - outputs: decision on whether MCP stays test-only or becomes an optional helper service later

## Operating rule

Use the root chat for decisions only.
Do implementation and evidence gathering in the child chat that owns the concern.
When a child chat changes architecture or sequencing, summarize the decision back into the root chat.

Tool choice rule:
1. For this project, prefer CQDS `cq_*` functions first, including index extraction and DB/log inspection.
2. Use Sandwich-pack tooling mainly for codebases not included in CQDS.
3. Treat Sandwich-pack here as a fallback path.

## Suggested immediate usage

Start work in this order:
1. `infra compose bootstrap`
2. `php runtime normalization`
3. `config and secrets extraction`
4. `web-ui and http api`
5. `bot instance lifecycle`

Leave `optional mcp integration` dormant until the base containers are stable.