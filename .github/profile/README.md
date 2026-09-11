# CPalius

### Next-generation Content Management Framework

Built on **PHP 8.2+** & **Symfony 7.4 LTS** — the bridge between heavy CMS platforms and frameworks you rebuild from scratch.

<p align="center">
  <a href="https://www.cpalius.com"><img src="https://img.shields.io/badge/Website-cpalius.com-0f172a?style=for-the-badge" alt="Website"></a>
  &nbsp;
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  &nbsp;
  <img src="https://img.shields.io/badge/Symfony-7.4_LTS-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony">
</p>

---

## What we build

| | |
|:--|:--|
| **CPalius CMF** | Modular CMS + application platform — auth, ACL, media, i18n, admin included |
| **Core Never Dies** | Faulty modules quarantine; the core and recovery console stay up |
| **Studio + AACP** | Content ops (`/admin`) and system control (`/aacp`) |
| **Safe extensions** | Modules, hooks, REST API, cron, plugins — isolated by design |

---

## Stack at a glance

```
PHP 8.2+  ·  Symfony 7.4 LTS  ·  Doctrine ORM  ·  AssetMapper  ·  Tailwind
```

No Node.js required for core & admin. Modules live in `cp-content/` — the kernel stays clean in `cp-core/`.

---

## Start here

| Resource | Link |
|:---------|:-----|
| Product site | [cpalius.com](https://www.cpalius.com) |
| Framework repo | [CPalius-Core](https://github.com/CPalius/CPalius-Core) |
| Architecture laws | Manifesto inside the repo |
| Contact | [hello@megabre.com](mailto:hello@megabre.com) |

```bash
composer install
cp .env.example .env
php cp-core/bin/console doctrine:migrations:migrate
php cp-core/bin/console cp:user:create-admin
```

---

<p align="center">
  <strong>MEGABRE</strong> · Founder <a href="https://github.com/slaweally">Ali Çömez</a><br>
  <sub>Not another CMS. An application framework that happens to manage content.</sub>
</p>
