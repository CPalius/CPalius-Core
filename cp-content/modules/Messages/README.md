# Messages

Private messaging between members. Inbox, quotas, blocks, reports, moderator
restrictions. **CPalius core never depends on this module.** Forum and Showcase
do not import it either: they publish hook points, and this module attaches
buttons when it is active.

`provides: ["messaging"]` so a future CRM may hard-require it. Forum and
Showcase do not.

---

## What it is not

- Not the core notification inbox (that stays in core; this module *uses* it).
- Not Symfony Messenger (the queue).
- Not a Forum or Showcase feature.

## Public pages

| URL | Who |
|---|---|
| `/{locale}/messages` | Member inbox |
| `/{locale}/messages/yaz` | Compose |
| `/{locale}/messages/{publicId}` | Thread |
| `/{locale}/messages/engellenenler` | Block list |
| `/admin/messages` | Moderator desk |

## Settings

Hourly / daily send caps, daily new-thread cap, flood window, minimum account
age, default privacy (`everyone` / `contacts` / `nobody`), master enable switch.
Zero on a cap means unlimited. `messages.quota.exempt` bypasses caps.

Members set “who may start a conversation with me” on the account profile.
Moderators can suspend messaging per user from the quota screen.

## Integration (no class imports)

Hook points this module listens on:

- `forum.user.actions` — “Send message” on a forum profile
- `forum.post.author.actions` — same on a post bit
- `showcase.item.actions` — “Contact seller”
- `theme.header.alerts` — envelope + unread flyout next to the avatar
- `forum.user.rail` — inbox link on the forum profile rail

Host templates add `{{ cp_hook('…', {…}) }}`. If Messages is not installed the
hook is empty.

A conversation may store an opaque context (`showcase_item:42`) so the same
pair talking about two listings gets two threads. The related URL is stored as
a string; if the source module is later disabled the label remains and the
link is simply not clicked.

Twig helpers for themes:

```twig
{{ cp_messages_unread() }}
{{ cp_messages_compose_url(user, {context_type: 'showcase_item', context_id: item.id}) }}
```

## Capabilities

```
messages.send
messages.report
messages.block
messages.moderate
messages.report.moderate
messages.settings.manage
messages.quota.exempt
```

The installer grants the member set to `member` and the moderator set to
`editor`. Uninstall removes exactly those lines.

While a page is open, the theme polls core `GET /hesap/nabiz`. This module
adds a `messages` channel (unread count, latest id, recent threads) plus a
short MP3 at `/{locale}/messages/notify.mp3`. A new incoming message plays
that sound once the tab has had a click or keypress (browser autoplay rules).

## Security

Writes go through `MessagesThreadManager` only. Bodies are stored via
`TextFormatProcessor::sanitizeForStorage()`; the format is re-resolved so a
member cannot post `full_html` by editing a hidden field. Context URLs must be
http(s) or a site-relative path. CSRF on every POST. FloodService plus SQL
quotas. Blocks work in either direction. Reports are unique per reporter+message.
Private messages are not in global search.
