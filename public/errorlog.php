<?php

/*
|--------------------------------------------------------------------------
| TEMPORARY DEVELOPMENT TOOL — DELETE BEFORE PRODUCTION
|--------------------------------------------------------------------------
| Reads storage/logs/laravel.log over HTTP.
|
|   rm public/errorlog.php
|
| Deliberately standalone: it does NOT boot Laravel, open a database connection
| or render a Blade view. That is the entire point of it being a raw file —
| this server has already hit a compiled-view permission error and a database
| outage, and a diagnostics page that depends on either is useless exactly when
| it is needed. This one keeps working when the application does not.
|
| SECURITY. Wide open by default, which is what makes it convenient and also
| what makes it dangerous: the log holds email addresses, SQL with live values,
| API tokens in stack traces and full request context. Set LOG_VIEWER_SECRET in
| .env and this file will start requiring it — no edit needed:
|
|   https://myprojectblock.dev/errorlog.php?key=<secret>
|
| There is a Laravel-routed equivalent at /errorlog.php handled by
| App\Http\Controllers\Dev\LogViewerController, which is always gated. This file
| shadows it, because the web server serves a real file before it reaches PHP's
| front controller — so deleting this file restores the guarded route.
*/

declare(strict_types=1);

const TAIL_BYTES = 2_000_000;
const LIMIT = 200;

$root = dirname(__DIR__);

/**
 * Read one key out of .env without booting anything.
 *
 * A five-line parser rather than a dependency: the moment this needs Laravel's
 * config it inherits Laravel's failure modes, which is what it exists to avoid.
 */
$env = static function (string $key, string $default = '') use ($root): string {
    static $lines = null;

    if ($lines === null) {
        $file = $root.'/.env';
        $lines = is_readable($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);

        if (trim($name) === $key) {
            return trim(trim($value), "\"'");
        }
    }

    return $default;
};

// ---- gate ---------------------------------------------------------------------------------
// Only enforced when a secret exists, so the file is directly accessible until you decide
// otherwise. `hash_equals` so a set secret cannot be probed a character at a time.
$secret = $env('LOG_VIEWER_SECRET');

if ($secret !== '') {
    $given = (string) ($_GET['key'] ?? '');

    if ($given === '' || ! hash_equals($secret, $given)) {
        // 404 rather than 403: a 403 confirms the path is right.
        http_response_code(404);
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Robots-Tag: noindex, nofollow');

// ---- read ---------------------------------------------------------------------------------
$path = $root.'/storage/logs/laravel.log';
$level = strtoupper((string) ($_GET['level'] ?? ''));
$needle = trim((string) ($_GET['q'] ?? ''));

$entries = [];
$note = '';

if (! is_file($path)) {
    $note = 'No log file at '.$path;
} elseif (! is_readable($path)) {
    // Worth naming explicitly: this server's recurring problem is file ownership, and
    // "no entries" would otherwise look like "no errors".
    $note = 'The log file exists but is not readable by the web user — check ownership on storage/logs.';
} else {
    $size = filesize($path) ?: 0;
    $handle = fopen($path, 'rb');

    if ($handle !== false) {
        if ($size > TAIL_BYTES) {
            fseek($handle, -TAIL_BYTES, SEEK_END);
            fgets($handle); // discard the partial first line
        }

        $raw = (string) stream_get_contents($handle);
        fclose($handle);

        // An entry starts `[2026-08-19 14:17:45] local.ERROR:` and runs until the next one, so
        // a stack trace stays attached to its message instead of becoming 40 orphan lines.
        $parts = preg_split(
            '/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+)\]\s+([\w-]+)\.(\w+):/m',
            $raw,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        ) ?: [];

        for ($i = 1; $i + 3 <= count($parts); $i += 4) {
            $entries[] = [
                'time' => trim($parts[$i]),
                'level' => strtoupper(trim($parts[$i + 2])),
                'body' => trim($parts[$i + 3]),
            ];
        }

        $entries = array_reverse($entries);

        if ($level !== '') {
            $entries = array_values(array_filter($entries, static fn ($e) => $e['level'] === $level));
        }

        if ($needle !== '') {
            $entries = array_values(array_filter(
                $entries,
                static fn ($e) => stripos($e['body'], $needle) !== false
            ));
        }

        $entries = array_slice($entries, 0, LIMIT);
    }
}

$e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$tone = [
    'ERROR' => '#ef4444', 'CRITICAL' => '#ef4444', 'ALERT' => '#ef4444', 'EMERGENCY' => '#ef4444',
    'WARNING' => '#f59e0b', 'NOTICE' => '#3b82f6', 'INFO' => '#22c55e', 'DEBUG' => '#6b7280',
];

$sizeLabel = is_file($path) ? number_format((filesize($path) ?: 0) / 1024, 1).' KB' : '—';
$mtime = is_file($path) ? date('Y-m-d H:i:s', (int) filemtime($path)) : '—';
$key = $secret !== '' ? (string) ($_GET['key'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Error log</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;background:#0f1115;color:#d7dae0}
  .warn{background:#7f1d1d;color:#fff;padding:7px 18px;font:600 12px system-ui,sans-serif}
  header{padding:14px 18px;border-bottom:1px solid #232733;background:#151821;position:sticky;top:0;z-index:2}
  h1{margin:0 0 6px;font-size:15px;color:#fff;font-family:system-ui,sans-serif}
  .meta{color:#7d8492;font-size:12px}
  form{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}
  input,select,button{font:12px ui-monospace,monospace;background:#0f1115;color:#d7dae0;border:1px solid #2b3140;border-radius:6px;padding:6px 9px}
  button{background:#2563eb;border-color:#2563eb;color:#fff;cursor:pointer;font-weight:600}
  main{padding:12px 18px 60px}
  .entry{border:1px solid #232733;border-radius:8px;margin-bottom:8px;background:#151821;overflow:hidden}
  summary{cursor:pointer;padding:9px 12px;display:flex;gap:10px;align-items:baseline;list-style:none}
  summary::-webkit-details-marker{display:none}
  .lvl{color:#fff;font-size:10px;font-weight:700;border-radius:4px;padding:2px 6px;flex-shrink:0}
  .time{color:#7d8492;font-size:11px;flex-shrink:0}
  .msg{color:#e6e9ef;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  pre{margin:0;padding:12px;border-top:1px solid #232733;background:#0f1115;white-space:pre-wrap;word-break:break-word;color:#a9b0bd;font-size:12px;max-height:460px;overflow:auto}
  .none{color:#7d8492}
</style></head><body>

<div class="warn">TEMPORARY DEBUG TOOL — delete <code>public/errorlog.php</code> before production<?= $secret === '' ? ' · UNPROTECTED: set LOG_VIEWER_SECRET in .env to require a key' : ' · protected by LOG_VIEWER_SECRET' ?></div>

<header>
  <h1>Error log</h1>
  <div class="meta"><?= $e($path) ?> &middot; <?= $e($sizeLabel) ?> &middot; last written <?= $e($mtime) ?> &middot; newest first, max <?= LIMIT ?></div>
  <form method="get">
    <?php if ($key !== ''): ?><input type="hidden" name="key" value="<?= $e($key) ?>"><?php endif; ?>
    <select name="level">
      <?php foreach (['' => 'All levels', 'ERROR' => 'ERROR', 'WARNING' => 'WARNING', 'INFO' => 'INFO', 'DEBUG' => 'DEBUG'] as $v => $label): ?>
        <option value="<?= $e($v) ?>"<?= $v === $level ? ' selected' : '' ?>><?= $e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= $e($needle) ?>" placeholder="filter text…" size="30">
    <button type="submit">Filter</button>
  </form>
</header>

<main>
<?php if ($note !== ''): ?>
  <p class="none"><?= $e($note) ?></p>
<?php elseif ($entries === []): ?>
  <p class="none">No matching entries.</p>
<?php else: ?>
  <?php foreach ($entries as $entry):
      $split = preg_split('/\r?\n/', $entry['body'], 2);
      $summary = $split[0] ?? '';
      $detail = $split[1] ?? '';
      $colour = $tone[$entry['level']] ?? '#6b7280';
  ?>
    <details class="entry">
      <summary>
        <span class="lvl" style="background:<?= $e($colour) ?>"><?= $e($entry['level']) ?></span>
        <span class="time"><?= $e($entry['time']) ?></span>
        <span class="msg"><?= $e(mb_substr($summary, 0, 300)) ?></span>
      </summary>
      <pre><?= $detail !== '' ? $e($detail) : 'No further detail.' ?></pre>
    </details>
  <?php endforeach; ?>
<?php endif; ?>
</main>
</body></html>
