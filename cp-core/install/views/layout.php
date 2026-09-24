<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $lang */
/** @var array<string, string> $t */
/** @var string $step */
/** @var string $body */

$steps = [
    'requirements' => $t['step_requirements'],
    'database' => $t['step_database'],
    'site' => $t['step_site'],
];
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root { color-scheme: light; --ink: #14202b; --muted: #5c6b7a; --line: #d5dee6; --bg: #f4f7f8; --card: #fff; --accent: #0f6f8c; --bad: #9b2c2c; --ok: #1f7a4d; --warn: #8a5a00; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.5 "Segoe UI", sans-serif; color: var(--ink); background: var(--bg); }
        main { max-width: 720px; margin: 32px auto; padding: 0 16px 48px; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; }
        h1 { font-size: 1.5rem; margin: 0; }
        .langs a { color: var(--muted); margin-left: 10px; }
        .langs a.active { color: var(--accent); font-weight: 700; }
        .steps { display: flex; gap: 8px; margin: 0 0 16px; padding: 0; list-style: none; }
        .steps li { flex: 1; padding: 8px 10px; background: var(--card); border: 1px solid var(--line); border-radius: 8px; color: var(--muted); font-size: .85rem; }
        .steps li.on { border-color: var(--accent); color: var(--ink); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        p.lead { color: var(--muted); margin-top: 0; }
        label { display: block; font-weight: 600; margin: 14px 0 4px; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; }
        .row { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; }
        button, .button { display: inline-block; margin-top: 18px; background: var(--accent); color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font: inherit; cursor: pointer; text-decoration: none; }
        a.ghost { background: transparent; color: var(--accent); margin-right: 8px; }
        .error { background: #fdecec; color: var(--bad); padding: 10px 12px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 8px 0; border-bottom: 1px solid var(--line); vertical-align: top; }
        td:last-child { text-align: right; color: var(--muted); }
        .fail { color: var(--bad); font-weight: 700; }
        .warn { color: var(--warn); }
        .ok { color: var(--ok); }
        code { font-size: .9em; word-break: break-all; }
        .actions { display: flex; gap: 12px; align-items: center; }
    </style>
</head>
<body>
<main>
    <header>
        <h1><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <nav class="langs">
            <a href="?lang=tr"<?= $lang === 'tr' ? ' class="active"' : '' ?>>TR</a>
            <a href="?lang=en"<?= $lang === 'en' ? ' class="active"' : '' ?>>EN</a>
        </nav>
    </header>
    <?php if (isset($steps[$step])): ?>
        <ol class="steps">
            <?php foreach ($steps as $id => $label): ?>
                <li<?= $id === $step ? ' class="on"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <section class="card">
        <?= $body ?>
    </section>
</main>
</body>
</html>
