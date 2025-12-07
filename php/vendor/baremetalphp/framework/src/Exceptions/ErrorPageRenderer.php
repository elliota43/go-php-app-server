<?php

declare(strict_types=1);

namespace BareMetalPHP\Exceptions;

use BareMetalPHP\Application;
use BareMetalPHP\Http\Request;
use Throwable;

class ErrorPageRenderer
{
    public static function render(Throwable $e, ?Request $request = null, ?Application $app = null): string
    {
        $title   = htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file    = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line    = $e->getLine();

        $containerHtml = self::renderContainerBindings($app);
        $sharePlaceholder = '{{__MINI_DEBUG_SHARE_URL__}}';

        $traceHtml   = self::renderTrace($e);
        $traceText   = self::renderTraceText($e);
        $codeHtml    = self::renderCodeExcerpt($e->getFile(), $e->getLine());
        $requestHtml = self::renderRequestContext($request);
        
        // Embed trace text data for JavaScript (already JSON-encoded, just need to escape for JS)
        // traceText is already JSON, so we can embed it directly but need to escape </script>
        $traceTextEscaped = str_replace('</script>', '<\/script>', $traceText);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unhandled Exception - {$title}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #e5e7eb;
        }
        .wrapper {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 16px;
        }
        .panel {
            background: #020617;
            border-radius: 10px;
            box-shadow: 0 18px 45px rgba(0,0,0,0.6);
            border: 1px solid #1f2937;
            overflow: hidden;
        }
        .panel-header {
            padding: 16px 20px;
            border-bottom: 1px solid #1f2937;
            background: linear-gradient(90deg, #111827, #020617);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .panel-header .dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
        }
        .dot.red { background: #ef4444; }
        .dot.yellow { background: #facc15; }
        .dot.green { background: #22c55e; }
        .panel-header-title {
            margin-left: 8px;
            font-size: 14px;
            color: #9ca3af;
        }
        h1 {
            margin: 0;
            font-size: 18px;
            color: #f87171;
        }
        .exception-meta {
            padding: 16px 20px;
            border-bottom: 1px solid #1f2937;
        }
        .exception-meta-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 13px;
            color: #e5e7eb;
        }
        .exception-meta-path {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 4px;
        }
        .columns {
            display: grid;
            grid-template-columns: minmax(0, 2.5fr) minmax(0, 2fr);
            border-top: 1px solid #000;
        }
        .col {
            border-right: 1px solid #1f2937;
        }
        .col:last-child {
            border-right: none;
        }
        .section-heading {
            padding: 10px 16px;
            border-bottom: 1px solid #1f2937;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #9ca3af;
            background: #020617;
        }
        .code-block {
            padding: 12px 16px 16px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 13px;
            background: radial-gradient(circle at top, #020617, #020617);
            overflow-x: auto;
            border-bottom-left-radius: 10px;
        }
        .code-line {
            white-space: pre;
        }
        .code-line span.ln {
            display: inline-block;
            width: 40px;
            text-align: right;
            margin-right: 8px;
            color: #4b5563;
            user-select: none;
        }
        .code-line.current {
            background: rgba(239, 68, 68, 0.12);
        }
        .code-line.current span.ln {
            color: #fca5a5;
        }
        .trace {
            padding: 8px 16px 14px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
            max-height: 520px;
            overflow-y: auto;
        }
        .trace-item {
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #1f2937;
        }
        .trace-item:last-child {
            border-bottom: none;
        }
        .trace-fn {
            color: #e5e7eb;
        }
        .trace-file {
            color: #9ca3af;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            background: rgba(148, 163, 184, 0.18);
            color: #e5e7eb;
        }
        .badge.framework {
            background: rgba(59, 130, 246, 0.16);
            color: #93c5fd;
        }
        .badge.app {
            background: rgba(34, 197, 94, 0.16);
            color: #6ee7b7;
        }
        .trace-controls {
            padding: 8px 16px 4px;
            border-bottom: 1px solid #1f2937;
            font-size: 11px;
            color: #9ca3af;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .trace-controls label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }
        .trace-controls input[type="checkbox"] {
            accent-color: #3b82f6;
        }
        .trace-item.framework {
            opacity: 0.75;
        }
        .panel-section {
            border-top: 1px solid #1f2937;
        }
        .request-block {
            padding: 8px 16px 16px;
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
            gap: 12px;
            font-size: 12px;
        }
        .request-meta {
            margin-bottom: 10px;
        }
        .request-meta dt {
            font-weight: 600;
            color: #e5e7eb;
            font-size: 12px;
        }
        .request-meta dd {
            margin: 2px 0 8px;
            color: #9ca3af;
        }
        .kv-box {
            background: #020617;
            border-radius: 6px;
            border: 1px solid #1f2937;
            padding: 6px 8px 8px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            max-height: 220px;
            overflow: auto;
        }
        .kv-box-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #9ca3af;
            margin-bottom: 4px;
        }
        .kv-box pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 11px;
            color: #e5e7eb;
        }
        footer {
            margin-top: 12px;
            font-size: 11px;
            color: #6b7280;
            text-align: right;
        }
        .copy-btn {
            background: #1f2937;
            border: 1px solid #374151;
            color: #e5e7eb;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .copy-btn:hover {
            background: #374151;
            border-color: #4b5563;
        }
        .copy-btn:active {
            transform: scale(0.95);
        }
        .copy-btn.copied {
            background: #22c55e;
            border-color: #16a34a;
            color: white;
        }
        #error-details {
            display: none;
            white-space: pre-wrap;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
            background: #020617;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #1f2937;
            max-height: 400px;
            overflow: auto;
            margin-top: 12px;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="panel">
        <div class="panel-header">
            <div class="dot red"></div>
            <div class="dot yellow"></div>
            <div class="dot green"></div>
            <div class="panel-header-title">mini • debug</div>
        </div>

        <div class="exception-meta">
            <div class="exception-meta-code" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <span class="badge">Unhandled Exception</span>
                <span>{$title}</span>
                <div style="margin-left: auto; display: flex; gap: 8px;">
                    <button class="copy-btn" data-copy="error" title="Copy error message">📋 Copy Error</button>
                    <button class="copy-btn" data-copy="trace" title="Copy stack trace">📋 Copy Trace</button>
                    <button class="copy-btn" data-copy="all" title="Copy full error details">📋 Copy All</button>
                </div>
            </div>
            <div class="exception-meta-code" style="margin-top: 6px; color:#f97373;" id="error-message">
                {$message}
            </div>
            <div class="exception-meta-path">
                in <strong>{$file}</strong> on line <strong>{$line}</strong>
            </div>
        </div>

        <div class="columns">
            <div class="col">
                <div class="section-heading">Code Excerpt</div>
                <div class="code-block">
                    {$codeHtml}
                </div>
            </div>
            <div class="col">
                <div class="section-heading">Stack Trace</div>
                <div class="trace-controls">
                    <span>Show frames:</span>
                    <label><input type="checkbox" data-toggle="app" checked> <span class="badge app">App</span></label>
                    <label><input type="checkbox" data-toggle="framework" checked> <span class="badge framework">Framework</span></label>
                </div>
                <div class="trace">
                    {$traceHtml}
                </div>
            </div>
        </div>

        <div class="panel-section">
            <div class="section-heading">Request Context</div>
            {$requestHtml}
        </div>

        <div class="panel-section">
            <div class="section-heading">Container / IoC Bindings</div>
            {$containerHtml}
        </div>
    </div>
    <footer>
        mini framework — debug mode  • share: {$sharePlaceholder}
    </footer>
</div>

<script>
(function () {
    // Trace filter toggles
    const toggles = document.querySelectorAll('.trace-controls [data-toggle]');
    toggles.forEach(function (cb) {
        cb.addEventListener('change', function () {
            const type = this.getAttribute('data-toggle');
            const items = document.querySelectorAll('.trace-item.' + type);
            items.forEach(function (el) {
                el.style.display = cb.checked ? '' : 'none';
            });
        });
    });

    // Copy functionality - prepare data
    const errorMessage = {$traceTextEscaped};
    const errorData = {
        error: errorMessage.message,
        trace: errorMessage.trace,
        all: errorMessage.all
    };

    document.querySelectorAll('.copy-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const type = this.getAttribute('data-copy');
            const text = errorData[type] || '';
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    const originalText = btn.textContent;
                    btn.textContent = '✓ Copied!';
                    btn.classList.add('copied');
                    setTimeout(function() {
                        btn.textContent = originalText;
                        btn.classList.remove('copied');
                    }, 2000);
                }).catch(function() {
                    fallbackCopy(text, btn);
                });
            } else {
                fallbackCopy(text, btn);
            }
        });
    });

    function fallbackCopy(text, btn) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            const originalText = btn.textContent;
            btn.textContent = '✓ Copied!';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = originalText;
                btn.classList.remove('copied');
            }, 2000);
        } catch (err) {
            alert('Failed to copy. Please select and copy manually.');
        }
        document.body.removeChild(textarea);
    }
})();
</script>
</body>
</html>
HTML;

        $shareUrl = self::maybeSaveDebugSnapshot($html);
        $html = str_replace($sharePlaceholder, htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'), $html);

        return $html;
    }

    protected static function renderTrace(Throwable $e): string
    {
        $trace = $e->getTrace();
        $lines = [];

        // First frame: the exception location itself
        $type = self::classifyFrame(['file' => $e->getFile()]);

        $lines[] = sprintf(
            '<div class="trace-item %s"><div class="trace-fn">#0 %s <span class="badge %s">%s</span></div><div class="trace-file">%s:%d</div></div>',
            $type,
            htmlspecialchars(self::formatFunction(
                get_class($e),
                null
            ), ENT_QUOTES, 'UTF-8'),
            $type,
            ucfirst($type),
            htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8'),
            $e->getLine()
        );

        foreach ($trace as $index => $frame) {
            $fn = self::formatFunction(
                $frame['class'] ?? null,
                $frame['function'] ?? 'unknown',
                $frame['type'] ?? null
            );

            $file  = $frame['file'] ?? '[internal function]';
            $line  = $frame['line'] ?? 0;
            $type  = self::classifyFrame($frame);

            $lines[] = sprintf(
                '<div class="trace-item %s"><div class="trace-fn">#%d %s <span class="badge %s">%s</span></div><div class="trace-file">%s:%d</div></div>',
                $type,
                $index + 1,
                htmlspecialchars($fn, ENT_QUOTES, 'UTF-8'),
                $type,
                ucfirst($type),
                htmlspecialchars($file, ENT_QUOTES, 'UTF-8'),
                (int) $line
            );
        }

        return implode("\n", $lines);
    }

    protected static function renderTraceText(Throwable $e): string
    {
        $trace = $e->getTrace();
        $lines = [];

        // First frame: the exception location itself
        $lines[] = sprintf(
            "#0 %s()\n   %s:%d",
            get_class($e),
            $e->getFile(),
            $e->getLine()
        );

        foreach ($trace as $index => $frame) {
            $fn = self::formatFunction(
                $frame['class'] ?? null,
                $frame['function'] ?? 'unknown',
                $frame['type'] ?? null
            );

            $file  = $frame['file'] ?? '[internal function]';
            $line  = $frame['line'] ?? 0;

            $lines[] = sprintf(
                "#%d %s\n   %s:%d",
                $index + 1,
                $fn,
                $file,
                (int) $line
            );
        }

        $traceText = implode("\n\n", $lines);
        $message = $e->getMessage();
        $file = $e->getFile();
        $line = $e->getLine();
        $title = get_class($e);

        // Return as JSON string for embedding in JavaScript
        $data = [
            'message' => $message,
            'trace' => $traceText,
            'all' => "Exception: {$title}\nMessage: {$message}\nFile: {$file}\nLine: {$line}\n\nStack Trace:\n{$traceText}"
        ];

        return json_encode($data, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }

    protected static function classifyFrame(array $frame): string
    {
        $file = $frame['file'] ?? '';

        if ($file === '') {
            return 'framework';
        }

        $lower = strtolower($file);
        $cwd   = strtolower(getcwd() ?: '');

        // crude heuristic: anything under "src/Framework" or "vendor" = framework
        if (str_contains($lower, '/vendor/')
            || str_contains($lower, '/framework/')
            || (str_contains($lower, $cwd . '/src') && str_contains($lower, 'framework'))) {
            return 'framework';
        }

        return 'app';
    }

    protected static function formatFunction(?string $class, ?string $function, ?string $type = null): string
    {
        $fn = $function ?? '';

        if ($class) {
            $fn = $class . ($type ?? '::') . $fn;
        }

        return $fn . '()';
    }

    protected static function renderCodeExcerpt(string $file, int $line, int $padding = 6): string
    {
        if (!is_readable($file)) {
            return '<div class="code-line"><span class="ln">?</span> (source not available)</div>';
        }

        $code  = file($file, FILE_IGNORE_NEW_LINES);
        $total = count($code);

        $start = max($line - $padding, 1);
        $end   = min($line + $padding, $total);

        $htmlLines = [];

        for ($i = $start; $i <= $end; $i++) {
            $current = $i === $line;
            $ln      = str_pad((string)$i, 3, ' ', STR_PAD_LEFT);
            $content = htmlspecialchars($code[$i - 1] ?? '', ENT_QUOTES, 'UTF-8');

            $class = $current ? 'code-line current' : 'code-line';

            $htmlLines[] = sprintf(
                '<div class="%s"><span class="ln">%s</span>%s</div>',
                $class,
                $ln,
                $content
            );
        }

        return implode("\n", $htmlLines);
    }

    protected static function renderContainerBindings(?Application $app = null): string
    {
        if (!$app) {
            return '<div class="request-block"></div>(container not available)</div></div>';
        }

        $data = $app->debugBindings();

        $bindings = $data['bindings'] ?? [];
        $singletons = $data['singletons'] ?? [];
        $instances = $data['instances'] ?? [];

        $bindingsJson = htmlspecialchars(json_encode($bindings, JSON_PRETTY_PRINT), ENT_QUOTES);
        $singletonsJson = htmlspecialchars(json_encode($singletons, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
        $instancesJson = htmlspecialchars(json_encode($instances, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
        return <<<HTML
<div class="request-block">
    <div>
        <div class="kv-box">
            <div class="kv-box-title">Bindings</div>
            <pre>{$bindingsJson}</pre>
        </div>
    </div>
    <div>
        <div class="kv-box">
            <div class="kv-box-title">Singletons</div>
            <pre>{$singletonsJson}</pre>
        </div>
    </div>
</div>
<div class="request-block">
    <div>
        <div class="kv-box">
            <div class="kv-box-title">Instances</div>
            <pre>{$instancesJson}</pre>
        </div>
    </div>
    <div></div>
</div>
HTML;

    }

    protected static function maybeSaveDebugSnapshot(string $html): string
    {
        // only in debug mode; if for some reason APP_DEBUG isn't set, just skip
        if (getenv('APP_DEBUG') !== 'true') {
            return '#';
        }

        $cwd = getcwd() ?: __DIR__ .'/../../..';
        $publicDir = $cwd . '/public';
        $debugDir = $publicDir .'/debug';

        if (!is_dir($debugDir)) {
            @mkdir($debugDir, 0777, true);
        }

        $id = date('Ymd_His') . '_' . substr(sha1(uniqid('', true)), 0, 8);
        $fileName = $id . '.html';
        $path = $debugDir . '/' . $fileName;

        // Best-effort write; if it fails, we still render the main page
        @file_put_contents($path, $html);

        // Relative URL the dev can copy (your built-in server will serve /public)
        return '/debug/'.$fileName;
    }
    protected static function renderRequestContext(?Request $request = null): string
    {
        // Basic info
        $method = $request
            ? (method_exists($request, 'getMethod') ? $request->getMethod() : ($request->method() ?? 'GET'))
            : ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri = $request && method_exists($request, 'uri')
            ? $request->uri()
            : ($_SERVER['REQUEST_URI'] ?? '/');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $meta = <<<HTML
<div class="request-block">
    <div>
        <dl class="request-meta">
            <dt>Method</dt>
            <dd>{$method}</dd>
            <dt>URI</dt>
            <dd>{$uri}</dd>
            <dt>Client IP</dt>
            <dd>{$ip}</dd>
        </dl>
    </div>
HTML;

        // Right side: superglobal dumps
        $get     = $_GET ?? [];
        $post    = $_POST ?? [];
        $cookies = $_COOKIE ?? [];
        $server  = $_SERVER ?? [];

        $getBox     = self::renderKvBox('GET', $get);
        $postBox    = self::renderKvBox('POST', $post);
        $cookieBox  = self::renderKvBox('Cookies', $cookies);
        $serverBox  = self::renderKvBox('Server / Headers', self::filterHeaderInfo($server));

        $right = <<<HTML
    <div>
        {$getBox}
        {$postBox}
    </div>
</div>
<div class="request-block">
    <div>
        {$cookieBox}
    </div>
    <div>
        {$serverBox}
    </div>
</div>
HTML;

        return $meta . $right;
    }

    protected static function renderKvBox(string $title, array $data): string
    {
        if (empty($data)) {
            $content = "(empty)";
        } else {
            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="kv-box">
    <div class="kv-box-title">{$title}</div>
    <pre>{$content}</pre>
</div>
HTML;
    }

    protected static function filterHeaderInfo(array $server): array
    {
        $filtered = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_') || in_array($key, ['REMOTE_ADDR', 'SERVER_NAME', 'SERVER_PORT', 'REQUEST_METHOD', 'REQUEST_URI'], true)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
