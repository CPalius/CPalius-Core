# Contributing

## Report a bug

Give the CPalius and PHP versions, the steps, what you expected and what happened. Never paste `.env`, keys or personal data. Security problems go through [SECURITY.md](SECURITY.md), not public issues.

## Propose a change

- Open an issue before large work.
- PHP 8.4, `declare(strict_types=1)`, typed properties. Match the code around you.
- Keep features that not every site needs in a module under `cp-content/modules/`, not in the core.
- Add a test for logic you add or fix. CI runs lint and the PHPUnit suites.
- Update the documentation when behaviour changes.
- Follow the deprecation rules in [VERSIONING.md](VERSIONING.md).
