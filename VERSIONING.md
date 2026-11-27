# Versioning and backward compatibility

Türkçe: [Sürüm politikası](https://www.cpalius.com/tr/docs)

This policy starts with **2.3.0**. In the 2.2.x series, patch releases also carried new features.

## Version numbers

`MAJOR.MINOR.PATCH`, for example `2.3.1`.

| Part | Raised for | Breaking changes |
|---|---|---|
| PATCH | Bug and security fixes | None |
| MINOR | New features | None; deprecations may be announced |
| MAJOR | Changes that break compatibility | Allowed, with an upgrade guide |

## What the compatibility promise covers

Within one MAJOR version these do not break:

- The module contract: `module.json`, the module folder layout, configuration and migration files under `Resources/`.
- The theme contract: `theme.json` and the Twig functions and filters the core provides.
- PHP interfaces, attributes, hooks and event names that the documentation describes as extension points.
- The REST API response format and the documented options of `cp:` commands.

Not covered: classes marked `@internal`, the HTML and CSS structure of the admin panels, undocumented classes and methods, direct table access.

**Exception:** if a security hole cannot be closed any other way, behaviour may change in a PATCH release. The release notes say so plainly.

## Deprecation

1. Something to be removed is announced in a MINOR release: release notes, documentation and a deprecation warning in development.
2. It keeps working for at least **6 months**.
3. It is removed only in the next MAJOR release.

## Support windows

| Line | Bug fixes | Security fixes |
|---|---|---|
| Current MINOR release | Yes | Yes |
| Previous MINOR release | No | 3 months after the new one |
| Last line of the previous MAJOR | No | 12 months after the new MAJOR |

No long-term support (LTS) line is promised for now.

## Reporting security problems

The reporting route and response times are in `SECURITY.md`.
