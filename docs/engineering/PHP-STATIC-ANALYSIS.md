# PaxofiCloud PHP Static-Analysis Baseline

## Purpose

This document defines the deterministic PHP static-analysis baseline for PaxofiCloud and the Paxofi Core Framework (PCF).

## Current baseline

- PHP CI baseline: 8.4 (required `Quality baseline` job); 8.5 compatibility job runs PHPUnit
- PHPStan: `phpstan/phpstan` ^2.1 from `composer.lock` (run as `vendor/bin/phpstan`)
- PHPStan analysis level: **max**
- Analysis PHP version: 8.4 (`80400`)
- Configuration: `phpstan.neon.dist`
- Governed boundaries: `src`, `tests`
- Required GitHub gate: `Quality baseline`

## Execution policy

1. Every pull request targeting `main` executes the `Quality baseline` workflow.
2. The workflow lints PHP syntax, validates Composer, installs locked dependencies, runs PHPUnit, PHPStan and `composer audit`.
3. No PHPStan baseline/suppression file is permitted. Findings are fixed, not hidden.
4. Tests must be real PHPUnit tests (`extends TestCase`); they cover happy path, failure and security-negative cases.

## Scope discipline

The static-analysis baseline does not authorize production implementation. Production implementation remains subject to Gate 3 approval and all applicable product, architecture, security, testing, tenant-isolation, financial-integrity, operational and release controls.

## Evolution policy

The PHPStan version may be upgraded through a controlled engineering change after compatibility verification. Tool upgrades must not silently weaken the configured analysis level or remove the required CI gate.
