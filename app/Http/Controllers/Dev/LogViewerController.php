<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Read the application log over HTTP, for diagnosing a deployed server.
 *
 * Built to survive the two failures that actually happen here, because a diagnostics page that
 * needs the thing that is broken is useless:
 *
 *   - NO DATABASE. Sessions are database-backed, so anything behind `auth` is unreachable the
 *     moment the database is — which is precisely when the log matters. The gate is a secret
 *     compared in constant time, and nothing on this path opens a connection.
 *   - NO BLADE. The HTML is assembled as a string. A compiled-view permission error (the
 *     `touch(): Utime failed` this server has hit twice) would otherwise take this page down
 *     with everything else.
 *
 * It is also registered OUTSIDE the `web` middleware group — no session, no CSRF — for the
 * same reason.
 *
 * The secret is mandatory. Empty means the route answers 404: a log viewer that defaults to
 * open on a public domain hands over email addresses, SQL with live values, tokens and stack
 * traces to anybody who guesses the path.
 */
class LogViewerController extends Controller
{
    /** How much of the tail to read. Log files grow without bound; memory does not. */
    private const TAIL_BYTES = 2_000_000;

    /** Entries rendered, newest first. */
    private const LIMIT = 200;

    public function __invoke(Request $request): Response
    {
        $secret = (string) config('logging.viewer_secret');

        /*
         * 404, not 401 — a 401 confirms the path is right and invites guessing. Same reasoning
         * as the Postmark webhook.
         */
        abort_if($secret === '', 404);

        $given = (string) ($request->route('token') ?? $request->query('key', ''));

        abort_unless($given !== '' && hash_equals($secret, $given), 404);

        $path = storage_path('logs/laravel.log');
        $level = strtoupper((string) $request->query('level', ''));
        $needle = trim((string) $request->query('q', ''));

        $entries = is_file($path) ? $this->entries($path, $level, $needle) : [];

        return response($this->html($path, $entries, $level, $needle, $given))
            ->header('Content-Type', 'text/html; charset=utf-8')
            // Never cached, never indexed.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The tail of the log, split into entries, newest first.
     *
     * @return array<int, array<string, string>>
     */
    private function entries(string $path, string $level, string $needle): array
    {
        $size = filesize($path) ?: 0;
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        if ($size > self::TAIL_BYTES) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
            // The first line after seeking mid-file is a fragment; drop it.
            fgets($handle);
        }

        $raw = (string) stream_get_contents($handle);
        fclose($handle);

        /*
         * An entry starts with `[2026-08-19 14:17:45] local.ERROR: …` and runs until the next
         * one. Everything between is the stack trace, which belongs with its message rather
         * than as a hundred separate lines.
         */
        $parts = preg_split(
            '/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+)\]\s+([\w-]+)\.(\w+):/m',
            $raw,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        ) ?: [];

        $entries = [];

        // The split yields [preamble, date, channel, level, body, date, channel, level, body…].
        for ($i = 1; $i + 3 <= count($parts); $i += 4) {
            $entries[] = [
                'time' => trim($parts[$i]),
                'channel' => trim($parts[$i + 1]),
                'level' => strtoupper(trim($parts[$i + 2])),
                'body' => trim($parts[$i + 3]),
            ];
        }

        $entries = array_reverse($entries);

        if ($level !== '') {
            $entries = array_values(array_filter($entries, fn ($e) => $e['level'] === $level));
        }

        if ($needle !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn ($e) => stripos($e['body'], $needle) !== false,
            ));
        }

        return array_slice($entries, 0, self::LIMIT);
    }

    /** @param  array<int, array<string, string>>  $entries */
    private function html(string $path, array $entries, string $level, string $needle, string $token): string
    {
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $tone = [
            'ERROR' => '#ef4444', 'CRITICAL' => '#ef4444', 'ALERT' => '#ef4444',
            'EMERGENCY' => '#ef4444', 'WARNING' => '#f59e0b', 'NOTICE' => '#3b82f6',
            'INFO' => '#22c55e', 'DEBUG' => '#6b7280',
        ];

        $rows = '';

        foreach ($entries as $entry) {
            // The first line is the message; the rest is context and stack trace.
            $lines = preg_split('/\r?\n/', $entry['body'], 2);
            $summary = $lines[0] ?? '';
            $detail = $lines[1] ?? '';
            $colour = $tone[$entry['level']] ?? '#6b7280';

            $rows .= '<details class="entry">'
                .'<summary>'
                .'<span class="lvl" style="background:'.$colour.'">'.$e($entry['level']).'</span>'
                .'<span class="time">'.$e($entry['time']).'</span>'
                .'<span class="msg">'.$e(mb_substr($summary, 0, 300)).'</span>'
                .'</summary>'
                .($detail !== '' ? '<pre>'.$e($detail).'</pre>' : '<pre class="empty">No further detail.</pre>')
                .'</details>';
        }

        if ($rows === '') {
            $rows = '<p class="empty-state">No matching entries. The log may be empty, or the '
                .'filters below exclude everything.</p>';
        }

        $size = is_file($path) ? number_format(filesize($path) / 1024, 1).' KB' : 'missing';
        $mtime = is_file($path) ? date('Y-m-d H:i:s', filemtime($path)) : '—';

        $levels = '';

        foreach (['', 'ERROR', 'WARNING', 'INFO', 'DEBUG'] as $option) {
            $label = $option === '' ? 'All levels' : $option;
            $on = $option === $level ? ' selected' : '';
            $levels .= '<option value="'.$e($option).'"'.$on.'>'.$e($label).'</option>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Error log</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;background:#0f1115;color:#d7dae0}
  header{padding:14px 18px;border-bottom:1px solid #232733;background:#151821;position:sticky;top:0;z-index:2}
  h1{margin:0 0 6px;font-size:15px;color:#fff;font-family:system-ui,sans-serif}
  .meta{color:#7d8492;font-size:12px}
  form{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}
  input,select,button{font:12px ui-monospace,monospace;background:#0f1115;color:#d7dae0;
    border:1px solid #2b3140;border-radius:6px;padding:6px 9px}
  button{background:#2563eb;border-color:#2563eb;color:#fff;cursor:pointer;font-weight:600}
  main{padding:12px 18px 60px}
  .entry{border:1px solid #232733;border-radius:8px;margin-bottom:8px;background:#151821;overflow:hidden}
  summary{cursor:pointer;padding:9px 12px;display:flex;gap:10px;align-items:baseline;list-style:none}
  summary::-webkit-details-marker{display:none}
  .lvl{color:#fff;font-size:10px;font-weight:700;border-radius:4px;padding:2px 6px;flex-shrink:0}
  .time{color:#7d8492;font-size:11px;flex-shrink:0}
  .msg{color:#e6e9ef;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  pre{margin:0;padding:12px;border-top:1px solid #232733;background:#0f1115;
    white-space:pre-wrap;word-break:break-word;color:#a9b0bd;font-size:12px;max-height:460px;overflow:auto}
  pre.empty{color:#5c6270}
  .empty-state{color:#7d8492}
</style></head><body>
<header>
  <h1>Error log</h1>
  <div class="meta">{$e($path)} &middot; {$size} &middot; last written {$mtime} &middot; newest first, max 200</div>
  <form method="get">
    <input type="hidden" name="key" value="{$e($token)}">
    <select name="level">{$levels}</select>
    <input type="search" name="q" value="{$e($needle)}" placeholder="filter text…" size="30">
    <button type="submit">Filter</button>
  </form>
</header>
<main>{$rows}</main>
</body></html>
HTML;
    }
}
