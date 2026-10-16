# CPalius CMF

**A modular application core for PHP 8.4 and Symfony 7.4 LTS.**

CPalius is not a classic CMS. It gives you the parts every serious web application needs: authentication, capability-based access control, content and business-record models, media, localization, an admin panel, a signed update channel and a module and theme system. Products such as a blog, a forum, a portal, a marketplace or a booking system are modules on top of it.

Website: [cpalius.com](https://www.cpalius.com) · Current stable release: **2.2.69** · [Türkçe](./README.tr.md)

---

## What is in this repository

This repository is the **core**, plus a small starter set so it runs on its own:

| Included | What |
|---|---|
| Core | Kernel, AACP admin panel, security, update system, migrations (`cp-core/`) |
| Modules | Blog, Forum, Media, Menu, Messages, Pages, Widget (`cp-content/modules/`) |
| Theme | `cpalius-website`, the default theme (`cp-content/themes/`) |

More modules and themes live in their own repositories:

- Modules: [CPalius/Modules](https://github.com/CPalius/Modules)
- Themes: [CPalius/Themes](https://github.com/CPalius/Themes)

---

## Requirements

- PHP 8.4 or newer
- MySQL 8+ or MariaDB 10.6+ (PostgreSQL and SQLite are not supported for installation yet; the shipped migrations use MySQL-specific DDL)
- A web server whose document root points at `public/` (Apache `.htaccess` is included)
- Composer 2 only when installing from source; the release zip already contains `cp-includes/vendor`

Node.js is not needed for the core or the admin UI.

---

## Install

**From the release zip:** upload it, open the site, and follow the setup wizard (requirements, empty database, site name, first administrator). The wizard removes itself when it finishes.

**From the command line:**

```bash
composer install
cp .env.example .env        # set DATABASE_URL and APP_SECRET
php cp-core/bin/console doctrine:migrations:migrate
php cp-core/bin/console cp:user:create-admin
```

If the host cannot point the document root at `public/`, the root `.htaccess` serves `public/` and blocks the rest of the tree.

Everyday commands:

```bash
php cp-core/bin/console cp:module:list
php cp-core/bin/console cp:module:activate <Name>
php cp-core/bin/console cp:cron:run
php cp-core/bin/console cp:doctor            # environment, security and performance checks
```

---

## Layout

```
cp-core/       Kernel, configuration, migrations, App\ namespace
cp-includes/   Composer vendor (isolated)
cp-content/    Your space: modules, themes, synced configuration, translations
public/        Web root
```

Namespaces: `App\` → `cp-core/src/`, `Modules\` → `cp-content/modules/`.

---

## Ideas behind it

- **The core never dies.** A module that fails at boot is quarantined; the core and the admin panel keep running. A database-free recovery console is available at `/aacp/recovery`.
- **Two data models.** *Node* for content (revisions, translations, workflow, fields). *Resource* for business records; mark a Doctrine entity with `#[CpResource]` and get list, form and permissions for free.
- **Capabilities, not roles.** Access is granted through named capabilities (`forum.post.edit.own`). Roles are YAML and travel with Git. Capabilities that could take over the site are flagged as restricted in the UI.
- **Configuration in code, settings in the panel.** `#[CpSetting]`, `#[CpAdminMenu]`, `#[CpHook]`, `#[CpApi]`, `#[CpCronJob]`.
- **Measured, not claimed.** Performance and security tooling ships with the product.

---

## Security

- bcrypt (cost 13) passwords with rehash on login, leak checks, login throttling, captcha, IP bans and a built-in WAF
- Two-factor authentication (TOTP, e-mail code, recovery codes) and step-up re-authentication for sensitive panel actions
- AES-256-GCM sealed secrets with zero-downtime key rotation (`cp:secrets:rotate`)
- CSP with nonces and a report endpoint, HSTS, trusted hosts; `cp:security:csp-suggest` turns violation reports into suggestions
- Upload checks (real MIME, double extensions, no execution); SVG upload is intentionally refused
- Ed25519-signed update manifests and extension index, sha256-verified packages, rollback attacks rejected, signed security advisories for extensions

Report vulnerabilities privately; see [SECURITY.md](./SECURITY.md).

---

## Performance

- Guest pages are stored as files and served by the web server without starting PHP, with stale-while-revalidate on purge
- Tag-based invalidation (about 3 ms per page)
- Tooling: `cp:perf:probe`, `cp:perf:routes`, `cp:doctor --only=performance`, and a development-mode N+1 query guard

Figures from a database with 100k users, 200k posts and 100k bookings are single-request timings, not concurrent load tests.

---

## Updates

Core updates are applied from the admin panel: signed manifest → download → sha256 check → atomic install → migrations → cache rebuild. Modules and themes are versioned separately through a signed extension catalogue.

---

## Documentation

[cpalius.com/tr/docs](https://www.cpalius.com/tr/docs) · [CPALIUS_MANIFESTO.md](./CPALIUS_MANIFESTO.md) for the architectural rules.

## License

Proprietary. All rights reserved. See [LICENSE](./LICENSE).

---

A project of [MEGABRE](https://www.cpalius.com) · Founder: Ali Çömez ([slaweally](https://github.com/slaweally))
