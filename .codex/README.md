# Project-local Codex policy

## Purpose

This directory defines the trusted project configuration and command policy for
the V2 Platform repository. It narrows local execution without replacing
`AGENTS.md`, GitHub task policies, repository rulesets, or release gates.

## Trust boundary

- `/var/www/oripa` must already be a trusted Codex project.
- Trust must not be broadened automatically to unrelated directories.
- The approved root execution exception remains in effect. It does not permit
  secret output, repository storage of credentials, or direct broker use.
- Project-local configuration is ignored when the project is not trusted.

## Configuration

`.codex/config.toml` targets the installed Codex CLI schema validated by
`codex --strict-config`:

- model `gpt-5.6` with high reasoning effort;
- `workspace-write` sandboxing;
- `on-request` approval with automatic approval review;
- cached web search;
- no sandbox network access;
- no additional write access through `/tmp` or `$TMPDIR`.

The default must not be changed to `danger-full-access`, approval `never`, or
an equivalent unrestricted mode.

## Command decisions

`.codex/rules/governance.rules` uses official `prefix_rule` syntax:

- `forbidden` blocks destructive Git, protected-ref changes, destructive Docker
  and database operations, and direct credential-broker execution;
- `prompt` routes mutating, dependency, container, migration-creation, and
  network commands through automatic review;
- `allow` covers read-only inspection, verification, and the approved GitHub
  App wrappers.

An `allow` decision does not authorize reading secrets, production data, or PII.
The most restrictive matching rule wins. Shell wrappers are blocked outside the
sandbox so they cannot hide a dangerous operation; escalation must invoke the
intended executable directly.

## GitHub App wrappers

Allowed entry points:

- `/usr/local/bin/oripa-github-app-api`
- `/usr/local/bin/oripa-github-app-api-write`
- `/usr/local/bin/oripa-github-app-git`

Direct execution is forbidden for:

- `/usr/local/libexec/ideal-sol-github-app-token`
- `/usr/local/libexec/ideal-sol-github-app-autonomy`

The allowed wrappers remain subject to their root-owned Task Policy, repository,
branch, operation, expected-SHA, and fast-forward checks.

## Verification

Use the installed CLI without adding dependencies:

```text
codex --strict-config doctor --json
codex execpolicy check --pretty --rules .codex/rules/governance.rules -- <command>
```

Validation must include allow, prompt, forbidden, compound-command, shell
wrapper, direct-main-push, force-push, package, migration, Docker, broker, and
approved-wrapper cases. A syntax error or unintended allow blocks merge.
