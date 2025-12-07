<?php

declare(strict_types=1);

namespace BareMetalPHP\View;

class TemplateEngine
{
    public static function compile(string $contents): string
    {
        // 1) Raw echo: {!! ... !!}
        $contents = preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?php echo $1; ?>', $contents);

        // 2) Escaped echo: {{ ... }}
        $contents = preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/s',
            '<?php echo htmlspecialchars((string) ($1), ENT_QUOTES, \'UTF-8\'); ?>',
            $contents
        );

        // 3) @if, @elseif, @else, @endif (allow indentation)
        $contents = preg_replace('/^\s*@if\s*\((.*)\)\s*$/m',      '<?php if ($1): ?>',        $contents);
        $contents = preg_replace('/^\s*@elseif\s*\((.*)\)\s*$/m',  '<?php elseif ($1): ?>',    $contents);
        $contents = preg_replace('/^\s*@else\s*$/m',               '<?php else: ?>',           $contents);
        $contents = preg_replace('/^\s*@endif\s*$/m',              '<?php endif; ?>',          $contents);

        

        // 4) @foreach, @endforeach (also allow indentation)
        $contents = preg_replace('/^\s*@foreach\s*\((.*)\)\s*$/m', '<?php foreach ($1): ?>',    $contents);
        $contents = preg_replace('/^\s*@endforeach\s*$/m',         '<?php endforeach; ?>',      $contents);

        return $contents;
    }
}