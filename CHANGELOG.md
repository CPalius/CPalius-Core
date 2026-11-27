# Changelog

One line per release, generated from the release feed. Full notes: https://com.net.tr/CPalius/releases/<version>.md

## 2.2.72 - 2026-11-27

Security updates for Symfony and Twig; choose your own Studio, AACP and sign-in addresses in .env; AACP sign-in, update check and vocabulary lookup fixes.

## 2.2.71 - 2026-11-27

Fixes homepage editor changes never showing on the live page; includes the 2.2.70 installation wizard fixes.

## 2.2.70 - 2026-10-29

Fixes the installation wizard: module-aware core migrations, cp:update during install, cold cache, savepoint reset, safe logging, website theme without Forum.

## 2.2.69 - 2026-10-16

Fixes the Appearance menu showing on the module store and makes the Forum services tolerate a stale compiled container after a module-only update (Forum 1.4.3).

## 2.2.68 - 2026-10-16

Homepage editor with desktop/mobile preview and a site access mode, a repaired guest page cache, a performance pass measured on 100k users and 200k posts, and a security round: secret key rotation, step-up authentication, extension advisories, restricted-capability marking and CSP advice.

## 2.2.67 - 2026-10-16

Fixes the module scaffold command and migration rollback after a cleared entity manager, adds a committed test configuration, and carries the 2.2.65 and 2.2.66 building blocks to the stable channel.

## 2.2.64 - 2026-10-16

Theme updates now show their new CSS and JS: the published copy is refreshed when the file size changes.

## 2.2.63 - 2026-10-16

Updates stop resetting edited roles and whitepaper text; Showcase and Messages updates keep your menu links.

## 2.2.62 - 2026-10-16

Update all continues by itself; refused extension packages say why; Githeme 1.0.2 drops its PHP dev script; catalogue import keeps edited showcase entries.

## 2.2.61 - 2026-10-16

Deleting a module removes all of its data, the homepage module cannot be switched off, themes declare the modules they need and fall back to the default theme, and paid extensions are listed without a download.

## 2.2.56 - 2026-10-13

AACP menus reorganised (no linking dropdowns, one Security Centre menu), new module source editor, and an update hook removes the stale /aacp/extensions store.

## 2.2.55 - 2026-10-13

AACP gets a fixed Modules menu (modules, module store, module plugins, module editor) and the theme store moves under Appearance.

## 2.2.54 - 2026-10-13

Update check no longer fails on channel builds ahead of stable; AACP store gets an Update all button and the duplicate Modules menu entry is gone.

## 2.2.52 - 2026-10-13

Adds the league/commonmark library for the new Docs module; no core behaviour change.

## 2.2.51 - 2026-10-12

New Tools menu in the AACP (module menu items are filed there by rule), multi-select in the media picker, Field API image and multi-value widgets, header overflow fixes and the editor media-embed fix.

## 2.2.50 - 2026-10-12

The security filter no longer blocks forum posts that mention file names such as .env or wp-login.php. Only the post body is exempt on the forum's new topic, reply, edit and draft routes.

## 2.2.49 - 2026-10-12

The malware scanner no longer flags its own rule file or the core code, and the Showcase entry description is now a rich text editor with Save and Cancel at the bottom.

## 2.2.48 - 2026-10-12

A private update channel for early builds, plus a Featured checkbox and list toggle in the Showcase; the catalogue sync button is gone.

## 2.2.47 - 2026-10-12

The store now has previews for every module and theme, activate/deactivate/remove actions, and the Showcase can be filled from the signed catalogue with one button.

## 2.2.46 - 2026-10-12

Extension store: install and update free modules and themes from a signed catalogue; core updates no longer overwrite modules and themes. Plus an optional malware scanner, live search in AACP and Studio, a WAF fix for saving articles with code samples, and theme font publishing.

## 2.2.45 - 2026-10-12

Release information is now Ed25519-signed and verified, and updates run in maintenance mode with a lock, atomic file writes, a database snapshot and automatic rollback of files and database when a step fails or the process dies.

## 2.2.44 - 2026-10-12

Cookie consent gains Necessary, Analytics and Marketing categories with Google Consent Mode v2, the 404 log ignores assets and bots and creates a 301 in one click, menus can be assigned to theme locations with cp_menu_location(), the AI module can use many AI services, and the panel is faster: icons are bundled instead of fetched, thumbnails no longer stall a page, and module switching no longer waits for a rebuild.

## 2.2.43 - 2026-10-06

Githeme redesigned on a dimmed grey GitHub-style system (dark default, light twin, two-row header with current-tab marker, framed forum boxes, generated recolour of every Default page), the forum statistics card now sits in the page column in theme colours, and AACP theme cards show real screenshots.

## 2.2.42 - 2026-10-06

New Githeme theme (light/dark) with a Themes list and previews in AACP, per-theme appearance settings, the forum statistics card on every public page, a larger All tools button in DNS tools, Showcase SEO, noindex private messages, inline handlers removed from the admin (strict CSP ready), safer theme ZIPs, and a fix for the Search Console forum "text, image or video" error. Cron now needs POST with a Bearer token: update your crontab.

## 2.2.41 - 2026-10-02

Media library upload queue and a settings screen for allowed file types and size, independent card columns with the card editor on the AACP dashboard too, soft-deleted content in the recycle bin with translated type names, and fixes for the AI users manifest and repeated configuration imports.

## 2.2.40 - 2026-10-02

Drag-and-drop Studio dashboard cards with an edit dialog and per-module cards, Yandex Metrica in Visitor statistics, a new AI users module, the Studio profile picture, and fixes for the media manager, centred modals and the AACP cache-file warning.

## 2.2.39 - 2026-10-02

Fixes the AACP page layout broken in 2.2.38, a rebuilt translation editor, a sticky quick-edit panel for blog writers, a cover-image height limit on posts, and the missing settings fields in the widget editor.

## 2.2.38 - 2026-10-02

System-wide recycle bin with retention settings, a media library with folders and a media trash, a Languages screen with AI-assisted translation, a visitors card on the Studio dashboard, delete confirmations, and forum avatars where letters were shown.

## 2.2.37 - 2026-10-02

Profile button shows your name instead of e-mail; richer Premium dashboard without the logo plate; all three Studio themes tuned for phones and tablets.

## 2.2.36 - 2026-10-02

Studio gets three themes shown as pictures in AACP → Studio appearance: new Premium, the original Classic and the flat Newspaper layout, plus mode, accent colour and typeface choices.

## 2.2.35 - 2026-10-02

Studio has a new flat, ruled look with light and dark modes and phone support; administrators pick the style (Process, Key or Spot colour) in AACP → Appearance → Studio appearance.

## 2.2.34 - 2026-10-02

Widgets rebuilt (Studio → Widgets: 17 types, per-language texts, page/audience rules, drag ordering, ready-made layouts, automatic upgrade of the old sidebar); AI article writer removed; cookie consent banner; 404 log with one-click redirects; menu rel/icon/CSS class; page CSS/JS tucked into Custom fields; AI translation refresh for pages; SEO shows only active modules. New tables are created on update.

## 2.2.33 - 2026-10-01

AI article writer in Blog → New post (seven providers incl. keyless Pollinations, live free-model lists); new Studio → Blog → Widgets screen; writers get an edit/publish toolbar on their own posts and can preview drafts; administrators are notified (bell) of username/e-mail change requests; big SEO upgrade (health check, llms.txt, RSS, IndexNow, FAQ markup, AI-crawler switches, sitemap and title-template fixes). No schema changes.

## 2.2.32 - 2026-10-01

Blog: "View on site" button and link after saving, categories created/edited in a pop-up, WordPress-style tag autocomplete, and editing an AI-translated post can update its AI translations too. SEO: every module (Blog, Forum, Roadmap, DNS Tools) now has its own landing-page title, description and keywords in the SEO module. No schema changes.

## 2.2.31 - 2026-09-30

Homepage SEO now follows the SEO module settings (title, description, Open Graph) instead of built-in theme text; new portal templates (Simple, Module feed, CPalius showcase) edited per language in the admin panel; the forum topic edit page keeps attachments, polls and the description; AI translation on edit updates existing translations in place and re-translates only what changed. Includes everything from 2.2.30. No schema changes.

## 2.2.30 - 2026-09-29

A module chosen as homepage (Blog, Forum, Showcase, DNS Tools...) is now served at the homepage (/tr, /en) instead of redirecting to its own address; the old address 301s to the homepage in the same language, and menus, canonical, sitemap, pagination and the language switcher point at the homepage. The sitemap cache is now per domain, so it no longer lists another domain's URLs. No schema changes.

## 2.2.29 - 2026-09-28

Updates now come from the new update server (https://com.net.tr/CPalius/) instead of GitHub, whose private release archives answered 404 so one-click updates could not download a package. The website footer reads 'Powered by MEGABRE' with only MEGABRE linked. Also fixes the website theme's homepage answering 500 on fresh installs (it linked to the Whitepaper/Roadmap modules, which are off by default). No schema changes.

## 2.2.28 - 2026-09-26

The shortcut bar under forum pages is removed; while browsing the forum, the header user menu opens in two columns with a Forum column (unread, my topics, drafts, mark read). Elsewhere the menu is unchanged. No schema changes.

## 2.2.27 - 2026-09-26

Blog list layout fix: the cover image could run about 40px under the post title and excerpt (aspect-ratio plus height:100% made its width follow the row height); it now stays in its column, and posts without a cover no longer leave an empty column. No schema changes.

## 2.2.26 - 2026-09-26

Design, accessibility and security pass across the homepage, blog and forum: member bios and post bodies are sanitized at render time (the importer bypassed the admin-form sanitizer); long forum topics no longer stay blank on phones; accessible contrast, focus ring, skip link and landmarks; keyboard and touch support for forum widgets; locale-aware blog dates; thumbnails for covers and avatars; new homepage hero; forum feeds off the homepage; navigation collapses below 1200px. No schema changes.

## 2.2.25 - 2026-09-26

Public forum profiles, the reputation list and notification sender names showed members e-mail addresses whenever an account had no first/last name (nearly every account imported from XenForo) -- now name, then username, never the e-mail. Adds forgot-password / reset-password (single-use one-hour link, hashed token, rate-limited, signs out everywhere). Forum topics and blog posts now pass Google structured-data checks (text, comment[], author.url; software posts no longer emit an always-invalid SoftwareApplication; videos get thumbnailUrl/uploadDate), titles are no longer double-escaped, shared posts carry their own og:title. Sign-in screens show the CPalius logo and the site name. No schema changes.

## 2.2.24 - 2026-09-25

Visual redesign of the VisitorStats admin page's charts, no data or schema changes: the 31-day view moves from plain CSS bars to a smooth two-series Chart.js line/area chart (total visits + unique visitors, with a legend), and the 12-month view moves to a Chart.js bar chart -- both using the same charting library already bundled for the AACP dashboard's own trend chart.

## 2.2.23 - 2026-09-25

Registration email verification and login email verification were the same setting read live at two moments -- turning on verification for new signups immediately refused login for every existing account too. Added an independent 'Refuse Login Until Verified' setting (defaults on, no behavior change for existing sites). Fixed an uncaught InvalidTypeException crashing any admin user save (including password changes) when the bio field was empty -- a Symfony TextareaType/nullable-property mismatch. Gave a routine 403 (posting without rights) a proper log message instead of an empty one.

## 2.2.22 - 2026-09-25

Design follow-up to 2.2.21: Security Center's live-threats card and audit-findings card merge into one card with two tabs (threats first), and the threat table's uneven JSON-details column is dropped. The VisitorStats admin page's single table is replaced with two CSS bar charts from the same existing data — 31 daily bars, 12 monthly bars summed from the daily rows, both zero-filled so no day/month is silently skipped.

## 2.2.21 - 2026-09-25

Security Center now shows live threat data (feed, vector breakdown, top offending IPs) instead of only a posture checklist and a bare IP-ban form. Page-view counting is split out of core into an optional VisitorStats module (core depends only on two small interfaces, nullable) with its own admin page showing real daily history; an update hook activates the module automatically on upgrade so existing sites keep counting visitors exactly as before.

## 2.2.20 - 2026-09-25

Telemetry and visitor statistics no longer share a table: page views are counted into three small new tables instead of logging one row per hit, and clearing security telemetry can no longer wipe visitor history with it (previously the same purge action, unfiltered by event_type, deleted both). Also moves the forum's unread/drafts/my-topics/mark-all-read row from the top of the board to the bottom, restyled to match the stats bar it now sits beside.

## 2.2.19 - 2026-09-25

A busy AACP page could log a caught, non-fatal MaxQueriesExceededException from update-hook inspection: several individually-correct, per-request-memoized internal settings readers (UpdateHookLedger, ReleaseChecker) each ran their own separate query against cp_settings, and enough of them on one page tripped the ten-per-table N+1 guard. Added SettingsRegistry::getRaw() so internal bookkeeping keys share the same batch-loaded, cached values map as everything else instead of querying separately.

## 2.2.18 - 2026-09-25

Fixes the same class of container-rebuild crash 2.2.11-2.2.16 chased, in a place none of them touched: AACP's module delete/deactivate. ModuleActivator::clearCache() hand-rolled its own synchronous, non-rebuilding cache:clear instead of using CacheRebuildManager, guaranteeing an inline compile on the admin's own next click; deleting a module also removed its files before any cache clearing, so its Twig template path (baked into the still-current container) threw LoaderError on every request until the container caught up. Both fixed: routed through CacheRebuildManager, file removal deferred until after the rebuild.

## 2.2.17 - 2026-09-25

No functional change — a diagnostic release. Every file is identical to 2.2.16 except the version constant, to test whether the update-time container crash still occurs when the code installing the update is already 2.2.16 (a one-time transitional effect of self-updating a live PHP process was the suspected remaining cause, not a bug in 2.2.16 itself).

## 2.2.16 - 2026-09-25

Removes the forced, mid-request cache-directory purge path entirely — the second, unfixed cause of the update-time crash 2.2.11-2.2.15 kept encountering. Every cache clear is now unconditionally deferred to kernel.terminate, so nothing running afterward in that request (including Symfony's own automatic kernel.response/terminate listeners) can ever reach for a container file the request itself just deleted.

## 2.2.15 - 2026-09-25

The actual root fix for the update-time container crash 2.2.11-2.2.14 addressed adjacent symptoms of: the compiled container was being cleared before migrations ran in the same request, not after, deleting files a lazily-resolved service needed a moment later. Reordered so the container now survives until migrations (and the equivalent resume/rollback recovery actions) are done with it.

## 2.2.14 - 2026-09-25

Root fix for the container-rebuild-after-cache-clear gap 2.2.12/2.2.13 only closed at two of its eight call sites: the guarantee now lives inside the one shared low-level method every clearSymfonyCache() caller already goes through, so it applies everywhere, including places not yet individually patched. A failed rebuild's real output is now visible on the AACP Updates screen instead of only surfacing as an unrelated downstream crash.

## 2.2.13 - 2026-09-25

AACP's manual "Clear Symfony Cache" button now rebuilds the container the same way 2.2.12 made updates do, instead of leaving it empty for whichever request compiles it next. Also documents a one-time container error you may see during the specific request that installs 2.2.12 or later from an older version — expected, not a new bug; see the release notes for the one-time manual recovery step.

## 2.2.12 - 2026-09-25

Reliability fix: the compiled DI container is now rebuilt in its own dedicated process immediately after an update clears it, instead of being left to whichever request happens to compile it next — closing the window that let an update's own automatic follow-up step (or a live visitor) hit a partially-built container and fail.

## 2.2.11 - 2026-09-25

Reliability fix: a module deleted by hand (files removed without deactivating first) could crash unrelated pages instead of being quarantined; Forum's post rendering no longer hard-depends on the Importer module; seven call sites reading performance-backend status now share one per-request lookup instead of each querying independently, closing a recurring query-budget failure.

## 2.2.10 - 2026-09-24

Security release: encrypts a Redis password that was stored in plain text, gives each install its own Redis session-key prefix, closes a pre-auth account-enumeration leak at login, sanitizes AI-translated content and forum bios before they render, and closes a DNS-rebinding bypass in the DnsTools SSRF guard.

## 2.2.9 - 2026-09-24

A full-release update now auto-completes update hooks, module upgrades, config import and cache rebuild after files/schema land, instead of leaving them pending until a second manual click.

## 2.2.8 - 2026-09-24

Fixes a shared-Redis cache-namespace collision: two CPalius installs on the same Redis server could overwrite each other's cached site settings.

## 2.2.7 - 2026-09-24

Fixes a module migration parser bug that could leave a table or column never created, a stale-container bug in the asset compiler, and isolates modules that wire themselves directly onto Symfony kernel events.

## 2.2.6 - 2026-09-24

Updates keep the site's module on/off list and still refresh module code.

## 2.2.5 - 2026-09-24

Updates keep the shipped Tailwind CSS instead of deleting the stylesheet manifest when the server cannot rebuild it.

## 2.2.4 - 2026-09-24

On-demand update check, appearance controls for logo and layout, designed DNS homepage, visual spam score, and a zip installer.

## 2.2.3 - 2026-09-23

Importer SQL dump or remote MySQL, selectable steps, board file folders (avatars and attachments), leftover BBCode rewrite, login e-mail code, and AACP full profile.

## 2.2.2 - 2026-09-23

Per-user capability overlay on AACP (deny wins, system.* not grantable), themed 404/403 pages, and Studio SEO redirects for dead URLs.

## 2.2.1 - 2026-09-23

Updates compile the importmap after files land; rebuild buttons use /js/studio-rebuild.js instead of a hashed AssetMapper file.

## 2.2.0 - 2026-09-22

DnsTools as a first-class module, header search, keyed REST, Studio rebuild desk, and tagged sitemap sources.

## 2.1.4 - 2026-09-22

Hotfix: cp:update adds the forum translation-group column that 2.1.3 mapped but did not apply, and pending module SQL runs even when module.json did not move.

## 2.1.3 - 2026-09-22

Independent Ai translations for Forum, Blog and Pages; live Studio and site search; the profile inbox shows only unread alerts.

## 2.1.2 - 2026-09-22

Forum O(1) counters, Deny-Wins ACL, passworded sections, and a Studio desk that keeps structure, permissions and cases apart.

## 2.1.1 - 2026-09-21

Forum spoilers that do not leak, optional visitor-only postbit and body hiding, a self-hosted font library, a homepage picker that names dormant modules, a cron lock against overlapping jobs, and a Studio shell a module can claim.

## 2.1.0 - 2026-09-18

Two-step verification gains an e-mail method, AACP sits behind a gate question, idle sessions are revoked on a schedule, and the forum gains a per-member word filter, self-service signatures, anti-bump and a longer edit window for the opening post.

## 2.0.5 - 2026-09-18

Patch: mention suggestion avatars no longer open full size, private messages gain mark-all-read, My Topics is paginated, and the who-is-online count splits into members, guests, search engines and bots.

## 2.0.4 - 2026-09-18

Maintenance mode now actually serves a page, operators can create and send their own mail templates, the cron URL and task schedules are on screen, and the forum gains mention autocomplete, hover cards, selection quoting, a postbit designer and a richer editor. Carries the unpublished 2.0.2 and 2.0.3 changes.

## 2.0.1 - 2026-09-16

Fixes a 2.0.0 update that could take a site down: migrations now run in the same request that writes the files, before anything renders.

## 2.0.0 - 2026-09-16

Every table gains the cp_ prefix, the database's three collations become one, and four duplicated forum tables become two. Major: renamed tables and four removed entity classes break modules that name them.

## 1.1.3 - 2026-09-16

The patch installer no longer renders a page against a stale compiled container, and a failed cache purge is now reported instead of silent.

## 1.1.2 - 2026-09-16

Per-locale, admin-editable mail templates; e-mail/username changes need admin approval; reputation takes a link instead of a topic; 404s stop filling the error log.

## 1.1.1 - 2026-09-14

Recovery from an interrupted update: the PHP time limit is lifted, and a half-finished update can be resumed or rolled back.

## 1.1.0 - 2026-09-14

Remote storage targets (S3, R2, FTP), off-site backups, an image CDN, and file-level patch updates.

## 1.0.0 - 2026-09-14

First stable release.
