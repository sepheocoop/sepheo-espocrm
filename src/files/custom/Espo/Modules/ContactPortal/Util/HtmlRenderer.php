<?php
namespace Espo\Modules\ContactPortal\Util;

/**
 * Renders self-contained HTML pages for the Contact Portal entry points.
 */
class HtmlRenderer
{
    private const STYLES = <<<CSS
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f4f5f7;
            color: #1a1a2e;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            padding: 40px;
            width: 100%;
            max-width: 520px;
        }
        h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 8px; }
        .subtitle { color: #6b7280; font-size: 0.9rem; margin-bottom: 28px; }
        .field { margin-bottom: 18px; }
        .row { display: flex; gap: 16px; }
        .row .field { flex: 1; }
        label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #9ca3af;
            margin-bottom: 5px;
        }
        input[type=text], input[type=email], input[type=tel] {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.95rem;
            color: #1a1a2e;
            transition: border-color .15s;
        }
        input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
        .btn {
            display: inline-block;
            padding: 10px 26px;
            background: #6366f1;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }
        .btn:hover { background: #4f46e5; }
        .btn-secondary {
            background: transparent;
            color: #6366f1;
            border: 1.5px solid #6366f1;
            margin-left: 10px;
        }
        .btn-secondary:hover { background: #f0f0ff; }
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 0.875rem;
            margin-bottom: 22px;
        }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .actions { margin-top: 24px; display: flex; align-items: center; }
        a.link { color: #6366f1; text-decoration: none; font-size: 0.875rem; }
        a.link:hover { text-decoration: underline; }
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
            <title>{$title}</title>
            <style>{$styles}</style>
        </head>
        <body>
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
