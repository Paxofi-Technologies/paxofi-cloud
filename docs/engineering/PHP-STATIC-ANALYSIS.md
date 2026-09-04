# PaxofiCloud PHP Static-Analysis Baseline

## Purpose

This document defines the deterministic PHP static-analysis baseline for PaxofiCloud and the Paxofi Core Framework (PCF).

## Current baseline

- PHP CI baseline: 8.4
- PHPStan: 2.2.8, pinned exactly in GitHub Actions
- PHPStan analysis level: 8
- Analysis PHP version: 8.4 (`80400`)
- Configuration: `phpstan.neon.dist`
- Governed source boundary: `src`
- Governed test boundary: `tests`
- Required GitHub gate: `Quality baseline`

## Execution policy

1. Every pull request targeting `main` executes the `Quality baseline` workflow.
2. PHP files are syntax-checked with the configured PHP runtime.
3. PHPStan configuration is always validated.
4. PHPStan static analysis executes automatically whenever PHP source exists.
5. No PHP baseline suppression file is introduced at this stage. Findings must be fixed rather than hidden.
6. The current repository contains no production PHP implementation yet; therefore PHPStan execution is intentionally deferred until PHP source is introduced. This is a readiness control, not a production-code waiver.
7. Once PCF/PaxofiCloud PHP implementation begins, the same required quality check becomes the authoritative static-analysis gate for every pull request.

## Scope discipline

The static-analysis baseline does not authorize production implementation. Production implementation remains subject to Gate 3 approval and all applicable product, architecture, security, testing, tenant-isolation, financial-integrity, operational and release controls.

## Evolution policy

The PHPStan version may be upgraded through a controlled engineering change after compatibility verification. Tool upgrades must not silently weaken the configured analysis level or remove the required CI gate.
