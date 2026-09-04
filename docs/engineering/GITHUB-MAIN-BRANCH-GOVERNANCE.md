# PaxofiCloud GitHub Main-Branch Governance

## Purpose

This document defines the intended GitHub enforcement baseline for the PaxofiCloud `main` branch. GitHub is the source-control, CI/CD, pull-request and engineering-evidence system; Notion remains authoritative for product specifications and ClickUp remains authoritative for delivery execution.

## Current repository posture

- Repository: `Paxofi-Technologies/paxofi-cloud`
- Visibility: public
- Default branch: `main`
- Current ruleset: `PaxofiCloud Main Branch Protection`
- Current ruleset enforcement: active
- Current ruleset target: default branch

## Required main-branch policy

The active ruleset should enforce:

1. Require a pull request before merging.
2. Require at least 1 approving review.
3. Dismiss stale approvals when new commits are pushed.
4. Require approval of the most recent reviewable push.
5. Require conversation/thread resolution.
6. Require the configured CI and quality status checks to pass.
7. Require branches to be up to date before merging (strict status-check policy).
8. Block force pushes.
9. Restrict branch deletion.
10. Allow squash merging as the governed default merge method.
11. Do not grant routine bypass access.

The current ruleset already enforces pull requests, deletion protection, force-push protection, thread resolution, extra approval for unattributed changes, and squash-only merging. It still needs the review count and required status checks completed before it is considered fully aligned with this baseline.

## Required status checks

Use the exact job names shown by GitHub Actions:

- `Repository governance checks`
- `Engineering baseline validation`
- `Quality baseline`
- `Analyze (javascript-typescript)`
- `Analyze (actions)`
- `Dependency Review`

Do not add a status check until that check has produced a recent result on a pull request; GitHub requires a real status/check context when configuring required status checks.

OpenSSF Scorecards is intentionally not a required PR check in this baseline because the current workflow is scheduled/mainline-oriented rather than a required pull-request gate. It remains an important supply-chain control and can be promoted to a required gate later if its trigger and operating model are deliberately changed.

## Rules that remain deliberately deferred

- Required signed commits: defer until contributor signing is operationally established.
- Required deployments: defer until a governed deployment environment exists.
- Required code-quality results: introduce after the PHP/PCF static-analysis baseline is implemented and stable.
- Code-owner review: introduce once the repository has a real CODEOWNERS ownership map.
- Organization-wide rulesets: revisit if/when GitHub Team or another paid plan is adopted.

## Free-plan and future Team posture

The current public repository can use GitHub Free branch protection/rulesets. A future move to GitHub Team should expand governance capability rather than cause the current controls to be removed.

When Team is later adopted, preserve this repository-level baseline and evaluate organization-wide rulesets, private-repository protection, broader security controls, team-based bypass/reviewer governance, and other paid-plan capabilities.

## Governance principle

No GitHub plan decision changes the PaxofiCloud product architecture, readiness gates, security model, or Definition of Done. Paid GitHub capabilities are governance and delivery-enablement improvements, not architectural dependencies.
