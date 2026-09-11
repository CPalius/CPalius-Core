<?php

declare(strict_types=1);

namespace App\Core\Portal;

/**
 * Structured, per-locale body of the CPalius CMF whitepaper (v1.2.0).
 * Ported verbatim from the original cpalius-website whitepaper documents.
 */
final class WhitepaperContent
{
    /**
     * @return array{title: string, intro_html: string, sections: list<array{id: string, title: string, html: string}>}
     */
    public function forLocale(string $locale): array
    {
        return $locale === 'tr' ? $this->turkish() : $this->english();
    }

    /**
     * @return array{title: string, intro_html: string, sections: list<array{id: string, title: string, html: string}>}
     */
    private function english(): array
    {
        return [
            'title' => 'CPalius CMF: Evolving from a Content Management System into a "Bulletproof" Enterprise Application Framework',
            'intro_html' => <<<'HTML'
<p><strong>Publication Type:</strong> Technical Review (Whitepaper) &amp; Architectural Roadmap (RFC)<br>
<strong>Version:</strong> v1.2.0<br>
<strong>Audience:</strong> Senior PHP Developers, System Architects, Open Source Contributors, and AI Agents<br>
<strong>Author / Founder:</strong> Ali Çömez (slaweally)<br>
<strong>Technology Stack:</strong> PHP 8.4+, Symfony 7.4 LTS, Doctrine ORM, AssetMapper, Tailwind CSS Standalone Binary</p>

<p><strong>Introduction: Theory, Practice, and the Pain of "Reinventing the Wheel"</strong></p>

<p>When starting a new project in the modern web ecosystem, developers always find themselves on a double-edged sword. On one side are classic CMS platforms (WordPress, Drupal) packed with ready-made plugin and theme systems, yet burdened with clumsy database schemas, mountains of technical debt, and security weaknesses. On the other side are pure modern frameworks (Symfony, Laravel) that force you to start from scratch&mdash;rewriting auth, ACL, file management, and admin panels on every project and burning time in the process.</p>

<p>Building your own MVC stack from zero may sound romantic, but it is not rational by today's standards. A framework is not just routing and controllers; it carries a massive "invisible iceberg" of security (CSRF, XSS, SQLi prevention), Dependency Injection (DI) Container management, and the HTTP layer.</p>

<p>CPalius was born to bridge these two worlds&mdash;to free developers from reinventing the wheel while giving them a world-class, optimized, secure, and extensible foundation. This document is a detailed account of CPalius's technical journey: from the cleanest skeleton install, through a "never-crashing" operating-system architecture, to the path from a CMS toward an Enterprise Application Framework.</p>
HTML,
            'sections' => [
                [
                    'id' => 'pristine-root',
                    'title' => 'Section 1: "Pristine Root" and Folder Architecture',
                    'html' => <<<'HTML'
<p>In a standard Symfony project, as third-party packages and recipes are installed, the project root turns into a dump of files and folders. The developer's own code mixes with the framework's system files. From day one, CPalius declared war on that chaos and adopted the "Pristine Root" policy.</p>

<p>We isolated the entire architecture so that the root retains only four fundamental folders that make clear what lives where, plus the <code>.env</code> file and <code>composer.json</code>.</p>

<p><strong>Folder Hierarchy</strong></p>

<pre><code>CPalius/ (Root)
|
|-- cp-core/       # === KERNEL &amp; SYSTEM SPACE ===
|   |-- bin/          # Console command tool (bin/console)
|   |-- config/       # Core configuration (bundles, packages, routes)
|   |-- migrations/   # Core database migration files (tracked in Git)
|   |-- src/          # Core PHP classes (App\ namespace)
|   `-- var/          # Cache, logs, and SQLite files (writable, gitignored)
|
|-- cp-includes/   # === DEPENDENCIES SPACE ===
|   `-- vendor/       # Composer packages (Symfony and libraries)
|
|-- cp-content/    # === USER &amp; DEVELOPER SPACE ===
|   |-- config/       # Config Sync area (infrastructure shipped as YAML)
|   |-- modules/      # Independent modules/plugins (Modules\ namespace)
|   |-- themes/       # User interface themes
|   `-- translations/ # Global UI translations (tr, en)
|
|-- public/        # === WEB ROOT === (the only publicly exposed folder)
|   |-- index.php     # Front Controller (single entry point)
|   `-- assets/       # Compiled / symlink'ed frontend assets
|
|-- .env           # Environment configuration
`-- composer.json  # CPalius custom path mappings
</code></pre>

<p><strong>Bending Symfony Flex's Internals</strong></p>

<p>To make this layout work, we pushed Symfony Flex and Composer's configuration capabilities to their limits. We configured <code>composer.json</code> so Flex would follow our folder structure: the <code>vendor-dir</code>, <code>bin-dir</code>, and the <code>extra</code> block parameters (<code>config-dir</code>, <code>src-dir</code>, <code>var-dir</code>, <code>public-dir</code>, and the runtime <code>project_dir</code> / <code>dotenv_path</code>).</p>

<p><strong>Technical Note:</strong> Changing only <code>config.vendor-dir</code> is not enough. Commands inside Symfony Flex (<code>cache:clear</code>, <code>assets:install</code>) resolve paths from parameters under the <code>extra</code> block&mdash;not from Composer's standard config. Without those parameters, the system crashed at compile time. This guarantees that all Flex recipes install directly under <code>cp-core</code>.</p>
HTML,
                ],
                [
                    'id' => 'core-never-dies',
                    'title' => 'Section 2: "Core Never Dies" Architecture',
                    'html' => <<<'HTML'
<p>When building a CMF, the worst nightmare is poorly written or broken code from the user space (<code>cp-content/modules</code>) locking up the entire system. Because PHP has no true OS-level sandbox, CPalius developed a multi-tier Active Defense and Quarantine Line.</p>

<p><strong>Defense Line 1: Solving the Chicken-and-Egg Problem (bundles.php)</strong></p>

<p>When Symfony boots, it first reads <code>config/bundles.php</code>. At that stage the database connection, service container, and autoloader are not fully up yet. If we tried to read module active state directly from the database, the system would lock before it could even boot.</p>

<p><strong>Solution:</strong> When a module is activated or deactivated, CPalius generates a static, PHP opcache-friendly array file at <code>cp-core/config/active_modules.php</code>. <code>bundles.php</code> only reads that file; the core bundles are always present and each declared module class is added only when <code>class_exists()</code> succeeds.</p>

<p><strong>Defense Line 2: Runtime Isolation (Kernel::boot Override)</strong></p>

<p>A class physically existing (<code>class_exists</code>) does not mean that module's code runs without errors. A <code>TypeError</code> or <code>RuntimeException</code> thrown inside the module's own <code>boot()</code> method can crash the entire system. To prevent that, <code>Kernel::boot()</code> is overridden so every module-level boot process is wrapped in a <code>try/catch (\Throwable)</code> shield; a failing module is written to the quarantine log while the core keeps running.</p>

<p><strong>Defense Line 3: Compile-Time Lock Protection &amp; Dry-Run</strong></p>

<p>If a plugin's <code>services.yaml</code> has invalid YAML syntax or a wrong Dependency Injection (autowire) definition, the Symfony container cannot compile. The system then crashes, and you cannot even run <code>cp:module:deactivate</code> from the terminal.</p>

<p><strong>Solution:</strong> An isolated Dry-Run check is integrated into module activation. The module class is written temporarily, a fully isolated subprocess is started with <code>symfony/process</code>, and it runs <code>cache:clear --no-warmup</code> &rarr; <code>lint:yaml</code> &rarr; <code>lint:container</code>. On a non-zero exit code the main process restores the file in a <code>finally</code> block, cancels activation, and permanently quarantines the module. The file is never left in a broken state.</p>

<p><strong>Defense Line 4: Isolated Route Loader</strong></p>

<p>In standard Symfony, a syntax error in a module's <code>routes.yaml</code> crashes the whole system at route-loading time. <code>SafeModuleRouteLoader</code> loads each module's routes inside an isolated try/catch block, so a faulty module is skipped while the rest of the system stays fully available.</p>
HTML,
                ],
                [
                    'id' => 'hybrid-data',
                    'title' => 'Section 3: Hybrid Data Model and High-Performance Flat Index System',
                    'html' => <<<'HTML'
<p>Both classic approaches to content management are problematic:</p>

<ul>
<li><strong>EAV (Entity-Attribute-Value) Model (Drupal):</strong> Every new field opens a new database table. Fetching a page with 15 custom fields requires 15 JOIN queries; the database locks up.</li>
<li><strong>Postmeta / Serialized Model (WordPress):</strong> All custom fields live row-by-row in a single meta table. Filtering and sorting become a performance disaster.</li>
</ul>

<p><strong>CPalius Hybrid Data Model:</strong> Frequently queried, filtered, and indexed core fields (ID, Title, Slug, Type, Status, Locale, Dates) are stored as real database columns. All dynamic, flexible, project-specific content fields (body text, featured image, gallery fields, SEO data) live in a single SQL <code>json</code> column (<code>data</code>).</p>

<p><strong>The Indexing Deadlock Across SQLite, MySQL, and Postgres</strong></p>

<p>If you want to query dynamic JSON data at the database level, SQLite, MySQL, and Postgres require completely different SQL syntax. Building indexes on generated columns is also extremely fragile with Doctrine ORM schema tools; Doctrine tries to drop those indexes on every schema update.</p>

<p><strong>Solution: NodeFieldIndex and the Dynamic Indexing Engine</strong></p>

<p>CPalius solves this with a Flat Field Index table and a Doctrine Event Listener. A standalone index table carries type-appropriate columns (<code>value_string</code>, <code>value_int</code>, <code>value_decimal</code>, <code>value_datetime</code>) for queryable dynamic fields, each backed by a compound index on <code>field_name</code>.</p>

<p>According to the developer-defined <code>QueryableFieldsRegistry</code> configuration, when a Node is saved or updated, the <code>NodeIndexListener</code> automatically parses the JSON <code>data</code> column, clears old indexes, and writes them idempotently to the helper table on <code>postPersist</code> / <code>postUpdate</code>.</p>

<p>Regardless of which database engine is used, JSON-backed data can then be queried at lightning speed through indexes with a single standard SQL/DQL query via <code>NodeRepository::findByIndexedField()</code> (type, locale, fieldName, value, valueColumn, operator).</p>
HTML,
                ],
                [
                    'id' => 'i18n',
                    'title' => 'Section 4: Multilingual Structure and Composite Uniqueness Constraints',
                    'html' => <<<'HTML'
<p>When multilingual (i18n) support is bolted on later, it collapses the entire data model. CPalius baked multilanguage into the core from day one.</p>

<p><strong>Composite Unique Constraints (Uniqueness Guarantee)</strong></p>

<p>In a multilingual setup, classic <code>unique: true</code> constraints lock the system. For example, Turkish <code>/tr/hakkimizda</code> and English <code>/en/hakkimizda</code> must be allowed to exist at the same time. A single global unique slug constraint blocks that.</p>

<p><strong>CPalius Solution:</strong> We use composite uniqueness constraints in the database schema:</p>

<ul>
<li><strong>Routing uniqueness:</strong> The same slug must be unique only within the same locale: <code>UNIQUE(slug, locale)</code>.</li>
<li><strong>Translation group uniqueness:</strong> Within the same translation group, only one piece of content may exist per locale: <code>UNIQUE(translation_group_id, locale)</code>.</li>
</ul>

<p>The same guarantee is carried to four more entities (categories, tags, menu_items, forum_sections) via <code>UNIQUE(translation_group_id, locale)</code>, so the database itself enforces "one record per locale per translation group" even under concurrent requests.</p>

<p><strong>Operator UI catalogues</strong></p>

<p>AACP and Studio chrome is bound to ICU YAML under <code>cp-content/translations</code> and each module's <code>Resources/translations</code>. Sidebar labels and <code>#[CpAdminMenu]</code> attributes use translation keys. Hardcoded Turkish (or English) operator strings do not live in PHP, Twig chrome, or JavaScript.</p>
HTML,
                ],
                [
                    'id' => 'entity-model',
                    'title' => 'Section 5: From "CMS" to "Application Framework"',
                    'html' => <<<'HTML'
<p>CPalius is not merely a content management system (CMS); it is a flexible application platform that can power Car Dealership, Travel Agency, Staff Management, or CRM/ERP systems. To enable that shift, we defined two classes of entities:</p>

<table>
<thead>
<tr><th>Aspect</th><th>Content Entities (Node)</th><th>Business Records (Resource)</th></tr>
</thead>
<tbody>
<tr><td><strong>Conceptual mapping</strong></td><td>Page, Post, Listing showcase, Blog</td><td>Vehicle, Invoice, Reservation, Staff</td></tr>
<tr><td><strong>Traits</strong></td><td>Has slug, multilanguage, publish status, SEO.</td><td>No slug, no locale, no publish; has a state machine (workflow).</td></tr>
<tr><td><strong>Common ground</strong></td><td colspan="2">Same Capability model, same Twig components, same CLI management, same Config Sync.</td></tr>
</tbody>
</table>

<p><strong>The #[CpResource] Revolution</strong></p>

<p>To bring coding business processes down to seconds, we designed a custom PHP Attribute. With a single annotation we wire a raw Doctrine class in the database to the full power of the platform:</p>

<pre><code>#[CpResource(
    name: 'vehicle',
    module: 'oto-galeri',
    capabilities: ['create', 'edit', 'delete', 'view'],
    auditable: true,
    multiTenant: true,
    workflow: 'vehicle_lifecycle'
)]
#[ORM\Entity]
class Vehicle
{
    private ?int $id = null;
    private ?string $plate = null;
    private ?string $brand = null;
    private ?int $price = 0; // Stored in cents (never Float!)
}
</code></pre>

<p>Thanks to this single attribute:</p>

<ul>
<li>Dynamic capabilities are registered automatically into the role/capability matrix (<code>vehicle.create</code>, <code>vehicle.edit</code>, etc.).</li>
<li>Automatic CRUD forms and list screens are derived dynamically from this definition.</li>
<li>For SaaS projects, multi-tenant isolation (<code>multiTenant: true</code>) is applied automatically in the background.</li>
<li>Every change on the entity is recorded instantly in the version history (audit log).</li>
</ul>
HTML,
                ],
                [
                    'id' => 'security',
                    'title' => 'Section 6: Security and Performance Constitution',
                    'html' => <<<'HTML'
<p>Security and performance are not polish added at the end of a project; they are the system's foundational building blocks.</p>

<p><strong>1. Capability-Based Access Control (CBAC)</strong></p>

<p>Standard Symfony <code>ROLE_ADMIN</code> or <code>ROLE_USER</code> approaches are clumsy and hard to extend later. In CPalius, role names are never checked in code; dynamically generated capabilities are always checked instead via <code>denyAccessUnlessGranted('system.module.manage')</code>.</p>

<ul>
<li><strong>Roles = Config (YAML):</strong> Role definitions and their capability matrices live as YAML under <code>cp-content/config/sync/</code> and travel with Git.</li>
<li><strong>Users = Content (DB):</strong> Real user records stay in the database and are never exported.</li>
</ul>

<p><strong>2. SaaS Data Leak Protection (Automatic Tenant SQLFilter)</strong></p>

<p>In multi-tenant (SaaS) systems, the biggest security hole is a developer forgetting to append <code>WHERE tenant_id = ?</code> to a query. In CPalius that risk is eliminated at the platform level: a Doctrine <code>SQLFilter</code> injects the tenant constraint into every query for entities marked multi-tenant.</p>

<p><strong>3. N+1 Query Guard (Dev-Mode N+1 Guard)</strong></p>

<p>To stop N+1 query mistakes&mdash;the most common cause of database bloat and slowness&mdash;a Doctrine DBAL middleware fires only in the dev environment. If the number of queries to the same table in a single HTTP request exceeds a set limit, it throws <code>MaxQueriesExceededException</code> immediately. Developers cannot ship code to production until they fix that error locally. The chain is real at the DBAL layer: <code>QueryCounterConnection</code>, <code>Driver</code>, <code>Statement</code>, and <code>TableParser</code>.</p>

<p><strong>4. Voter-to-SQL Conversion</strong></p>

<p>List screens must not run a PHP voter per row (that is an N+1 of authorization). <code>QueryScopeApplier</code> turns the caller's <code>.own</code> / <code>.any</code> capabilities into a Doctrine <code>WHERE</code> before the query hits the database.</p>

<p><strong>5. Zero-Trust Payloads and Core XSS Sanitization</strong></p>

<p>Inbound HTTP bodies are mapped to strict DTOs and validated before they reach controllers. Rich text destined for the database is sterilized by <code>RichTextSanitizer</code> (Manifesto Law 5.3). <code>SchemaOrgBuilder</code> emits JSON-LD (BlogPosting, WebPage) from the same hybrid JSON payload.</p>

<p><strong>6. Optimistic Concurrency</strong></p>

<p>High-write paths use optimistic locking instead of table locks, so concurrent mutations isolate without deadlock theater. Config (roles, capabilities) stays in Git-tracked YAML; users stay in the database&mdash;Drupal-style config sync via Symfony TreeBuilder.</p>
HTML,
                ],
                [
                    'id' => 'platform-layer',
                    'title' => 'Section 7: Platform Extensibility — API, Hooks, Cron, Plugins',
                    'html' => <<<'HTML'
<p>Since the first whitepaper draft, four extension backbones landed in core. Each carries the same Core Never Dies armor: a failing extension is quarantined; the kernel stays up.</p>

<p><strong>Cryptographic REST API Gateway</strong></p>

<p>A single <code>/api/{path}</code> wildcard is bound at compile time by <code>ApiRegistrationPass</code> to service methods marked <code>#[CpApi]</code>. Authentication uses the <code>X-CP-API-KEY</code> header, SHA-256 hashes, and timing-safe <code>hash_equals()</code>. Fail-closed: an invalid key never reaches the target method. Each endpoint runs in its own try/catch; a crashing method cannot 500 the gateway.</p>

<p><strong>Dual-lane isolated Hook engine</strong></p>

<p>Cotonti-style <code>Hooks/{hook_point}.php</code> files run inside a bound Closure so they cannot leak <code>$this</code>. Symfony-style <code>#[CpHook]</code> services are collected by <code>HookRegistrationPass</code>. A throwing hook is written to the quarantine log; the page still renders.</p>

<p><strong>Unified hybrid Cron engine</strong></p>

<p><code>CronManager</code> merges three sources into one list: <code>cp_cron_jobs</code> rows, <code>#[CpCronJob]</code> methods, and <code>Hooks/cron.{job}.php</code> files. Jobs run in isolated subprocesses and may only execute <code>cp:*</code> commands via <code>CronCommandWhitelist</code>. Operators can inspect and &ldquo;Run now&rdquo; from <code>/aacp/cron</code>.</p>

<p><strong>Module-independent Plugin layer</strong></p>

<p>Services marked <code>PluginInterface</code> are collected by <code>PluginRegistry</code>; enablement lives in <code>PluginToggleRepository</code>. A module can toggle optional widgets without deactivating the whole module.</p>

<p><strong>Lazy settings</strong></p>

<p><code>#[CpSetting]</code> keys are loaded through <code>SettingsRegistry</code> on demand&mdash;one query when asked, not a boot-time dump of every key.</p>

<p><strong>WordPress-style module packages</strong></p>

<p>A first-party module may ship <code>Resources/config/importmap.php</code> and <code>contributions.yaml</code>. <code>ModulePackageContract</code> validates the on-disk layout; operators can upload a ZIP from AACP. Homepage modes, portal feed blocks, schema types, and the account post-login landing are declared in contributions&mdash;core does not hardcode <code>forum_index</code> or <code>blog_index</code>.</p>
HTML,
                ],
                [
                    'id' => 'aacp-command',
                    'title' => 'Section 8: AACP Command Desk, Studio, and Recovery',
                    'html' => <<<'HTML'
<p>The Safe Mode / Recovery Console promised in the original roadmap is production code. AACP is the system management center, not a decorative admin skin.</p>

<p><strong>Recovery Console (<code>/aacp/recovery</code>)</strong></p>

<p>The route is intentionally <code>PUBLIC_ACCESS</code> in <code>security.yaml</code>. Authorization is a timing-safe <code>hash_equals()</code> against <code>AACP_RECOVERY_TOKEN</code> in <code>.env</code>. It issues no Doctrine queries, so it still works when the database is gone. An empty token seals the door (fail-safe).</p>

<p><strong>Live command desk (<code>/aacp</code>)</strong></p>

<p>The dashboard polls <code>/aacp/system/metrics</code> for PHP version, load, request time, memory, OPcache hit rate, database telemetry, queue, cron last-run, and module quarantine. Cache Rebuild and OPcache flush are one-click, CSRF-protected actions. Content KPIs live on Studio <code>/admin</code>, not on AACP&mdash;AACP is operations, Studio is the editorial command desk.</p>

<p><strong>Studio dashboard</strong></p>

<p>Each first-party module contributes a <code>StudioDashboardStatsProvider</code> (Blog, Forum, Media, Menu, Roadmap, SEO). Studio is a Drupal-style command desk: counts, shortcuts, and homepage portal layout (block order, bilingual copy in <code>portal.{locale}.yaml</code>).</p>

<p><strong>Quarantine, localization, performance backends</strong></p>

<p><code>/aacp/quarantine</code> is a read-only view of <code>module_quarantine.log</code>. <code>TranslationManager</code> edits core and module ICU YAML with atomic <code>.tmp</code> + <code>rename()</code> writes. The Performance screen tests Redis, Memcached, Varnish, Nginx PageSpeed, and CPalius Origin Cache; the enabled flag is set only after a successful probe&mdash;never a fake-green toggle. <code>/aacp/backup</code> is the Backup Management desk (capability <code>system.backup.manage</code>): database dump, files ZIP, or full archive, with download and delete.</p>
HTML,
                ],
                [
                    'id' => 'origin-cache',
                    'title' => 'Section 9: Origin HTML Cache and Performance Inventory',
                    'html' => <<<'HTML'
<p>CPalius Origin Cache is a first-party HTML cache that does not require Redis, Varnish, or a CDN. It sits beside those backends and can run on plain shared hosting.</p>

<p><strong>How a hit is served</strong></p>

<p>Enabled state is a disk sentinel under <code>public/page-cache/</code>. Apache rewrite in <code>public/.htaccess</code> serves <code>page-cache/{path}/index.html</code> for anonymous GET requests with no query string and no session cookies (<code>PHPSESSID</code>, <code>REMEMBERME</code>). PHP still writes and, for query-string variants, reads through <code>OriginCacheWriter</code> / <code>OriginCacheReader</code>.</p>

<p><strong>What is stored</strong></p>

<p>HTML is optionally minified. Linked CSS/JS can be copied and minified; images can be derived as WebP. An optional HTML shield rewrites markup. TTL, exclude paths (<code>/aacp</code>, <code>/admin</code>, <code>/login</code>, APIs, profiler), minify, asset/image compression, and shield are <code>performance.cpalius.*</code> settings. Cron <code>cpalius.origin_cache.purge</code> drops expired files every 15 minutes. Cache Rebuild also purges origin HTML.</p>

<p><strong>Invalidation</strong></p>

<p><code>OriginCachePurger</code> runs on Blog post/category/tag writes (including scheduled publish), Forum topic mutations, Roadmap entry saves, and Menu saves&mdash;so editorial changes do not leave stale HTML on disk.</p>

<p><strong>AACP Performance panel</strong></p>

<p>The dashboard Performance section inventories every backend the PHP process can see:</p>

<ul>
<li><strong>Origin Cache:</strong> HTML page count, disk bytes, recent paths with size and mtime.</li>
<li><strong>Redis:</strong> <code>DBSIZE</code> (key count) and <code>INFO memory</code> (<code>used_memory</code>).</li>
<li><strong>Memcached:</strong> <code>curr_items</code> and <code>bytes</code> (plus hit count when the daemon answers).</li>
<li><strong>OPcache:</strong> cached scripts, used memory, hit rate.</li>
<li><strong>Varnish / PageSpeed:</strong> enablement and TTL / last probe. Object counts need <code>varnishstat</code> or the PageSpeed admin on the host; PHP cannot invent those numbers.</li>
</ul>

<p>Probes are isolated and cached for a few seconds so a hanging Redis cannot 500 the dashboard.</p>
HTML,
                ],
                [
                    'id' => 'backup',
                    'title' => 'Section 10: Backup Management',
                    'html' => <<<'HTML'
<p>Disaster recovery is a first-party AACP desk, not a third-party plugin. Capability <code>system.backup.manage</code> gates <code>/aacp/backup</code>. The admin role inherits it via <code>*</code>; editors do not see the menu.</p>

<p><strong>Three archive types</strong></p>

<ul>
<li><strong>Database:</strong> a gzipped SQL dump written in PHP through Doctrine DBAL. <code>mysqldump</code> is not required, so Laragon on Windows works the same as Linux hosting.</li>
<li><strong>Files:</strong> a ZIP of the whole CPalius tree, including <code>cp-includes/vendor</code> and the entire <code>public/</code> directory (uploads, compiled assets, themes, front controller). Out: every <code>.env*</code> file, <code>.git</code>, <code>node_modules</code>, Symfony cache, Tailwind compile cache, sessions, Origin Cache HTML, and nested archives under <code>cp-core/var/backups/</code>.</li>
<li><strong>Full:</strong> the files ZIP plus <code>database.sql.gz</code> packed inside the same archive.</li>
</ul>

<p>Archives are named <code>cpalius-{db|files|full}-YYYYMMDD-HHMMSS.{sql.gz|zip}</code> and live under <code>cp-core/var/backups/</code> (already gitignored). Download uses <code>/aacp/backup/archive/{stem}</code> (no <code>.sql.gz</code> / <code>.zip</code> in the URL) so nginx static-file locations cannot intercept the request; <code>Content-Disposition</code> still sends the real filename. Delete is CSRF-protected. Path traversal is rejected by an allowlist on the filename. CLI <code>cp:backup:create</code> is on the cron whitelist so operators can schedule dumps without a web timeout.</p>

<p>v1 does not overwrite a live tree from the browser. Restore is a staging-first operator step: unpack files beside a fresh install, apply SQL on a copy of the database, then swap. Putting restore behind a one-click AACP button would violate Core Never Dies.</p>
HTML,
                ],
                [
                    'id' => 'telemetry',
                    'title' => 'Section 11: Security Telemetry and IP Control',
                    'html' => <<<'HTML'
<p>AACP records request telemetry without turning the public site into a dark-pattern tracker. The master switch is <code>telemetry.security_enabled</code> and defaults to <strong>off</strong>.</p>

<p><strong>Visitor mode (default)</strong></p>

<p>The dashboard shows page views, unique IPs, top paths, and a traffic chart. No threat feed, no Ban IP. Suitable for hosting the marketing site without a WAF console in the operator&rsquo;s face.</p>

<p><strong>Security mode</strong></p>

<p>When enabled, <code>TelemetrySubscriber</code> on <code>kernel.terminate</code> persists a row to <code>cp_system_telemetry_logs</code>: IP, user, method, URI, user-agent, severity, event type, threat score, and JSON details. <code>ThreatAnalyzer</code> scores SQLi, XSS, scanner, path traversal, and login noise. The live feed polls JSON; a details modal opens the row; Ban IP is offered only for <code>critical</code> / <code>threat</code> severities.</p>

<p><strong>IP ban</strong></p>

<p><code>IpBanService</code> writes <code>cp_banned_ips</code>. <code>BannedIpSubscriber</code> rejects banned clients before the rest of the stack spends budget. A cron task purges old telemetry rows so the table cannot grow without bound.</p>
HTML,
                ],
                [
                    'id' => 'first-party',
                    'title' => 'Section 12: First-Party Modules, Media Pipeline, and Deep Localization',
                    'html' => <<<'HTML'
<p>Blog, Media, Menu, Forum, Roadmap, Pages, and SEO are reference modules: they exercise API, Hook, Cron, Plugin, Settings, contributions, and Studio stats the same way a third-party module must.</p>

<p><strong>Blog</strong> sits on <code>Node::type = post</code> with categories, tags, scheduled publish (<code>PublishScheduledPostsTask</code>), <code>GET /api/blog/posts</code>, sidebar hooks, and Schema.org BlogPosting. <strong>Pages</strong> sits on <code>Node::type = page</code>; field groups use <code>fg-</code> slugs and the public route is <code>page_show</code> at <code>/{_locale}/{slug}</code>. <strong>Menu</strong> is WordPress-style drag-and-drop; items reference nodes loosely (no hard FK) so soft-delete stays honest. <strong>Roadmap</strong> is a native feed that can also pull related blog and forum activity onto the portal. <strong>SEO</strong> contributes sitemap sources and JSON-LD.</p>

<p><strong>Forum engine</strong></p>

<p>Hierarchical boards reuse the Node tree; prefixes, a CSRF-protected report/moderation queue, ranks/badges, and a 20/80 postbit layout (Golden Ratio) run on the same capability model as the rest of the CMF.</p>

<p><strong>Media pipeline (Manifesto Law 3.3)</strong></p>

<p><code>Asset</code> is independent of Node. Flysystem storage plus sha256 dedup. <code>ImageProcessor</code> (GD) builds <code>crop</code> or <code>fit</code> derivatives into <code>public/uploads/cache/</code>; <code>cp_thumb</code> accepts an Asset, id, storage key, or <code>/uploads/...</code> URL. A failed derivative returns the original URL (fail-soft). <code>purge()</code> drops all sizes when the source changes.</p>

<p><strong>Deep localization</strong></p>

<p>Each row lives in its locale and joins siblings via <code>translation_group_id</code> UUID, enforced by <code>UNIQUE(translation_group_id, locale)</code> (also on categories, tags, menu items, forum sections). <code>LocaleSwitchService</code> builds the counterpart URL; missing siblings fall back to that locale&rsquo;s home&mdash;never a 404. AACP has no locale prefix; panel locale is the <code>cp_locale</code> cookie. Translation files are written atomically (<code>.tmp</code> + OS <code>rename()</code>).</p>

<p><strong>Zero Node.js in core UI</strong></p>

<p>Admin and developer chrome use AssetMapper + the Tailwind standalone binary. There is no Node.js build for those surfaces. Public theme CSS may still be a static theme asset.</p>
HTML,
                ],
                [
                    'id' => 'roadmap',
                    'title' => 'Section 13: Future Roadmap and Technical Consultation (RFC)',
                    'html' => <<<'HTML'
<p>The core security, performance, and architectural backbone of CPalius is complete. Since this whitepaper's first draft the following have shipped: AACP Safe Mode / Recovery Console, the isolated Hook system, the unified Cron engine, the REST API Gateway, the fourth defense line (<code>SafeModuleRouteLoader</code>), the on-demand image pipeline (<code>cp_thumb</code>), the <code>#[CpResource]</code> audit log, Studio dashboard stats, CPalius Origin Cache, the AACP performance inventory, optional security telemetry with IP ban, WordPress-style module packages and the contribution catalog (homepage, portal, schema, account landing), the Pages module, operator UI catalogue binding, and AACP Backup Management (<code>cp:backup:create</code>).</p>

<p>In upcoming development sprints we will build the following systems:</p>

<ul>
<li><strong>Workflow &amp; State Machine:</strong> A mechanism that manages transition processes for business records such as invoices, vehicles, and reservations (<code>draft &rarr; preparation &rarr; sold</code>) via YAML definitions, and automatically ties every transition to the audit log and notification queue. <code>CpResource::$workflow</code> is already declared.</li>
<li><strong>Messenger Async Queue:</strong> Wiring a real transport (Doctrine/Redis) so email and notification work move onto an asynchronous queue. <code>symfony/messenger</code> is installed and visible on the AACP desk; no active transport is bound yet.</li>
</ul>
HTML,
                ],
                [
                    'id' => 'community',
                    'title' => 'Questions and Feedback (Community Consultation)',
                    'html' => <<<'HTML'
<p>To refine this architecture further, we welcome feedback from you valued developers on these topics:</p>

<ul>
<li><strong>Flat Field Index Model:</strong> How do you think this model&mdash;designed for SQLite, MySQL, and Postgres compatibility&mdash;will perform at massive data volumes? Should the index table be partitioned?</li>
<li><strong>Config Sync Approach:</strong> What do you think of our idea to emulate Drupal's config sync system using Symfony's TreeBuilder?</li>
<li><strong>Zero Node.js Stance:</strong> Will our decision to use AssetMapper + Standalone Tailwind in the developer and admin panels constrain us when writing highly complex frontend components later?</li>
</ul>

<p>We eagerly await your ideas, critiques, and architectural suggestions. With the strength of the community, CPalius will become the most solid application framework.</p>
HTML,
                ],
            ],
        ];
    }

    /**
     * @return array{title: string, intro_html: string, sections: list<array{id: string, title: string, html: string}>}
     */
    private function turkish(): array
    {
        return [
            'title' => 'CPalius CMF: Bir İçerik Yönetim Sisteminden "Kurşun Geçirmez" Kurumsal Uygulama Framework\'üne Evrim',
            'intro_html' => <<<'HTML'
<p><strong>Yayın Türü:</strong> Teknik İnceleme (Whitepaper) &amp; Mimari Yol Haritası (RFC)<br>
<strong>Sürüm:</strong> v1.2.0<br>
<strong>Hedef Kitle:</strong> Kıdemli PHP Geliştiricileri, Sistem Mimarları, Açık Kaynak Geliştiricileri ve Yapay Zeka Ajanları<br>
<strong>Yazar / Kurucu:</strong> Ali Çömez (slaweally)<br>
<strong>Teknoloji Yığını:</strong> PHP 8.4+, Symfony 7.4 LTS, Doctrine ORM, AssetMapper, Tailwind CSS Standalone Binary</p>

<p><strong>Giriş: Teori, Pratik ve "Tekerleği Yeniden İcat Etme" Sancısı</strong></p>

<p>Modern web ekosisteminde yeni bir projeye başlarken geliştiriciler kendilerini her zaman iki ucu keskin bir bıçağın üzerinde bulurlar. Bir yanda, hazır eklenti ve tema sistemleriyle donatılmış ancak hantal veritabanı şemaları, teknik borç dağları ve güvenlik zaafiyetleriyle boğuşan klasik CMS'ler (WordPress, Drupal) vardır. Diğer yanda ise her şeye sıfırdan başlamayı gerektiren, her projede auth, ACL, dosya yönetimi ve admin paneli gibi temel bileşenleri sıfırdan yazarak zaman kaybettiren saf modern framework'ler (Symfony, Laravel) bulunur.</p>

<p>Sıfırdan kendi MVC yapısını yazmak kulağa romantik gelse de günümüz standartlarında rasyonel değildir. Bir framework sadece yönlendirme (routing) ve kontrolcülerden ibaret değildir; güvenlik (CSRF, XSS, SQLi engelleme), Dependency Injection (DI) Container yönetimi ve HTTP katmanı gibi devasa bir "görünmez buzdağı" barındırır.</p>

<p>İşte CPalius, bu iki dünya arasındaki köprüyü kurmak; geliştiriciyi tekerleği yeniden icat etme sancısından kurtarırken ona dünya standartlarında, optimize, güvenli ve genişletilebilir bir altyapı sunmak amacıyla doğdu. Bu döküman, CPalius'un en temiz skeleton kurulumundan, "çökmeyen" bir işletim sistemi mimarisine; ardından bir CMS'ten "Kurumsal Uygulama Framework'üne" uzanan teknik yolculuğunun en ince ayrıntısına kadar dökümüdür.</p>
HTML,
            'sections' => [
                [
                    'id' => 'pristine-root',
                    'title' => '1. Bölüm: "Pristine Root" ve Klasör Mimarisi',
                    'html' => <<<'HTML'
<p>Standart bir Symfony projesinde üçüncü parti paketler ve tarifler (recipes) yüklendikçe projenin ana dizini (root) dosya ve klasör çöplüğüne döner. Geliştiricinin kendi yazdığı kodlar ile framework'ün sistem dosyaları birbirine karışır. CPalius, projenin ilk gününde bu karmaşaya savaş açtı ve "Pristine Root" (Tertemiz Ana Dizin) politikasını benimsedi.</p>

<p>Ana dizinde sadece neyin nerede olduğunu gösteren 4 temel klasör, <code>.env</code> dosyası ve <code>composer.json</code> kalacak şekilde tüm mimariyi izole ettik.</p>

<p><strong>Klasör Hiyerarşisi</strong></p>

<pre><code>CPalius/ (Ana Dizin)
|
|-- cp-core/       # === KERNEL &amp; SYSTEM SPACE ===
|   |-- bin/          # Konsol komut araci (bin/console)
|   |-- config/       # Core konfigurasyonlari (bundles, packages, routes)
|   |-- migrations/   # Cekirdek veritabani goc dosyalari (Git'e tabi)
|   |-- src/          # Cekirdek PHP siniflari (App\ namespace)
|   `-- var/          # Cache, log ve SQLite dosyalari (yazma alani)
|
|-- cp-includes/   # === DEPENDENCIES SPACE ===
|   `-- vendor/       # Composer paketleri (Symfony ve kutuphaneler)
|
|-- cp-content/    # === USER &amp; DEVELOPER SPACE ===
|   |-- config/       # Config Sync alani (YAML olarak tasinan altyapi)
|   |-- modules/      # Bagimsiz moduller/eklentiler (Modules\ namespace)
|   |-- themes/       # Kullanici arayuz temalari
|   `-- translations/ # Global arayuz cevirileri (tr, en)
|
|-- public/        # === WEB ROOT === (Disariya acik tek klasor)
|   |-- index.php     # Front Controller (Tek giris kapisi)
|   `-- assets/       # Derlenmis/Semlink edilmis frontend varliklari
|
|-- .env           # Ortam yapilandirmalari
`-- composer.json  # CPalius ozel yol eslemeleri
</code></pre>

<p><strong>Symfony Flex'in İç Mekanizmalarını Bükmek</strong></p>

<p>Bu yapıyı kurabilmek için Symfony Flex ve Composer'ın yapılandırma yeteneklerini en uç sınırlarına kadar zorladık. <code>composer.json</code> dosyasını Flex'e kendi klasör yapımızı dikte edecek şekilde yapılandırdık: <code>vendor-dir</code>, <code>bin-dir</code> ve <code>extra</code> bloğu altındaki parametreler (<code>config-dir</code>, <code>src-dir</code>, <code>var-dir</code>, <code>public-dir</code> ve runtime <code>project_dir</code> / <code>dotenv_path</code>).</p>

<p><strong>Teknik Not:</strong> Sadece <code>config.vendor-dir</code> değiştirmek yetersizdir. Symfony Flex'in içindeki komutlar (<code>cache:clear</code>, <code>assets:install</code>), yolları hesaplarken composer'ın standart konfigürasyonlarından değil, <code>extra</code> bloğu altındaki parametrelerden beslenir. Bu parametreler eklenmediğinde sistem derleme aşamasında çöküyordu. Bu sayede tüm Flex tariflerinin doğrudan <code>cp-core</code> altına kurulmasını garanti altına aldık.</p>
HTML,
                ],
                [
                    'id' => 'core-never-dies',
                    'title' => '2. Bölüm: "Core Never Dies" (Çökmeyen Çekirdek) Mimarisi',
                    'html' => <<<'HTML'
<p>Bir CMF geliştirirken en büyük kabus, kullanıcı alanından (<code>cp-content/modules</code>) gelebilecek kötü yazılmış veya bozuk kodların tüm sistemi kilitlemesidir. PHP'de gerçek bir işletim sistemi seviyesinde "sandbox" olmadığı için, CPalius çok kademeli bir Aktif Savunma ve Karantina Hattı geliştirmiştir.</p>

<p><strong>1. Savunma Hattı: Tavuk-Yumurta Probleminin Çözümü (bundles.php)</strong></p>

<p>Symfony boot edilirken ilk olarak <code>config/bundles.php</code> dosyası okunur. Bu aşamada henüz veritabanı bağlantısı, servis konteyneri veya autoloader tam olarak ayağa kalkmamıştır. Modüllerin aktiflik durumunu doğrudan veritabanından okumaya çalışırsak, sistem daha boot edilemeden kilitlenir.</p>

<p><strong>Çözüm:</strong> Bir modül aktif veya pasif edildiğinde CPalius, <code>cp-core/config/active_modules.php</code> adında statik, PHP opcache dostu bir dizi dosyası üretir. <code>bundles.php</code> sadece bu dosyayı okur; çekirdek bundle'lar her zaman yüklüdür ve her modül sınıfı yalnızca <code>class_exists()</code> başarılıysa eklenir.</p>

<p><strong>2. Savunma Hattı: Çalışma Zamanı İzolasyonu (Kernel::boot Override)</strong></p>

<p>Sınıfın fiziksel olarak var olması (<code>class_exists</code>), o modülün kodunun hatasız çalıştığı anlamına gelmez. Modülün kendi <code>boot()</code> metodu içinde fırlatacağı bir <code>TypeError</code> veya <code>RuntimeException</code> tüm sistemi çökertebilir. Bunu engellemek için <code>Kernel::boot()</code> override edilerek modül seviyesindeki tüm boot süreçleri <code>try/catch (\Throwable)</code> zırhıyla kuşatıldı; hatalı modül karantina günlüğüne yazılır, çekirdek çalışmaya devam eder.</p>

<p><strong>3. Savunma Hattı: Compile-Time Kilitlenme Koruması &amp; Dry-Run</strong></p>

<p>Eklentideki bir <code>services.yaml</code> dosyasında geçersiz bir YAML sözdizimi varsa veya yanlış bir DI tanımı yapıldıysa, Symfony container'ı derlenemez. Bu durumda sistem çöker ve terminalden <code>cp:module:deactivate</code> komutunu bile çalıştıramayız.</p>

<p><strong>Çözüm:</strong> Modül aktivasyonuna izole bir Dry-Run kontrolü entegre edildi. Modül sınıfı geçici olarak yazılır, <code>symfony/process</code> ile tamamen izole bir alt süreç başlatılır ve sırasıyla <code>cache:clear --no-warmup</code> &rarr; <code>lint:yaml</code> &rarr; <code>lint:container</code> koşturulur. Sıfır dışı bir exit code'da ana süreç <code>finally</code> bloğunda dosyayı geri getirir, aktivasyonu iptal eder ve modülü kalıcı karantinaya alır. Dosya asla bozuk haliyle kaydedilmez.</p>

<p><strong>4. Savunma Hattı: İzole Route Yükleyicisi</strong></p>

<p>Standart Symfony'de bir modülün <code>routes.yaml</code> dosyasındaki bir syntax hatası, rota yükleme anında tüm sistemi çökertir. <code>SafeModuleRouteLoader</code> her modülün rotalarını izole bir try/catch bloğunda yükler; hatalı modül atlanır, sistemin geri kalanı tamamen ayakta kalır.</p>
HTML,
                ],
                [
                    'id' => 'hybrid-data',
                    'title' => '3. Bölüm: Hibrit Veri Modeli ve Yüksek Performanslı Flat Index Sistemi',
                    'html' => <<<'HTML'
<p>İçerik yönetiminde iki klasik yaklaşım da sorunludur:</p>

<ul>
<li><strong>EAV (Entity-Attribute-Value) Modeli (Drupal):</strong> Her yeni alan için yeni bir veritabanı tablosu açılır. 15 özel alanı olan bir sayfayı çekmek için 15 JOIN sorgusu atılır; veritabanı kilitlenir.</li>
<li><strong>Postmeta/Serialized Modeli (WordPress):</strong> Tüm özel alanlar tek bir meta tablosunda satır satır tutulur. Filtreleme ve sıralama yapmak tam bir performans felaketidir.</li>
</ul>

<p><strong>CPalius Hibrit Veri Modeli:</strong> Sık sorgulanan, filtrelenen ve indekslenen temel alanları (ID, Başlık, Slug, Tür, Durum, Dil, Tarihler) gerçek veritabanı sütunları olarak tutuyoruz. İçeriğin kendisine ait tüm dinamik, esnek ve her projede değişebilecek alanları (Gövde metni, öne çıkan görsel, galeri alanları, SEO verileri) ise tek bir SQL <code>json</code> sütununda (<code>data</code>) saklıyoruz.</p>

<p><strong>SQLite, MySQL ve Postgres Uyumluluğunda İndeksleme Çıkmazı</strong></p>

<p>Dinamik JSON verilerini veritabanı düzeyinde sorgulamak isterseniz, SQLite, MySQL ve Postgres arasında tamamen farklı SQL sözdizimleri kullanmanız gerekir. Ayrıca generated columns üzerinde indeks oluşturmak Doctrine ORM şema araçlarıyla son derece kırılgandır; Doctrine her şema güncellemesinde bu indeksleri silmeye çalışır.</p>

<p><strong>Çözüm: NodeFieldIndex ve Dinamik İndeksleme Motoru</strong></p>

<p>CPalius bu sorunu bir Flat Field Index tablosu ve bir Doctrine Event Listener ile çözer. Bağımsız bir indeks tablosu, sorgulanacak dinamik alanlar için tip-uygun kolonlar (<code>value_string</code>, <code>value_int</code>, <code>value_decimal</code>, <code>value_datetime</code>) taşır; her biri <code>field_name</code> üzerinden bileşik indekslidir.</p>

<p>Geliştiricinin belirlediği <code>QueryableFieldsRegistry</code> yapılandırmasına göre, bir Node kaydedildiğinde veya güncellendiğinde <code>NodeIndexListener</code> otomatik olarak JSON <code>data</code> sütununu parse eder, eski indeksleri temizler ve <code>postPersist</code> / <code>postUpdate</code> anında idempotent şekilde yardımcı tabloya yazar.</p>

<p>Artık hangi veritabanı motoru kullanılırsa kullanılsın, JSON verileri <code>NodeRepository::findByIndexedField()</code> (type, locale, fieldName, value, valueColumn, operator) ile tek bir standart SQL/DQL sorgusuyla ışık hızında sorgulanabilir.</p>
HTML,
                ],
                [
                    'id' => 'i18n',
                    'title' => '4. Bölüm: Çok Dilli Yapı ve Kompozit Benzersizlik Kısıtları',
                    'html' => <<<'HTML'
<p>Çok dilli (i18n) yapı projelere sonradan eklendiğinde tüm veri modelini çökertir. CPalius, çoklu dili ilk günden çekirdeğin hücrelerine işlemiştir.</p>

<p><strong>Kompozit Unique Constraints (Benzersizlik Güvencesi)</strong></p>

<p>Çok dilli bir yapıda, klasik <code>unique: true</code> kısıtlamaları sistemi kilitler. Örneğin, Türkçe <code>/tr/hakkimizda</code> ile İngilizce <code>/en/hakkimizda</code> sayfalarının aynı anda var olabilmesi gerekir. Global tek bir slug unique kısıtlaması bunu engeller.</p>

<p><strong>CPalius Çözümü:</strong> Veritabanı şemamızda kompozit benzersizlik kısıtları kullandık:</p>

<ul>
<li><strong>Yönlendirme Benzersizliği:</strong> Aynı slug sadece aynı dilde unique olmalıdır: <code>UNIQUE(slug, locale)</code>.</li>
<li><strong>Çeviri Grubu Benzersizliği:</strong> Aynı çeviri grubunda aynı dilden sadece bir adet içerik bulunabilir: <code>UNIQUE(translation_group_id, locale)</code>.</li>
</ul>

<p>Aynı güvence, <code>UNIQUE(translation_group_id, locale)</code> ile dört entity'ye daha (categories, tags, menu_items, forum_sections) taşınmıştır; böylece "bir çeviri grubunda her dilden en fazla bir kayıt" kuralını eşzamanlı isteklerde bile veritabanının kendisi zorlar.</p>

<p><strong>Operatör arayüz katalogları</strong></p>

<p>AACP ve Studio kromu <code>cp-content/translations</code> altındaki ICU YAML'e ve her modülün <code>Resources/translations</code> dizinine bağlıdır. Kenar çubuğu etiketleri ve <code>#[CpAdminMenu]</code> öznitelikleri çeviri anahtarı kullanır. Sabit kodlanmış Türkçe (veya İngilizce) operatör metinleri PHP, Twig kromu veya JavaScript içinde yaşamaz.</p>
HTML,
                ],
                [
                    'id' => 'entity-model',
                    'title' => '5. Bölüm: "CMS"ten "Uygulama Framework\'üne" Geçiş',
                    'html' => <<<'HTML'
<p>CPalius sadece bir içerik yönetim sistemi (CMS) değildir; arkasında Oto Galeri, Turizm Acentası, Personel Yönetimi veya CRM/ERP sistemlerinin çalışabileceği esnek bir uygulama platformudur. Bu dönüşümü sağlamak için iki sınıf varlık tanımladık:</p>

<table>
<thead>
<tr><th>Özellik</th><th>Content Entities (Node)</th><th>Business Records (Resource)</th></tr>
</thead>
<tbody>
<tr><td><strong>Kavramsal Karşılık</strong></td><td>Sayfa, Yazı, İlan Vitrini, Blog</td><td>Araç, Fatura, Rezervasyon, Personel</td></tr>
<tr><td><strong>Özellikleri</strong></td><td>Slug var, çoklu dil var, yayın durumu var, SEO var.</td><td>Slug yok, dil yok, yayın yok, durum makinesi (workflow) var.</td></tr>
<tr><td><strong>Ortak Payda</strong></td><td colspan="2">Aynı Yetenek (Capability) modeli, aynı Twig bileşenleri, aynı CLI yönetimi, aynı Config Sync.</td></tr>
</tbody>
</table>

<p><strong>#[CpResource] Devrimi</strong></p>

<p>Geliştiricinin iş süreçlerini kodlamasını saniyeler seviyesine indirmek için özel bir PHP Attribute yapısı tasarladık. Tek bir anotasyon ile veritabanındaki ham bir Doctrine sınıfını platformun tüm güçleriyle birleştiriyoruz:</p>

<pre><code>#[CpResource(
    name: 'vehicle',
    module: 'oto-galeri',
    capabilities: ['create', 'edit', 'delete', 'view'],
    auditable: true,
    multiTenant: true,
    workflow: 'vehicle_lifecycle'
)]
#[ORM\Entity]
class Vehicle
{
    private ?int $id = null;
    private ?string $plate = null;
    private ?string $brand = null;
    private ?int $price = 0; // Kurus bazinda saklanir (Float asla!)
}
</code></pre>

<p>Bu tek nitelik (<code>#[CpResource]</code>) sayesinde:</p>

<ul>
<li>Rol ve yetenek matrisine (<code>vehicle.create</code>, <code>vehicle.edit</code> vb.) dinamik yetenekler otomatik kaydolur.</li>
<li>Otomatik CRUD formları ve liste ekranları bu tanıma göre dinamik olarak türetilir.</li>
<li>SaaS projeleri için çoklu kiracı (<code>multiTenant: true</code>) izolasyonu arka planda otomatik uygulanır.</li>
<li>Entity üzerinde yapılan tüm değişiklikler anlık olarak sürüm geçmişine (audit log) kaydedilir.</li>
</ul>
HTML,
                ],
                [
                    'id' => 'security',
                    'title' => '6. Bölüm: Güvenlik ve Performans Anayasası',
                    'html' => <<<'HTML'
<p>Güvenlik ve performans, projenin son aşamasında "üzerine eklenen" birer cila değildir; sistemin en temel yapı taşlarıdır.</p>

<p><strong>1. Yetenek Tabanlı Erişim Kontrolü (CBAC)</strong></p>

<p>Standart Symfony <code>ROLE_ADMIN</code> veya <code>ROLE_USER</code> yaklaşımları hantaldır ve sonradan genişletilemez. CPalius'ta kodun hiçbir yerinde rol ismi kontrol edilmez; her zaman dinamik olarak üretilen yetenekler <code>denyAccessUnlessGranted('system.module.manage')</code> ile kontrol edilir.</p>

<ul>
<li><strong>Roller = Config (YAML):</strong> Rol tanımları ve yetenek matrisleri <code>cp-content/config/sync/</code> altında YAML dosyalarında tutulur ve Git ile taşınır.</li>
<li><strong>Kullanıcılar = Content (DB):</strong> Gerçek kullanıcı kayıtları veritabanında kalır, asla dışa aktarılmaz.</li>
</ul>

<p><strong>2. SaaS Veri Sızıntısı Koruması (Automatic Tenant SQLFilter)</strong></p>

<p>Çok kiracılı sistemlerde en büyük güvenlik açığı, yazılımcının bir sorgunun sonuna <code>WHERE tenant_id = ?</code> yazmayı unutmasıdır. CPalius'ta bu ihtimal platform seviyesinde yok edilmiştir: bir Doctrine <code>SQLFilter</code>, multi-tenant işaretli entity'ler için tenant kısıtını her sorguya enjekte eder.</p>

<p><strong>3. N+1 Sorgu Muhafızı (Dev-Mode N+1 Guard)</strong></p>

<p>Veritabanı şişmelerinin ve yavaşlıklarının en yaygın sebebi olan N+1 sorgu hatalarını engellemek için sadece dev ortamında tetiklenen bir Doctrine DBAL middleware'i devrededir. Tek bir HTTP isteğinde aynı tabloya atılan sorgu sayısı belirlenen limiti aşarsa, doğrudan <code>MaxQueriesExceededException</code> fırlatılır. Geliştirici bu hatayı lokalde çözmeden kodu canlıya alamaz. Zincir DBAL katmanında gerçektir: <code>QueryCounterConnection</code>, <code>Driver</code>, <code>Statement</code> ve <code>TableParser</code>.</p>

<p><strong>4. Voter-to-SQL Dönüşümü</strong></p>

<p>Liste ekranları her satır için PHP voter çalıştırmamalıdır (bu, yetkilendirmenin N+1'idir). <code>QueryScopeApplier</code>, çağıranın <code>.own</code> / <code>.any</code> yeteneklerini sorgu veritabanına gitmeden önce Doctrine <code>WHERE</code> koşuluna çevirir.</p>

<p><strong>5. Zero-Trust Payload ve Çekirdek XSS Sterilizasyonu</strong></p>

<p>Dışarıdan gelen HTTP gövdeleri sıkı DTO'lara map edilir ve denetleyicilere ulaşmadan doğrulanır. Veritabanına gidecek zengin metin <code>RichTextSanitizer</code> ile sterilize edilir (Manifesto Law 5.3). <code>SchemaOrgBuilder</code> aynı hibrit JSON'dan JSON-LD (BlogPosting, WebPage) üretir.</p>

<p><strong>6. İyimser Eşzamanlılık</strong></p>

<p>Yüksek yazma yolları tablo kilidi yerine optimistic locking kullanır; eşzamanlı mutasyonlar deadlock tiyatrosu olmadan izole edilir. Config (roller, yetenekler) Git'teki YAML'de kalır; kullanıcılar veritabanında kalır — Symfony TreeBuilder ile Drupal tarzı config sync.</p>
HTML,
                ],
                [
                    'id' => 'platform-layer',
                    'title' => '7. Bölüm: Platform Genişletilebilirliği — API, Hook, Cron, Plugin',
                    'html' => <<<'HTML'
<p>İlk whitepaper taslağından bu yana çekirdeğe dört genişletme omurgası indi. Her biri aynı Core Never Dies zırhını taşır: çöken bir eklenti karantinaya alınır; çekirdek ayakta kalır.</p>

<p><strong>Kriptografik REST API Gateway</strong></p>

<p>Tek bir <code>/api/{path}</code> joker rotası, derleme zamanında <code>ApiRegistrationPass</code> ile <code>#[CpApi]</code> işaretli servis metotlarına bağlanır. Kimlik doğrulama <code>X-CP-API-KEY</code> header'ı, SHA-256 hash ve zamanlama-güvenli <code>hash_equals()</code> ile yapılır. Fail-closed: geçersiz anahtar hedef metoda asla ulaşmaz. Her uç kendi try/catch'inde çalışır; çöken bir metot gateway'i 500'e düşüremez.</p>

<p><strong>Çift kulvarlı izole Hook motoru</strong></p>

<p>Cotonti tarzı <code>Hooks/{hook_point}.php</code> dosyaları bağlı bir Closure içinde çalışır; <code>$this</code> sızıntısı olmaz. Symfony tarzı <code>#[CpHook]</code> servisleri <code>HookRegistrationPass</code> ile toplanır. Fırlatan hook karantina günlüğüne yazılır; sayfa yine render edilir.</p>

<p><strong>Birleşik hibrit Cron motoru</strong></p>

<p><code>CronManager</code> üç kaynağı tek listede birleştirir: <code>cp_cron_jobs</code> satırları, <code>#[CpCronJob]</code> metotları ve <code>Hooks/cron.{job}.php</code> dosyaları. Görevler izole alt süreçlerde çalışır ve <code>CronCommandWhitelist</code> ile yalnızca <code>cp:*</code> komutlarını çalıştırabilir. Operatörler <code>/aacp/cron</code> üzerinden izler ve &ldquo;Şimdi çalıştır&rdquo; der.</p>

<p><strong>Modülden bağımsız Plugin katmanı</strong></p>

<p><code>PluginInterface</code> işaretli servisler <code>PluginRegistry</code> tarafından toplanır; aktiflik <code>PluginToggleRepository</code>'dedir. Bir modül, kendisini kapatmadan opsiyonel widget'ları açıp kapatabilir.</p>

<p><strong>Lazy ayarlar</strong></p>

<p><code>#[CpSetting]</code> anahtarları <code>SettingsRegistry</code> üzerinden talep edilince yüklenir — boot'ta her anahtarı dökmez, sorulunca tek sorgu atar.</p>

<p><strong>WordPress tarzı modül paketleri</strong></p>

<p>Birinci parti bir modül <code>Resources/config/importmap.php</code> ve <code>contributions.yaml</code> taşıyabilir. <code>ModulePackageContract</code> disk düzenini doğrular; operatörler AACP'den ZIP yükleyebilir. Ana sayfa kipleri, portal akış blokları, şema türleri ve hesap giriş-sonrası iniş noktası contribution kataloğunda ilan edilir — çekirdek <code>forum_index</code> veya <code>blog_index</code>'i sabit kodlamaz.</p>
HTML,
                ],
                [
                    'id' => 'aacp-command',
                    'title' => '8. Bölüm: AACP Komuta Masası, Studio ve Kurtarma',
                    'html' => <<<'HTML'
<p>Orijinal yol haritasında vaat edilen Safe Mode / Kurtarma Konsolu artık üretim kodudur. AACP süs bir admin teması değil, sistem yönetim merkezidir.</p>

<p><strong>Kurtarma Konsolu (<code>/aacp/recovery</code>)</strong></p>

<p>Rota <code>security.yaml</code>'da bilinçli olarak <code>PUBLIC_ACCESS</code>'tir. Yetki, <code>.env</code>'deki <code>AACP_RECOVERY_TOKEN</code> ile zamanlama-güvenli <code>hash_equals()</code>'tir. Hiç Doctrine sorgusu çalıştırmaz; veritabanı yokken de ayaktadır. Boş token kapıyı kilitler (fail-safe).</p>

<p><strong>Canlı komuta masası (<code>/aacp</code>)</strong></p>

<p>Dashboard <code>/aacp/system/metrics</code>'i poll eder: PHP sürümü, load, istek süresi, bellek, OPcache hit oranı, veritabanı telemetrisi, kuyruk, cron son çalışma, modül karantinası. Cache Rebuild ve OPcache flush tek tık, CSRF korumalıdır. İçerik KPI'ları Studio <code>/admin</code>'dedir, AACP'de değil — AACP operasyon, Studio editoryal komuta masasıdır.</p>

<p><strong>Studio dashboard</strong></p>

<p>Her ilk parti modül bir <code>StudioDashboardStatsProvider</code> sunar (Blog, Forum, Medya, Menü, Roadmap, SEO). Studio Drupal tarzı bir komuta masasıdır: sayılar, kısayollar ve ana sayfa portal düzeni (blok sırası, <code>portal.{locale}.yaml</code> içinde çift dilli metin).</p>

<p><strong>Karantina, lokalizasyon, performans backend'leri</strong></p>

<p><code>/aacp/quarantine</code>, <code>module_quarantine.log</code>'un salt-okunur görünümüdür. <code>TranslationManager</code> çekirdek ve modül ICU YAML'ini atomik <code>.tmp</code> + <code>rename()</code> ile yazar. Performans ekranı Redis, Memcached, Varnish, Nginx PageSpeed ve CPalius Origin Cache'i test eder; &ldquo;aktif&rdquo; bayrağı yalnızca başarılı probe'dan sonra konur — sahte yeşil toggle yoktur. <code>/aacp/backup</code> Yedek Yönetimi masasıdır (<code>system.backup.manage</code> yeteneği): veritabanı dökümü, dosya ZIP'i veya tam arşiv; indirme ve silme dahildir.</p>
HTML,
                ],
                [
                    'id' => 'origin-cache',
                    'title' => '9. Bölüm: Origin HTML Cache ve Performans Envanteri',
                    'html' => <<<'HTML'
<p>CPalius Origin Cache, Redis, Varnish veya CDN gerektirmeyen birinci parti bir HTML önbelleğidir. Bu backend'lerin yanında durur; sade paylaşımlı hostingde de çalışır.</p>

<p><strong>İsabet nasıl servis edilir</strong></p>

<p>Açık/kapalı durumu <code>public/page-cache/</code> altındaki disk sentinel'idir. <code>public/.htaccess</code> içindeki Apache rewrite, sorgu dizesi ve oturum çerezi (<code>PHPSESSID</code>, <code>REMEMBERME</code>) olmayan anonim GET isteklerinde <code>page-cache/{path}/index.html</code> dosyasını servis eder. PHP yazmayı ve sorgu-dizesi varyantlarını <code>OriginCacheWriter</code> / <code>OriginCacheReader</code> ile okumayı sürdürür.</p>

<p><strong>Ne saklanır</strong></p>

<p>HTML isteğe bağlı minify edilir. Bağlı CSS/JS kopyalanıp minify edilebilir; görseller WebP türevi olarak üretilebilir. İsteğe bağlı HTML kalkanı işaretlemeyi yeniden yazar. TTL, hariç tutulan yollar (<code>/aacp</code>, <code>/admin</code>, <code>/login</code>, API'ler, profiler), minify, asset/görsel sıkıştırma ve kalkan <code>performance.cpalius.*</code> ayarlarıdır. Cron <code>cpalius.origin_cache.purge</code> süresi dolan dosyaları 15 dakikada bir siler. Cache Rebuild origin HTML'i de temizler.</p>

<p><strong>Geçersiz kılma</strong></p>

<p><code>OriginCachePurger</code> Blog yazı/kategori/etiket yazımlarında (zamanlanmış yayın dahil), Forum konu mutasyonlarında, Roadmap kayıt kaydında ve Menü kaydında çalışır — editoryal değişiklik diskte bayat HTML bırakmaz.</p>

<p><strong>AACP Performans paneli</strong></p>

<p>Dashboard'daki Performans bölümü, PHP sürecinin görebileceği her backend'in envanterini çıkarır:</p>

<ul>
<li><strong>Origin Cache:</strong> HTML sayfa adedi, disk baytı, son yollar (boyut ve zaman).</li>
<li><strong>Redis:</strong> <code>DBSIZE</code> (anahtar sayısı) ve <code>INFO memory</code> (<code>used_memory</code>).</li>
<li><strong>Memcached:</strong> <code>curr_items</code> ve <code>bytes</code> (daemon cevaplıyorsa hit sayısı).</li>
<li><strong>OPcache:</strong> önbellekteki script'ler, kullanılan bellek, hit oranı.</li>
<li><strong>Varnish / PageSpeed:</strong> açık/kapalı ve TTL / son probe. Nesne sayıları host'ta <code>varnishstat</code> veya PageSpeed admin ister; PHP bu sayıları uydurmaz.</li>
</ul>

<p>Probe'lar izole edilir ve birkaç saniye önbelleğe alınır; takılan bir Redis dashboard'u 500'e düşüremez.</p>
HTML,
                ],
                [
                    'id' => 'backup',
                    'title' => '10. Bölüm: Yedek Yönetimi',
                    'html' => <<<'HTML'
<p>Felaket kurtarma üçüncü parti bir eklenti değil, birinci parti bir AACP masasındır. <code>system.backup.manage</code> yeteneği <code>/aacp/backup</code> yolunu korur. Admin rolü bunu <code>*</code> ile miras alır; editörler menüyü görmez.</p>

<p><strong>Üç arşiv türü</strong></p>

<ul>
<li><strong>Veritabanı:</strong> Doctrine DBAL üzerinden PHP ile yazılan gzip SQL dökümü. <code>mysqldump</code> gerekmez; Windows'ta Laragon, Linux hosting ile aynı şekilde çalışır.</li>
<li><strong>Dosyalar:</strong> tüm CPalius ağacının ZIP'i; <code>cp-includes/vendor</code> ve tüm <code>public/</code> dizini (yüklemeler, derlenmiş asset'ler, temalar, front controller) dahildir. Dışarıda: her <code>.env*</code> dosyası, <code>.git</code>, <code>node_modules</code>, Symfony cache, Tailwind derleme önbelleği, oturumlar, Origin Cache HTML ve <code>cp-core/var/backups/</code> altındaki iç içe arşivler.</li>
<li><strong>Tam:</strong> aynı arşivin içine <code>database.sql.gz</code> eklenmiş dosya ZIP'i.</li>
</ul>

<p>Arşiv adları <code>cpalius-{db|files|full}-YYYYMMDD-HHMMSS.{sql.gz|zip}</code> biçimindedir ve <code>cp-core/var/backups/</code> altında durur (zaten gitignore). İndirme <code>/aacp/backup/archive/{stem}</code> kullanır (URL'de <code>.sql.gz</code> / <code>.zip</code> yoktur) ki nginx statik-dosya location'ı isteği kesmesin; <code>Content-Disposition</code> yine gerçek dosya adını gönderir. Silme CSRF korumalıdır. Path traversal dosya adı allowlist'i ile reddedilir. CLI <code>cp:backup:create</code> cron whitelist'indedir; operatörler web zaman aşımı olmadan döküm zamanlayabilir.</p>

<p>v1 tarayıcıdan canlı ağacı üzerine yazmaz. Geri yükleme önce staging'dir: dosyaları taze bir kurulumun yanına açın, SQL'i veritabanının bir kopyasına uygulayın, sonra yer değiştirin. Geri yüklemeyi tek tık AACP düğmesinin arkasına koymak Core Never Dies'ı ihlal eder.</p>
HTML,
                ],
                [
                    'id' => 'telemetry',
                    'title' => '11. Bölüm: Güvenlik Telemetrisi ve IP Denetimi',
                    'html' => <<<'HTML'
<p>AACP, kamu sitesini karanlık-kalıp bir izleyiciye çevirmeden istek telemetrisi tutar. Ana anahtar <code>telemetry.security_enabled</code>'dır ve varsayılanı <strong>kapalı</strong>dır.</p>

<p><strong>Ziyaretçi modu (varsayılan)</strong></p>

<p>Dashboard sayfa görüntüleme, benzersiz IP, popüler yollar ve trafik grafiği gösterir. Tehdit akışı yoktur, Ban IP yoktur. Pazarlama sitesini, operatörün yüzüne bir WAF konsolu yapıştırmadan barındırmak için uygundur.</p>

<p><strong>Güvenlik modu</strong></p>

<p>Açıldığında <code>kernel.terminate</code> üzerindeki <code>TelemetrySubscriber</code>, <code>cp_system_telemetry_logs</code>'a satır yazar: IP, kullanıcı, metot, URI, user-agent, şiddet, olay türü, tehdit skoru ve JSON ayrıntı. <code>ThreatAnalyzer</code> SQLi, XSS, tarayıcı, path traversal ve giriş gürültüsünü skorlar. Canlı akış JSON poll eder; ayrıntı modalı satırı açar; Ban IP yalnızca <code>critical</code> / <code>threat</code> şiddette sunulur.</p>

<p><strong>IP ban</strong></p>

<p><code>IpBanService</code> <code>cp_banned_ips</code>'e yazar. <code>BannedIpSubscriber</code> yasaklı istemciyi yığının geri kalanı bütçe harcamadan reddeder. Bir cron görevi eski telemetri satırlarını siler; tablo sınırsız büyüyemez.</p>
HTML,
                ],
                [
                    'id' => 'first-party',
                    'title' => '12. Bölüm: İlk Parti Modüller, Medya Pipeline ve Deep-Localization',
                    'html' => <<<'HTML'
<p>Blog, Medya, Menü, Forum, Roadmap, Pages ve SEO referans modülleridir: API, Hook, Cron, Plugin, Settings, contribution kataloğu ve Studio istatistiklerini üçüncü parti bir modülün kullanması gereken şekilde kullanırlar.</p>

<p><strong>Blog</strong> <code>Node::type = post</code> üzerindedir; kategori, etiket, zamanlanmış yayın (<code>PublishScheduledPostsTask</code>), <code>GET /api/blog/posts</code>, sidebar hook ve Schema.org BlogPosting. <strong>Pages</strong> <code>Node::type = page</code> üzerindedir; alan grupları <code>fg-</code> slug kullanır ve kamu rotası <code>/{_locale}/{slug}</code> adresindeki <code>page_show</code>'dur. <strong>Menü</strong> WordPress tarzı sürükle-bıraktır; öğeler node'a gevşek referans verir (sert FK yok) ki soft-delete dürüst kalsın. <strong>Roadmap</strong> yerli bir akıştır; portala ilişkili blog ve forum etkinliğini de çekebilir. <strong>SEO</strong> sitemap kaynakları ve JSON-LD üretir.</p>

<p><strong>Forum motoru</strong></p>

<p>Hiyerarşik panolar Node ağacını yeniden kullanır; önekler, CSRF korumalı rapor/moderasyon kuyruğu, rütbe/rozet ve 20/80 postbit (Altın Oran) CMF'in geri kalanıyla aynı yetenek modelinde çalışır.</p>

<p><strong>Medya pipeline (Manifesto Law 3.3)</strong></p>

<p><code>Asset</code> Node'dan bağımsızdır. Flysystem depolama ve sha256 dedup. <code>ImageProcessor</code> (GD) <code>crop</code> veya <code>fit</code> türevlerini <code>public/uploads/cache/</code> altına yazar; <code>cp_thumb</code> bir Asset, id, depolama anahtarı veya <code>/uploads/...</code> URL kabul eder. Başarısız türev orijinal URL'i döner (fail-soft). Kaynak değişince <code>purge()</code> tüm boyutları siler.</p>

<p><strong>Deep-localization</strong></p>

<p>Her satır kendi dilinde yaşar ve kardeşlerine <code>translation_group_id</code> UUID'si ile bağlanır; kural <code>UNIQUE(translation_group_id, locale)</code> ile zorlanır (kategoriler, etiketler, menü öğeleri, forum bölümleri dahil). <code>LocaleSwitchService</code> karşı URL'i üretir; kardeş yoksa o dilin ana sayfasına düşer — asla 404. AACP'de locale öneki yoktur; panel dili <code>cp_locale</code> çerezidir. Çeviri dosyaları atomik yazılır (<code>.tmp</code> + işletim sistemi <code>rename()</code>).</p>

<p><strong>Çekirdek arayüzde Zero Node.js</strong></p>

<p>Admin ve geliştirici kromu AssetMapper + Tailwind standalone binary kullanır. Bu yüzeyler için Node.js derlemesi yoktur. Kamu tema CSS'i yine statik bir tema varlığı olabilir.</p>
HTML,
                ],
                [
                    'id' => 'roadmap',
                    'title' => '13. Bölüm: Gelecek Yol Haritası ve Teknik İstişare (RFC)',
                    'html' => <<<'HTML'
<p>CPalius'un çekirdek güvenlik, performans ve mimari omurgası tamamlanmıştır. Bu whitepaper'ın ilk taslağından bu yana teslim edilenler: AACP Safe Mode / Kurtarma Konsolu, izole Hook sistemi, birleşik Cron motoru, REST API Gateway, dördüncü savunma hattı (<code>SafeModuleRouteLoader</code>), anlık görsel pipeline'ı (<code>cp_thumb</code>), <code>#[CpResource]</code> audit log, Studio dashboard istatistikleri, CPalius Origin Cache, AACP performans envanteri, isteğe bağlı güvenlik telemetrisi (IP ban dahil), WordPress tarzı modül paketleri ve contribution kataloğu (ana sayfa, portal, şema, hesap inişi), Pages modülü, operatör arayüz katalog bağlama ve AACP Yedek Yönetimi (<code>cp:backup:create</code>).</p>

<p>Sıradaki geliştirme sprintlerinde aşağıdaki sistemleri inşa edeceğiz:</p>

<ul>
<li><strong>Workflow &amp; State Machine:</strong> Fatura, araç, rezervasyon gibi iş kayıtlarının geçiş süreçlerini (<code>draft &rarr; preparation &rarr; sold</code>) YAML tanımlarıyla yöneten ve her geçişi otomatik audit log'a ve bildirim kuyruğuna bağlayan mekanizma. <code>CpResource::$workflow</code> alanı zaten deklare edilmiştir.</li>
<li><strong>Messenger Async Kuyruk:</strong> Gerçek bir transport (Doctrine/Redis) bağlayarak e-posta ve bildirim işlemlerini asenkron kuyruğa taşımak. <code>symfony/messenger</code> kurulu ve AACP masasında görünür; henüz aktif transport bağlı değildir.</li>
</ul>
HTML,
                ],
                [
                    'id' => 'community',
                    'title' => 'Soru ve Görüşleriniz (Topluluk İstişaresi)',
                    'html' => <<<'HTML'
<p>Bu mimariyi daha da kusursuzlaştırmak için siz değerli geliştiricilerden şu konularda geri bildirim bekliyoruz:</p>

<ul>
<li><strong>Flat Field Index Modeli:</strong> SQLite, MySQL ve Postgres uyumluluğu için tasarladığımız bu model sizce devasa veri hacimlerinde nasıl bir performans gösterir? İndeks tablosunun partition edilmesi gerekir mi?</li>
<li><strong>Config Sync Yaklaşımı:</strong> Drupal'ın config sync sistemini Symfony'nin TreeBuilder yapısıyla taklit etme fikrimiz hakkındaki düşünceleriniz nelerdir?</li>
<li><strong>Zero Node.js Israrı:</strong> Geliştirici ve admin panelinde AssetMapper + Standalone Tailwind kullanma kararımız, gelecekte çok karmaşık frontend bileşenleri yazarken bizi kısıtlar mı?</li>
</ul>

<p>Fikirlerinizi, eleştirilerinizi ve mimari önerilerinizi heyecanla bekliyoruz. CPalius, topluluğun gücüyle en sağlam uygulama framework'üne dönüşecek.</p>
HTML,
                ],
            ],
        ];
    }
}
