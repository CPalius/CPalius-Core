# ==============================================================================
# ================================================================================
#  ██████╗██████╗  █████╗ ██╗     ██╗██╗   ██╗███████╗    ██████╗███╗   ███╗███████╗
# ██╔════╝██╔══██╗██╔══██╗██║     ██║██║   ██║██╔════╝   ██╔════╝████╗ ████║██╔════╝
# ██║     ██████╔╝███████║██║     ██║██║   ██║███████╗   ██║     ██╔████╔██║█████╗  
# ██║     ██╔═══╝ ██╔══██║██║     ██║██║   ██║╚════██║   ██║     ██║╚██╔╝██║██╔══╝  
# ╚██████╗██║     ██║  ██║███████╗██║╚██████╔╝███████║   ╚██████╗██║ ╚═╝ ██║██║     
#  ╚══════╝╚═╝     ╚═╝  ╚═╝╚══════╝╚═╝ ╚═════╝ ╚══════╝    ╚═════╝╚═╝     ╚═╝╚═╝     
# ================================================================================         
#                 CONTENT MANAGEMENT FRAMEWORK (v1.0.0)
# ==============================================================================

CRITICAL NOTICE FOR AI DEVELOPERS (CLAUDE, GPT, CURSOR)
    You are the Lead Architect of the CPalius Enterprise Application Framework. Before generating, modifying, or suggesting any code, you MUST strictly adhere to the core principles, directory layouts, and architectural constraints defined in this document. Any code generation that violates these constraints is considered a regression and will be rejected.

1. Directory Layout & Namespace Constraints

CPalius enforces a strict "Pristine Root" policy. The project root must remain completely clean. No framework pollution is allowed in the root directory.
Plaintext

CPalius/ (Root)
├── cp-core/                        # KERNEL SPACE (System configuration, Core Src, Console)
│   ├── bin/                        # Console binary
│   ├── config/                     # Core configs (bundles.php, active_modules.php, routes.yaml)
│   ├── migrations/                 # Core DB migrations (tracked by Git)
│   ├── src/                        # Core App Namespace (App\Core, App\Entity, etc.)
│   └── var/                        # Cache, logs, and SQLite database (gitignored)
├── cp-includes/                    # DEPENDENCIES
│   └── vendor/                     # Composer autoloader and vendor packages
├── cp-content/                     # USER & DEVELOPER SPACE
│   ├── config/                     # Config Sync (YAML definitions exported/imported via Git)
│   ├── modules/                    # Isolated CMF/ERP Plugins/Modules
│   ├── themes/                     # Independent frontend themes
│   └── translations/               # Global interface translations (tr, en)
├── public/                         # WEB ROOT (Single entry point)
│   ├── index.php                   # Front controller
│   └── assets/                     # Symlinked or compiled asset outputs

Namespace Mapping (PSR-4):

    App\ maps to cp-core/src/

    Modules\ maps to cp-content/modules/

    DoctrineMigrations\ maps to cp-core/migrations/

2. Core Preservation & Recovery Laws ("Core Never Dies")

CPalius operates like an Operating System (OS). If a user-space module (cp-content/modules/*) crashes, throws a syntax error, or fails to boot, the Core and AACP (Admin Control Panel) MUST remain alive.
Law 2.1: Dynamic & Safe Booting (The active_modules file)

To prevent boot-time deadlocks, config/bundles.php MUST NEVER connect to the database or rely on Symfony's service container.

    Active modules are registered as static class strings in cp-core/config/active_modules.php.

    bundles.php only reads this lightweight file using standard PHP class checks.

Law 2.2: Compile-Time Protection (Pre-activation Linting)

Before a module can be added to active_modules.php via CLI (cp:module:activate), the activator command MUST execute an isolated dry-run sub-process verifying lint:container and lint:yaml. If compilation fails, the activation is rejected, keeping the core safe from compile-time deadlocks.
Law 2.3: Safe Mode & Recovery Console

AACP (/aacp) must be rendered with zero dependencies on custom modules or themes. If all modules are quarantined, /aacp must still render. A recovery token defined in .env (/aacp/recovery?token=...) boots a minimal kernel allowing developers to deactivate broken modules and clear cache from the web UI without SSH access.
3. The Two Classes of Entities (Content vs. Business)

We reject the anti-pattern of "everything is a node" (over-abstraction). We divide our domain model into two distinct classes:
Plaintext

               ┌──────────────────────────┐
               │    CPalius Framework     │
               └─────────────┬────────────┘
                             │
              ┌──────────────┴──────────────┐
              ▼                             ▼
   ┌────────────────────┐        ┌────────────────────┐
   │  Content Entities  │        │  Business Records  │
   │       (Node)       │        │     (Resource)     │
   ├────────────────────┤        ├────────────────────┤
   │ - Slug / Locale    │        │ - No Slug / Locale │
   │ - Multilingual     │        │ - State Machine    │
   │ - SEO & Revision   │        │ - Immutable Logs   │
   │ - Core: data (JSON)│        │ - Core: SQL Column │
   └────────────────────┘        └────────────────────┘

Law 3.1: Content Entities (Node)

    Represents blog posts, landing pages, portfolio items.

    Employs a Hybrid Content Model: Squeezes dynamic/custom fields into a single json column called data, while keeping structural metadata (id, slug, status, locale) as real SQL columns.

Law 3.2: Business Records (Resource)

    Represents ERP/CRM records (vehicles, invoices, reservations, staff).

    Uses real SQL columns for almost all properties to allow strict types, reporting, and mathematical aggregates. No schema alteration at runtime.

4. The #[CpResource] Engine & Automation

To avoid writing repetitive CRUD controllers, forms, and permission checks, CPalius uses a metadata-driven architecture.
Law 4.1: The Resource Contract

Every business record or entity that wants platform-level support (Automatic UI, Audit Logs, API endpoints) must be annotated with the #[CpResource] attribute:
PHP

#[CpResource(
    name: 'vehicle',
    module: 'oto-galeri',
    capabilities: ['create', 'edit', 'delete', 'view'],
    auditable: true,
    multiTenant: true,
    workflow: 'vehicle_lifecycle'
)]

Law 4.2: Dynamic Capability Mapping

The CapabilityRegistry must automatically parse #[CpResource] attributes at compiler time and register corresponding capabilities (e.g., vehicle.create, vehicle.edit) into the security system. Geliştirici manual yetki tanımlamak zorunda kalmamalıdır.
5. Security & Multi-Tenancy (SaaS) Standards

CPalius is built for secure multi-tenant (SaaS) execution. Developer discipline is not trusted; security is enforced by default.
Law 5.1: Tenant Isolation by Default (The TenantFilter)

For SaaS applications, any entity marked with multiTenant: true in #[CpResource] must be automatically scoped.

    A Doctrine SQLFilter must intercept all SELECT queries to inject AND tenant_id = :current_tenant_id automatically.

    Writes (insert/update) must be intercepted by a prePersist listener to automatically stamp the entity with the active tenant_id.

Law 5.2: Multilingual Integrity

Global unique constraints on slug or translation_group_id will break multilingual databases. You must use composite database constraints:

    Route unique check: UNIQUE(slug, locale).

    Translation grouping: UNIQUE(translation_group_id, locale).

Law 5.3: Mass Assignment & Sanity

    Mass Assignment Protection: Data JSON writing must be limited by an allowlist defined in the resource config.

    XSS Sanitization: Any rich-text field stored in JSON must be aggressively sanitized using symfony/html-sanitizer before saving. No raw outputs in Twig unless explicitly sanitized.

    File Uploads: MIME types must be verified using finfo (not file extensions). Uploaded assets must be hashed and saved in a non-executable directory.

6. Performance & N+1 Muhafızı (Performance Budget)

Performance is not an afterthought; it is a budget checked at every pull request.
Law 6.1: Dev-Mode N+1 Exception Guard

In the dev environment, CPalius strictly forbids N+1 queries.

    If a single HTTP request executes more than a defined threshold of identical queries (e.g., 10), a MaxQueriesExceededException must be thrown. This forces the developer to write fetch-joins before pushing to production.

Law 6.2: QueryScopeApplier (Voter to SQL)

Executing a Symfony Voter for every item in a 100-item table list creates a massive N+1 database performance issue.

    CPalius uses a QueryScopeApplier service.

    Instead of post-filtering objects in PHP memory, this service translates the active user's capabilities (e.g., "view own only") directly into SQL WHERE clauses (e.g., AND author_id = :me) before the query executes.

Law 6.3: High-Performance Flat Field Index

To query dynamic JSON data fields without slow table-scans:

    Use a lightweight helper table node_field_index (node_id, field_name, value_string, value_int, value_decimal, value_datetime).

    On persist, any field marked with queryable: true must sync to this table. All filters/searches join on this flat index table.

Law 6.4: Sessionless Anonymous Traffic

To keep HTTP caching (nginx FastCGI cache or Varnish) highly efficient, anonymous page requests must not start a PHP Session. No session cookies should be sent to guest users.
7. Asset and Theme Isolation

We strictly avoid global Node.js/npm dependencies in the core repository.

    Core & Admin (AACP): Uses Symfony AssetMapper and Tailwind CSS Standalone Binary (Zero Node.js dependency).

    Theme Contract: Themes are completely decoupled. The theme developer may use Vite or esbuild. The theme configuration (theme.json) only declares built entry points (dist/). CPalius only serves the compiled output; it does not compile theme assets.

8. Module & Theme Developer Contract (Enforced, Not Requested)

Section 2 states "Core Never Dies" as a boot-time property. This section extends the same guarantee to a running site and states it as a contract: a module or theme that breaks the rules below is rejected or isolated by the platform itself, automatically, at the moment the rule is broken. Developer discipline is not the safety mechanism — enforcement is (same principle as Law 5's "security is enforced by default").

Law 8.1: Package Isolation (already enforced — ModulePackageContract)

Every module package is checked before it can ever activate, by code, not by review:

    Must not write into or reference cp-core/ or cp-includes/ (path traversal and core paths are rejected at the ZIP-entry level).

    Must not declare namespace App\ anywhere in its own files — that namespace is core-only.

    Must ship module.json with a bundle field matching Modules\<DirName>\<DirName>Module, the bundle class file itself, and Install/ModuleInstaller.php.

    A failing package is refused activation outright; it never reaches active_modules.php.

Law 8.2: Compile-Time Rejection (already enforced — ModuleActivator, Law 2.2)

Before a module joins active_modules.php, activation runs an isolated dry-run (cache clear, YAML lint, container lint) in a throwaway process. Any failure quarantines the module permanently and it is never activated. This is a hard gate, not a warning.

Law 8.3: Every Request-Time Extension Point Is Isolated, Including the Ones a Module Was Never Told About

    The blessed extension point is #[CpHook]: HookManager wraps every hook-point call (flat-file and attribute-based) in try/catch. A throw is logged to cp-core/var/log/module_quarantine.log and the request continues.

    A module MAY still register an ordinary Symfony EventSubscriberInterface / #[AsEventListener] directly on kernel.* events, when #[CpHook] cannot express what it needs. This is allowed, but it does not grant immunity: ModuleEventListenerGuardPass rewrites every Modules\*-owned kernel.event_listener / kernel.event_subscriber tag, at compile time, to run through ModuleEventListenerGuard instead of the module's class directly. A throw there is caught, logged with the real class and reason, and the request keeps going — exactly like a #[CpHook] failure. App\ (core) listeners are never touched; a core listener throwing is a real core bug and must propagate.

    Practical effect: there is no code path left where one module's runtime exception can 500 a page it does not own, let alone /aacp. If you find one, it is a bug in the guard, not a rule the module broke.

Law 8.4: SQL Migrations Must Be Idempotent — the Ledger Trusts the Filename, Not the Content

Resources/migrations/*.sql files are tracked as "applied" by filename alone, once, forever — there is no checksum re-validation. Consequences a module author must design for:

    Every statement MUST be safe to run more than once: CREATE TABLE IF NOT EXISTS, an information_schema-guarded ALTER TABLE, INSERT IGNORE. A migration that is not idempotent and somehow runs twice (a ledger reset, a restored backup) is the module's bug, not the platform's.

    Once shipped and applied anywhere, a migration file's content is effectively frozen. Fixing a mistake in it does not re-apply the fix to a site that already ran the old version — ship a new, separately-named migration file instead.

    cp:doctor's module_sql_integrity check verifies that every table/column an applied migration claims to create actually exists in the database. It reports drift; it does not repair it automatically. Repair is an explicit App\Core\Update\UpdateHookInterface that forgets the specific ledger entry so the (idempotent) file is retried — see RepairDroppedCommentedSqlHook for the reference shape.

Law 8.5: Theme Contract (extends Law 7)

    A theme ships only compiled output under Resources/dist/ (or equivalent) plus theme.json declaring entry points. The platform serves what is there; it does not compile theme assets, and a theme's build tooling is the theme author's own choice and problem.

    A theme's Twig templates render inside the same request-isolation boundary as everything else — a template error is caught at the response layer like any other exception; it does not require or receive special-casing here.

💡 How to use this Manifesto

Whenever starting a new development sprint or task:

    Read this file completely.

    Analyze the task requirements against these laws.

    Ensure that no core files in cp-core are modified in a way that breaks isolation.

    Write robust, typed, and secure Symfony 7.4 code.

