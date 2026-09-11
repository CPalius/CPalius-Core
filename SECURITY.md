# Security Policy

CPalius CMF ships hardening in the core rather than in optional plugins, so a
defect in this repository can directly affect every installation. Reports are
taken seriously and answered.

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.0.x   | ✅ Security fixes |
| < 1.0   | ❌ Pre-release, not supported |

## Reporting a vulnerability

**Do not open a public GitHub issue for a security defect.**

Use one of these channels instead:

1. **GitHub Security Advisories** — the *Security* tab of this repository →
   *Report a vulnerability*. This is preferred: the discussion stays private
   until a fix ships, and a CVE can be requested from the same place.
2. **E-mail** — `sys@rootali.net`

Please include, as far as you can determine them:

- affected version or commit,
- the component (for example `Core/Security/Service/IpMatcher`),
- a minimal reproduction,
- the impact you believe it has,
- whether the issue is already public anywhere.

## What to expect

| Stage | Target |
|-------|--------|
| Acknowledgement of your report | 72 hours |
| Initial assessment and severity | 7 days |
| Fix or mitigation for critical issues | 30 days |

Reporters are credited in the release notes unless they ask not to be. There
is no paid bounty programme at this time.

## Scope

In scope — anything that lets an attacker bypass a control the core claims to
provide:

- authentication, two-factor authentication, session handling,
- the capability system (CBAC), voters, per-record access grants,
- the tenant filter and query scoping,
- the perimeter layer: WAF, IP bans, flood limits, CSP and other headers,
- stored-secret handling and the password pipeline,
- injection of any kind (SQL/DQL, XSS, SSRF, path traversal, template),
- the module isolation boundary (a quarantined module reaching the core).

Out of scope:

- findings that require an already-compromised administrator account,
- missing hardening on a deliberately documented default — CPalius ships CSP
  in `report` mode and the WAF in `detect` mode on purpose, and
  `cp:security:audit` reports both as findings so the operator can tighten
  them,
- vulnerabilities in third-party dependencies (report those upstream; tell us
  if CPalius is affected),
- self-XSS, missing headers with no demonstrated impact, and automated scanner
  output without a working proof of concept.

## Verifying your own installation

The core ships a posture auditor. It inspects how requests are actually
handled, not merely which settings are stored:

```bash
php cp-core/bin/console cp:security:audit
php cp-core/bin/console cp:security:audit --json          # machine-readable
php cp-core/bin/console cp:security:audit --fail-on=high  # for CI
```

A default installation deliberately does not score 100/100. The audit is a
checklist for the operator, not a certificate.
