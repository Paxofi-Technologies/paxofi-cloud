# PaxofiCloud GitHub Main-Branch Governance

## Purpose

This document defines the enforced GitHub baseline for the PaxofiCloud `main` branch. GitHub is the source-control, CI/CD, pull-request and engineering-evidence system; Notion remains authoritative for product specifications and ClickUp remains authoritative for delivery execution.

## Current repository posture

- Repository: `Paxofi-Technologies/paxofi-cloud`
- Visibility: public
- Default branch: `main`
- Current ruleset: `PaxofiCloud Main Branch Protection`
- Current ruleset enforcement: active
- Current ruleset target: default branch
- Bypass actors: none

## Enforced main-branch policy

The active ruleset enforces:

1. Require a pull request before merging.
2. Require at least 1 approving review.
3. Dismiss stale approvals when new commits are pushed.
4. Require approval of the most recent reviewable push.
5. Require conversation/thread resolution.
6. Require the configured CI, quality, security and supply-chain status checks to pass.
7. Require branches to be up to date before merging through strict required-status-check policy.
8. Block force pushes/non-fast-forward updates.
9. Restrict branch deletion.
10. Allow squash merging as the governed merge method.
11. Do not grant routine bypass access.

## Required status checks

The active ruleset currently requires exactly these five check contexts:

- `Repository governance checks`
- `Engineering baseline validation`
- `Quality baseline`
- `Analyze (actions)`
- `Dependency Review`

The previously used generic `strict` status-check entry has been removed. The ruleset intentionally uses the actual executable GitHub Actions contexts rather than an unexplained placeholder check.

`Quality baseline` now includes the deterministic PHPStan analysis foundation. PHPStan is pinned to an exact tool version in the workflow, while the repository analysis policy is defined in `phpstan.neon.dist`. PHP analysis is intentionally deferred when no PHP source exists yet; once PHP/PCF source is introduced, the same required quality gate executes syntax validation and PHPStan analysis against the governed source/test boundaries.

OpenSSF Scorecards is intentionally not a required PR check in this baseline because its current workflow is scheduled/mainline-oriented rather than a required pull-request gate. It remains an important public-repository supply-chain control and can be promoted to a required gate later if its trigger and operating model are deliberately changed.

## Rules deliberately deferred

The following remain intentionally disabled until their prerequisites exist:

- Required signed commits: defer until contributor signing is operationally established.
- Required deployments: defer until a governed deployment environment exists.
- Required code-quality results as a separate GitHub ruleset control: the current `Quality baseline` already provides the required quality gate, including PHPStan; a separate code-quality rule would be redundant at this stage.
- Code-owner review: introduce once the repository has a real ownership map and operational reviewer coverage.
- Organization-wide rulesets: revisit if/when GitHub Team or another paid plan is adopted.

## Free-plan and future Team posture

The current public repository operates on the GitHub Free baseline. A future move to GitHub Team should expand governance capability rather than cause the current controls to be removed.

When Team is later adopted, preserve this repository-level baseline and evaluate organization-wide rulesets, private-repository protection, broader security controls, team-based bypass/reviewer governance, and other paid-plan capabilities.

## Governance principle

No GitHub plan decision changes the PaxofiCloud product architecture, readiness gates, security model, or Definition of Done. Paid GitHub capabilities are governance and delivery-enablement improvements, not architectural dependencies.
