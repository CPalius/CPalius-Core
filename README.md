# CPalius CMF

**Next-generation Content Management Framework** built on PHP 8.2+ and Symfony 7.4 LTS.

CPalius sits between heavy CMS platforms and bare frameworks. You get authentication, access control, file management, localization, an admin panel, and a modular extension system — without rebuilding them for every project, and without inheriting a decade of CMS technical debt.

Website: [cpalius.com](https://www.cpalius.com)

---

## Why CPalius?

| Approach | Problem |
|----------|---------|
| Classic CMS (WordPress, Drupal) | Rich plugins, but heavy schemas, debt, and security surface |
| Pure frameworks (Symfony, Laravel) | Clean, but you rebuild auth, ACL, media, and admin every time |
| **CPalius** | Symfony-grade core + ready enterprise building blocks + safe modules |

Use it for blogs and community sites today, or as the base for dealerships, travel agencies, CRM, and ERP-style apps tomorrow.

---

## Requirements

- PHP 8.2 or newer
- Composer 2
- SQLite, MySQL, or PostgreSQL
- A web server pointing at the `public/` directory

No Node.js is required for the core or admin UI (AssetMapper + standalone Tailwind).

---

## Quick start

```bash
composer install
cp .env .env.local   # configure DATABASE_URL, APP_SECRET, tokens
php cp-core/bin/console doctrine:migrations:migrate
php cp-core/bin/console cp:user:create-admin
```

Point your vhost document root to `public/`, then open the site.

Useful commands:

```bash
php cp-core/bin/console cp:module:list
php cp-core/bin/console cp:module:activate ModuleName
php cp-core/bin/console cp:cron:run
php cp-core/bin/console cp:blog:seed-demo-content
```

---

## Project layout

```
CPalius/
├── cp-core/        # Kernel, config, migrations, App\ namespace
├── cp-includes/    # Composer vendor (isolated)
├── cp-content/     # Your space: modules, themes, sync config, translations
├── public/         # Web root (front controller + assets)
└── composer.json
```

Namespaces:

- `App\` → `cp-core/src/`
- `Modules\` → `cp-content/modules/`

Developer code stays in `cp-content/`. Framework system files stay in `cp-core/`.

---

## Core Never Dies

Broken third-party code must not take the whole app down. CPalius treats the core like an OS:

1. **Static module list** — Active modules are written to `active_modules.php`. Boot never queries the database for module status.
2. **Runtime quarantine** — Module boot/route/hook/API failures are caught; the core and AACP keep running.
3. **Pre-activation checks** — Before enabling a module, an isolated process runs container/YAML lint. Failures cancel activation.
4. **Recovery console** — `/aacp/recovery` can stay available even when the database is down (token from `.env`).

Faulty modules are logged to the quarantine log and can be reviewed in AACP.

---

## What you get out of the box

### Content & business data

- **Nodes** — Pages, posts, and similar content: SQL columns for title, slug, status, locale + flexible JSON `data`
- **Flat field index** — Queryable JSON fields indexed for fast filters (SQLite / MySQL / PostgreSQL)
- **Localization** — Built into the core (`UNIQUE(slug, locale)`, translation groups)
- **Resources** — Business records via `#[CpResource]` (capabilities, multi-tenant flags, workflow hooks — infrastructure ready)

### Admin surfaces

- **Studio (`/admin`)** — Content operations: posts, media, menus, forum, homepage portal
- **AACP (`/aacp`)** — System console: modules, plugins, cron, hooks, API keys, metrics, localization, cache rebuild, quarantine, recovery

### Extensibility

| Layer | How |
|-------|-----|
| Modules | Independent Symfony bundles under `cp-content/modules/` |
| REST API | `#[CpApi]` methods → `/api/...` with `X-CP-API-KEY` |
| Hooks | Flat-file `Hooks/` and/or `#[CpHook]` services |
| Cron | DB jobs, `#[CpCronJob]`, and hook files — one runner |
| Plugins | Optional sub-features toggled without disabling the whole module |
| Settings | `#[CpSetting]` definitions, loaded lazily from the database |

### Security & performance

- Capability-based access control (not `ROLE_ADMIN` checks in app code)
- Roles as YAML in `cp-content/config/sync/` (travel with Git); users stay in the database
- Query scoping: `.own` / `.any` capabilities become SQL `WHERE` clauses
- Rich-text XSS sanitization at save time
- Dev-mode N+1 query guard
- Tenant SQL filter infrastructure for SaaS-style isolation

---

## Included modules

| Module | Purpose |
|--------|---------|
| **Blog** | Posts on Node, categories/tags, scheduled publish, public API, hooks & plugins |
| **Forum** | Sections, topics, posts, likes, reports, bans, ranks, moderation |
| **Media** | Asset library and image picker (hash-based storage) |
| **Menu** | Named menus and items for the frontend |

Default theme: `cpalius-website` (portal, blog, and forum UI; `tr` / `en`).

---

## Roadmap

- Workflow / state machine for business records
- On-demand image derivatives (Imagine-style pipeline)
- First concrete `#[CpResource]` example + audit log
- Messenger transports for email and bulk jobs
- Theme manager UI in AACP

See [cpalius.com](https://www.cpalius.com) for the full product story and [CPALIUS_MANIFESTO.md](./CPALIUS_MANIFESTO.md) for architectural laws.

---

## Contributing

Ideas, issues, and pull requests that respect the manifesto (especially **Core Never Dies** and the `cp-core` / `cp-content` split) are welcome.

---

## License

See `composer.json` / the `LICENSE` file in this repository for the current license terms.

---

**CPalius CMF** — a project of [MEGABRE](https://www.cpalius.com) · Founder: Ali Çömez ([slaweally](https://github.com/slaweally))

[Türkçe README](./README.tr.md)
