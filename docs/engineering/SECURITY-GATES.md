# CI security gates

Every pull request to `main` must pass these, in addition to PHPUnit, PHPStan
(level max), CodeQL and Dependency Review.

| Gate | Workflow / job | Fails when | Requirement |
|---|---|---|---|
| Secret scanning | `security.yml` → Secret scanning (gitleaks) | Any commit on any branch contains a credential | SEC-009 |
| SAST | `security.yml` → SAST (Semgrep) | A PaxofiCloud rule or a community PHP security rule matches, or fewer than 50 rules loaded | SEC-010, SEC-011 |
| Layering | `quality.yml` → PHPUnit (`LayeringTest`) | `src/Domain` imports anything outside the domain, or `src/Application` uses concrete PCF or outer-layer classes | NFR-011, ADR-001 |

## PaxofiCloud Semgrep rules (`.semgrep/paxoficloud.yaml`)

| Rule | Blocks | Why |
|---|---|---|
| `pc-shell-execution` | `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen` | SRS SEC-011 |
| `pc-dynamic-code` | `eval`, `create_function` | PCF invariant |
| `pc-unserialize` | `unserialize` | Object injection |
| `pc-password-hash-not-argon2id` | `password_hash` with anything but `PASSWORD_ARGON2ID` | IAM-004, threat model D-3 |
| `pc-insecure-random` | `rand`, `mt_rand`, `uniqid`, `lcg_value` | Threat model D-1 |
| `pc-weak-hash` | `md5`, `sha1`, `crc32` | Weak digests |
| `pc-tls-verification-disabled` | `CURLOPT_SSL_VERIFYPEER/HOST` off | Provider calls must verify TLS |
| `pc-debug-output` | `var_dump`, `phpinfo`, `print_r($x)` | Information leakage |
| `pc-sql-concatenation` | SQL built from variables or `sprintf` passed to the DB | SEC-011; constants like `self::TABLE` are fine |

Each rule has positive and negative cases in `.semgrep/paxoficloud.php`; CI
runs `semgrep scan --test .semgrep/` before scanning. Add a test case with
every rule change.

## Running locally

```bash
python3 -m pip install semgrep==1.179.0
git clone --depth 1 https://github.com/semgrep/semgrep-rules.git /tmp/semgrep-rules
semgrep scan --test --metrics=off .semgrep/
semgrep scan --metrics=off --error --config .semgrep/paxoficloud.yaml --config /tmp/semgrep-rules/php .

gitleaks git --redact --log-opts="--all" .     # https://github.com/gitleaks/gitleaks
```

## When a gate fires

- **Secret found:** treat the secret as compromised. Rotate it at the
  provider first, then remove it from history. Deleting the line in a new
  commit is not enough, because history keeps it. Tell the CEO.
- **Semgrep finding:** fix the code. If it is a genuine false positive, add
  `// nosemgrep` on that line **with a comment explaining why**, so the
  exception is reviewed in the PR. Never add paths to `.semgrepignore` to
  silence application code.
- **Layering failure:** move the dependency behind a port in
  `src/Application/Contracts` and implement it in `src/Infrastructure`.

Versions are pinned (gitleaks binary verified by SHA-256; Semgrep and the
community rules commit pinned in `security.yml`). Bump them deliberately in
their own PR.
