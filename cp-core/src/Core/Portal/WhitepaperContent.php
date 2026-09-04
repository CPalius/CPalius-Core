<?php

declare(strict_types=1);

namespace App\Core\Portal;

/**
 * Structured, per-locale body of the CPalius CMF whitepaper (v1.0.0-draft).
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
<strong>Version:</strong> v1.0.0-draft<br>
<strong>Audience:</strong> Senior PHP Developers, System Architects, Open Source Contributors, and AI Agents<br>
<strong>Author / Founder:</strong> Ali Çömez (slaweally)<br>
<strong>Technology Stack:</strong> PHP 8.2+, Symfony 7.4 LTS, Doctrine ORM, AssetMapper, Tailwind CSS Standalone Binary</p>

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

<p>To stop N+1 query mistakes&mdash;the most common cause of database bloat and slowness&mdash;a Doctrine DBAL middleware fires only in the dev environment. If the number of queries to the same table in a single HTTP request exceeds a set limit, it throws <code>MaxQueriesExceededException</code> immediately. Developers cannot ship code to production until they fix that error locally.</p>
HTML,
                ],
                [
                    'id' => 'roadmap',
                    'title' => 'Section 7: Future Roadmap and Technical Consultation (RFC)',
                    'html' => <<<'HTML'
<p>The core security, performance, and architectural backbone of CPalius is complete. Since this whitepaper's first draft, several roadmap items have shipped: the AACP Safe Mode / Recovery Console, the isolated Hook system, the unified Cron engine, the REST API Gateway, the fourth defense line (<code>SafeModuleRouteLoader</code>), the on-demand image pipeline (<code>cp_thumb</code>), and the <code>#[CpResource]</code> audit log.</p>

<p>In upcoming development sprints we will build the following systems:</p>

<ul>
<li><strong>Workflow &amp; State Machine:</strong> A mechanism that manages transition processes for business records such as invoices, vehicles, and reservations (<code>draft &rarr; preparation &rarr; sold</code>) via YAML definitions, and automatically ties every transition to the audit log and notification queue.</li>
<li><strong>Pimcore-style Independent Asset System:</strong> A modern media library that frees media from being a Node subtype and offers on-the-fly image derivation over URLs via Flysystem (S3, MinIO).</li>
<li><strong>Messenger Async Queue:</strong> Wiring a real transport (Doctrine/Redis) so email and notification work move onto an asynchronous queue.</li>
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
<strong>Sürüm:</strong> v1.0.0-draft<br>
<strong>Hedef Kitle:</strong> Kıdemli PHP Geliştiricileri, Sistem Mimarları, Açık Kaynak Geliştiricileri ve Yapay Zeka Ajanları<br>
<strong>Yazar / Kurucu:</strong> Ali Çömez (slaweally)<br>
<strong>Teknoloji Yığını:</strong> PHP 8.2+, Symfony 7.4 LTS, Doctrine ORM, AssetMapper, Tailwind CSS Standalone Binary</p>

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

<p>Veritabanı şişmelerinin ve yavaşlıklarının en yaygın sebebi olan N+1 sorgu hatalarını engellemek için sadece dev ortamında tetiklenen bir Doctrine DBAL middleware'i devrededir. Tek bir HTTP isteğinde aynı tabloya atılan sorgu sayısı belirlenen limiti aşarsa, doğrudan <code>MaxQueriesExceededException</code> fırlatılır. Geliştirici bu hatayı lokalde çözmeden kodu canlıya alamaz.</p>
HTML,
                ],
                [
                    'id' => 'roadmap',
                    'title' => '7. Bölüm: Gelecek Yol Haritası ve Teknik İstişare (RFC)',
                    'html' => <<<'HTML'
<p>CPalius'un çekirdek güvenlik, performans ve mimari omurgası tamamlanmıştır. Bu whitepaper'ın ilk taslağından bu yana birçok roadmap maddesi teslim edildi: AACP Safe Mode / Kurtarma Konsolu, izole Hook sistemi, birleşik Cron motoru, REST API Gateway, dördüncü savunma hattı (<code>SafeModuleRouteLoader</code>), anlık görsel pipeline'ı (<code>cp_thumb</code>) ve <code>#[CpResource]</code> audit log.</p>

<p>Sıradaki geliştirme sprintlerinde aşağıdaki sistemleri inşa edeceğiz:</p>

<ul>
<li><strong>Workflow &amp; State Machine:</strong> Fatura, araç, rezervasyon gibi iş kayıtlarının geçiş süreçlerini (<code>draft &rarr; preparation &rarr; sold</code>) YAML tanımlarıyla yöneten ve her geçişi otomatik audit log'a ve bildirim kuyruğuna bağlayan mekanizma.</li>
<li><strong>Pimcore Tarzı Bağımsız Asset Sistemi:</strong> Medyayı Node'un bir alt tipi yapmaktan kurtarıp, Flysystem (S3, MinIO) ile URL üzerinden anlık görsel türetme sunan modern dosya kütüphanesi.</li>
<li><strong>Messenger Async Kuyruk:</strong> Gerçek bir transport (Doctrine/Redis) bağlayarak e-posta ve bildirim işlemlerini asenkron kuyruğa taşımak.</li>
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
