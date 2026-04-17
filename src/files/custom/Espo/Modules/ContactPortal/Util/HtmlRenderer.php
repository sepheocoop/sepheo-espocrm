<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Util;

/**
 * Renders self-contained HTML pages for the Contact Portal entry points.
 * Styled to match sepheo.co: Syne Mono headings, SUSE body, warm off-white bg.
 */
class HtmlRenderer
{
    private const STYLES = <<<CSS
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "SUSE", sans-serif;
            font-weight: 300;
            font-optical-sizing: auto;
            background: #f4f3ef;
            color: #1a1a1a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 32px 24px 56px;
        }

        .site-header {
            width: 100%;
            max-width: 680px;
            margin-bottom: 20px;
        }

        .site-wordmark {
            font-family: "Syne Mono", monospace;
            font-size: 1.25rem;
            font-weight: 400;
            letter-spacing: 0.08em;
            color: #1a1a1a;
            text-decoration: none;
        }

        .card {
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 2px 20px rgba(0,0,0,.07);
            padding: 40px 44px;
            width: 100%;
            max-width: 680px;
        }

        h1 {
            font-family: "Syne Mono", monospace;
            font-size: 1.55rem;
            font-weight: 400;
            line-height: 1.3;
            margin-bottom: 14px;
        }

        .description {
            font-weight: 300;
            line-height: 1.65;
            color: #555;
            font-size: 0.9rem;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e8e6e0;
        }

        .description p + p { margin-top: 8px; }

        .subtitle { color: #666; font-size: 0.9rem; margin-bottom: 24px; }

        .field { margin-bottom: 20px; }
        .row { display: flex; gap: 16px; }
        .row .field { flex: 1; }

        label {
            display: block;
            font-family: "Syne Mono", monospace;
            font-size: 0.68rem;
            font-weight: 400;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #999;
            margin-bottom: 6px;
        }

        input[type=text], input[type=email], input[type=tel], input[type=url],
        input[type=number], input[type=date], input[type=datetime-local],
        textarea, select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d8d5cd;
            border-radius: 3px;
            font-family: "SUSE", sans-serif;
            font-weight: 300;
            font-size: 0.95rem;
            color: #1a1a1a;
            background: #fff;
            transition: border-color .15s;
        }

        textarea { resize: vertical; min-height: 100px; }
        select { cursor: pointer; background-color: #fff; }

        .checkbox-group {
            display: flex; flex-direction: column; gap: 8px;
            padding: 10px 12px;
            border: 1px solid #d8d5cd;
            border-radius: 3px;
            background: #fff;
        }

        .checkbox-option {
            display: flex; align-items: center; gap: 8px;
            font-family: "SUSE", sans-serif;
            font-size: 0.9rem; font-weight: 300;
            color: #1a1a1a; text-transform: none; letter-spacing: normal; cursor: pointer;
        }

        .checkbox-option input[type=checkbox] { width: 15px; height: 15px; flex-shrink: 0; cursor: pointer; accent-color: #1a1a1a; }

        input[type=file] {
            width: 100%; padding: 8px 12px;
            border: 1px solid #d8d5cd; border-radius: 3px;
            font-family: "SUSE", sans-serif; font-size: 0.9rem;
            color: #666; background: #fff; cursor: pointer;
        }

        .field-hint { display: block; font-size: 0.75rem; color: #aaa; margin-top: 4px; }

        input:focus, textarea:focus, select:focus, input[type=file]:focus {
            outline: none;
            border-color: #1a1a1a;
            box-shadow: none;
        }

        .field-checkbox label {
            display: flex; align-items: center; gap: 8px;
            text-transform: none; letter-spacing: normal;
            font-family: "SUSE", sans-serif;
            font-size: 0.9rem; font-weight: 300; color: #1a1a1a; cursor: pointer;
        }

        .field-checkbox input[type=checkbox] { width: 15px; height: 15px; flex-shrink: 0; cursor: pointer; }

        .btn {
            display: inline-block;
            padding: 10px 28px;
            background: #1a1a1a;
            color: #fff;
            border: none;
            border-radius: 3px;
            font-family: "Syne Mono", monospace;
            font-size: 0.85rem;
            font-weight: 400;
            letter-spacing: 0.05em;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }

        .btn:hover { background: #333; }

        .btn-secondary {
            background: transparent;
            color: #1a1a1a;
            border: 1px solid #1a1a1a;
            margin-left: 10px;
        }

        .btn-secondary:hover { background: #f4f3ef; }

        .alert {
            padding: 12px 16px;
            border-radius: 3px;
            font-size: 0.875rem;
            margin-bottom: 22px;
            line-height: 1.5;
        }

        .alert-success { background: #f0faf4; color: #1a5c38; border: 1px solid #b6dfc8; }
        .alert-error   { background: #fdf2f2; color: #7c1d1d; border: 1px solid #f0b8b8; }
        .alert-info    { background: #f0f4fd; color: #1a3a6e; border: 1px solid #bccfef; }

        .actions { margin-top: 28px; display: flex; align-items: center; }

        a.link { color: #1a1a1a; text-decoration: underline; font-size: 0.875rem; }
        a.link:hover { color: #555; }

        .field.has-error input,
        .field.has-error textarea,
        .field.has-error select,
        .field.has-error input[type=file] { border-color: #c0392b; }
        .field.has-error .checkbox-group { border-color: #c0392b; }
        .field-error-msg { display: block; font-size: 0.75rem; color: #c0392b; margin-top: 4px; font-weight: 400; }

        .file-current {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 12px;
            background: #f4f3ef;
            border: 1px solid #d8d5cd;
            border-radius: 3px;
            font-size: 0.875rem;
            color: #444;
            margin-bottom: 8px;
        }

        .file-current .file-name { font-weight: 400; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-current .file-size { color: #999; font-size: 0.8rem; flex-shrink: 0; }
    CSS;

    public function render(string $title, string $body): string
    {
        $styles = self::STYLES;

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title} — Sepheo</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=SUSE:wght@100..800&family=Syne+Mono&display=swap" rel="stylesheet">
            <style>{$styles}</style>
        </head>
        <body>
            <div class="site-header">
                <span class="site-wordmark">SEPHEO</span>
            </div>
            <div class="card">
                {$body}
            </div>
        </body>
        </html>
        HTML;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

