<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Util;

use Espo\ORM\Entity;

/**
 * Renders self-contained HTML pages for the Contact Portal entry points.
 * Styled to match sepheo.co: Syne Mono headings, SUSE body, warm off-white bg.
 */
class HtmlRenderer
{
    // FIXME this CSS should not be hardwired in the code.
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

        .field { margin-bottom: 50px; }
        .row { display: flex; gap: 16px; }
        .row .field { flex: 1; }

        label {
            display: block;
            font-family: "SUSE", sans-serif;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            color: #1a1a1a;
            margin-bottom: 5px;
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
        .field-desc {
            display: block;
            font-family: "SUSE", sans-serif;
            font-size: 0.78rem;
            font-weight: 300;
            font-style: italic;
            color: #999;
            line-height: 1.55;
            margin-top: 3px;
            margin-bottom: 7px;
        }

        .field-readonly { opacity: 0.85; }
        .field-readonly-label {
            display: block;
            font-family: "SUSE", sans-serif;
            font-size: 0.85rem;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 3px;
        }
        .field-readonly-value {
            font-family: "SUSE", sans-serif;
            font-size: 0.95rem;
            font-weight: 300;
            color: #555;
            padding: 9px 12px;
            background: #f7f6f3;
            border: 1px solid #e4e1d9;
            border-radius: 3px;
            line-height: 1.5;
            cursor: default;
        }

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
        .required-asterisk { color: #c0392b; margin-left: 2px; font-weight: 600; }

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
        .file-current a.file-name { color: inherit; text-decoration: underline; text-decoration-color: #aaa; }
        .file-current a.file-name:hover { color: #5a6e2c; text-decoration-color: currentColor; }
        .file-current .file-size { color: #999; font-size: 0.8rem; flex-shrink: 0; }
        .file-remove-btn {
            margin-left: auto; flex-shrink: 0;
            background: none; border: none; cursor: pointer;
            color: #999; font-size: 1rem; line-height: 1;
            padding: 2px 4px; border-radius: 3px;
            transition: color 0.15s, background 0.15s;
        }
        .file-remove-btn:hover { color: #c0392b; background: #fde8e6; }
    CSS;

    public function render(string $title, string $body): string
    {
        $styles = self::STYLES;
        $safeTitle = self::e($title);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$safeTitle} — Sepheo</title>
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

    // -------------------------------------------------------------------------
    // Shared form rendering
    // -------------------------------------------------------------------------

    /**
     * Renders a single form field.
     *
     * When $contact is null the field renders blank (registration).
     * When $contact is provided values are pre-filled (edit).
     *
     * @param array<string, mixed> $field
     * @param list<array{name:string,size:int}> $existingFiles  current attachments for file fields
     */
    public function renderFormField(
        array $field,
        ?Entity $contact = null,
        array $existingFiles = [],
        string $token = '',
    ): string {
        $name = $field['name'];
        $label = self::e($field['label']);
        $inputType = $field['inputType'];
        $required = $field['required'] ? ' required' : '';
        $maxLength =
            $field['maxLength'] !== null
                ? ' maxlength="' . (int) $field['maxLength'] . '"'
                : '';
        $raw = $contact?->get($name);

        $rawHint = (string) ($field['hint'] ?? '');
        $hintHtml =
            $rawHint !== ''
                ? '<span class="field-desc">' .
                    nl2br(self::e($rawHint)) .
                    '</span>'
                : '';

        $asterisk =
            $field['required'] && $inputType !== 'checkbox'
                ? ' <span class="required-asterisk" aria-hidden="true">*</span>'
                : '';

        // ── read-only display ────────────────────────────────────────────────
        if (!empty($field['readOnly'])) {
            if ($inputType === 'checkbox') {
                $display = $raw ? 'Yes' : 'No';
            } elseif ($inputType === 'multiselect') {
                $vals = is_array($raw) ? $raw : [];
                $display = $vals
                    ? implode(', ', array_map('htmlspecialchars', $vals))
                    : '—';
            } elseif ($inputType === 'file') {
                $pills = '';
                foreach ($existingFiles as $file) {
                    $safeName = self::e($file['name']);
                    $fileUrl = self::e(
                        '/?entryPoint=contactPortalFile&token=' .
                            rawurlencode($token) .
                            '&field=' .
                            rawurlencode($name),
                    );
                    $pills .= "<a class=\"file-name\" href=\"{$fileUrl}\" target=\"_blank\" rel=\"noopener\">{$safeName}</a> ";
                }
                $display = $pills ?: '—';
            } elseif ($field['originalType'] === 'urlMultiple') {
                $urls = is_array($raw) ? $raw : [];
                $display = $urls
                    ? implode(
                        ', ',
                        array_map(
                            fn($u) => '<a href="' .
                                self::e($u) .
                                '" target="_blank" rel="noopener">' .
                                self::e($u) .
                                '</a>',
                            $urls,
                        ),
                    )
                    : '—';
            } else {
                $display = self::e((string) ($raw ?? '')) ?: '—';
            }
            return <<<HTML
            <div class="field field-readonly">
                <span class="field-readonly-label">{$label}</span>
                {$hintHtml}
                <div class="field-readonly-value">{$display}</div>
            </div>
            HTML;
        }

        // ── checkbox (bool) ──────────────────────────────────────────────────
        if ($inputType === 'checkbox') {
            $checked = $raw ? ' checked' : '';
            return <<<HTML
            <div class="field field-checkbox">
                <label>
                    <input type="checkbox" name="{$name}" value="1"{$checked}>
                    {$label}
                </label>
                {$hintHtml}
            </div>
            HTML;
        }

        // ── multiselect (multiEnum) ──────────────────────────────────────────
        if ($inputType === 'multiselect') {
            $currentValues = is_array($raw) ? array_map('strval', $raw) : [];
            $checkboxes = '';
            foreach ((array) ($field['options'] ?? []) as $opt) {
                if ((string) $opt === '') {
                    continue;
                }
                $safeOpt = self::e((string) $opt);
                $checked = in_array((string) $opt, $currentValues, true)
                    ? ' checked'
                    : '';
                $checkboxes .= <<<HTML
                <label class="checkbox-option">
                    <input type="checkbox" name="{$name}[]" value="{$safeOpt}"{$checked}> {$safeOpt}
                </label>
                HTML;
            }
            return <<<HTML
            <div class="field">
                <label>{$label}{$asterisk}</label>
                {$hintHtml}
                <div class="checkbox-group">{$checkboxes}</div>
            </div>
            HTML;
        }

        // ── textarea (text) ──────────────────────────────────────────────────
        if ($inputType === 'textarea') {
            $value = self::e((string) ($raw ?? ''));
            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}{$asterisk}</label>
                {$hintHtml}
                <textarea id="{$name}" name="{$name}"{$required}{$maxLength}>{$value}</textarea>
            </div>
            HTML;
        }

        // ── select (enum) ────────────────────────────────────────────────────
        if ($inputType === 'select') {
            $currentStr = (string) ($raw ?? '');
            $options = '<option value=""></option>';
            foreach ((array) ($field['options'] ?? []) as $opt) {
                if ((string) $opt === '') {
                    continue;
                }
                $safeOpt = self::e((string) $opt);
                $selected = $currentStr === (string) $opt ? ' selected' : '';
                $options .= "<option value=\"{$safeOpt}\"{$selected}>{$safeOpt}</option>";
            }
            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}{$asterisk}</label>
                {$hintHtml}
                <select id="{$name}" name="{$name}"{$required}>{$options}</select>
            </div>
            HTML;
        }

        // ── file (attachmentMultiple) ────────────────────────────────────────
        if ($inputType === 'file') {
            $accept = implode(',', (array) ($field['accept'] ?? []));
            $acceptAttr =
                $accept !== '' ? ' accept="' . self::e($accept) . '"' : '';
            $maxFileSizeAttr =
                $field['maxFileSize'] !== null
                    ? ' data-max-file-size-mb="' .
                        (int) $field['maxFileSize'] .
                        '"'
                    : '';
            $sizeHint =
                $field['maxFileSize'] !== null
                    ? 'Max file size: ' . (int) $field['maxFileSize'] . ' MB.'
                    : '';
            $hint =
                $sizeHint !== ''
                    ? '<span class="field-hint">' .
                        self::e($sizeHint) .
                        '</span>'
                    : '';

            $currentHtml = '';
            foreach ($existingFiles as $file) {
                $safeName = self::e($file['name']);
                $sizeStr = self::e($this->formatFileSize($file['size']));
                $safeKey = self::e('delete_' . $name);
                $fileUrl = self::e(
                    '/?entryPoint=contactPortalFile&token=' .
                        rawurlencode($token) .
                        '&field=' .
                        rawurlencode($name),
                );
                $currentHtml .= <<<PILL
                <div class="file-current" id="file-pill-{$safeKey}">
                    <span>&#128206;</span>
                    <a class="file-name" href="{$fileUrl}" target="_blank" rel="noopener">{$safeName}</a>
                    <span class="file-size">({$sizeStr})</span>
                    <button type="button" class="file-remove-btn" aria-label="Remove file"
                            onclick="
                                document.getElementById('file-pill-{$safeKey}').remove();
                                var h = document.createElement('input');
                                h.type = 'hidden'; h.name = '{$safeKey}'; h.value = '1';
                                this.closest('form').appendChild(h);
                            ">&#x2715;</button>
                </div>
                PILL;
            }
            $uploadHint = !empty($existingFiles)
                ? '<span class="field-hint">Upload a new file to replace the current one.</span>'
                : '';

            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}{$asterisk}</label>
                {$hintHtml}
                {$currentHtml}
                <input type="file" id="{$name}" name="{$name}"{$acceptAttr}{$maxFileSizeAttr}>
                {$uploadHint}
                {$hint}
            </div>
            HTML;
        }

        // ── all other scalar inputs ───────────────────────────────────────────
        if ($field['originalType'] === 'urlMultiple') {
            $value = self::e(is_array($raw) ? (string) ($raw[0] ?? '') : '');
        } else {
            $value = self::e(
                is_scalar($raw) || $raw === null ? (string) ($raw ?? '') : '',
            );
        }

        $step =
            $field['step'] !== null
                ? ' step="' . self::e($field['step']) . '"'
                : '';

        return <<<HTML
        <div class="field">
            <label for="{$name}">{$label}{$asterisk}</label>
            {$hintHtml}
            <input type="{$inputType}" id="{$name}" name="{$name}"
                   value="{$value}"{$required}{$maxLength}{$step}>
        </div>
        HTML;
    }

    /**
     * Returns the shared JS block that handles fetch-based form submission,
     * inline field highlighting, and the summary error banner.
     * FIXME probably this JS shouldn't be embedded in PHP
     *
     * $successHtml  – HTML to inject into .card on success (escaped by caller)
     * $submitLabel  – label on the submit button (default "Saving…" spinner text)
     */
    public function formScript(
        string $successHtml,
        string $submitLabel = 'Saving\u2026',
    ): string {
        $successJson = json_encode(
            $successHtml,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return <<<JS
        <script>
        (function () {
            var form = document.querySelector('form');

            function escHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            form.addEventListener('input', function (e) {
                var wrapper = e.target.closest('.field');
                if (wrapper) {
                    wrapper.classList.remove('has-error');
                    wrapper.querySelectorAll('.field-error-msg').forEach(function (el) { el.remove(); });
                }
                if (!form.querySelector('.has-error')) {
                    var banner = document.getElementById('cp-error-banner');
                    if (banner) banner.remove();
                }
            });

            function showErrorBanner(messages) {
                var existing = document.getElementById('cp-error-banner');
                if (existing) existing.remove();
                var banner = document.createElement('div');
                banner.id = 'cp-error-banner';
                banner.className = 'alert alert-error';
                var items = messages.map(function (m) { return '<li>' + escHtml(m) + '</li>'; }).join('');
                banner.innerHTML = '<ul style="padding-left:16px;">' + items + '</ul>';
                var actions = form.querySelector('.actions');
                form.insertBefore(banner, actions || null);
            }

            function highlightFieldError(fieldName, message) {
                var el = form.querySelector('[name="' + fieldName + '"]')
                      || form.querySelector('[name="' + fieldName + '[]"]');
                if (!el) return null;
                var wrapper = el.closest('.field');
                if (!wrapper) return null;
                wrapper.classList.add('has-error');
                var msg = document.createElement('span');
                msg.className = 'field-error-msg';
                msg.textContent = message;
                wrapper.appendChild(msg);
                return wrapper;
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();

                form.querySelectorAll('.field-error-msg').forEach(function (el) { el.remove(); });
                form.querySelectorAll('.has-error').forEach(function (el) { el.classList.remove('has-error'); });
                var banner = document.getElementById('cp-error-banner');
                if (banner) banner.remove();

                var firstError = null;
                var hasClientErrors = false;

                form.querySelectorAll('[required]').forEach(function (input) {
                    if (input.value.trim() !== '') return;
                    hasClientErrors = true;
                    var wrapper = input.closest('.field');
                    if (!wrapper) return;
                    wrapper.classList.add('has-error');
                    var msg = document.createElement('span');
                    msg.className = 'field-error-msg';
                    var labelEl = wrapper.querySelector('label');
                    var labelText = labelEl ? labelEl.textContent.replace(/\\s*\\*\\s*$/, '').trim() : 'This field';
                    msg.textContent = labelText + ' is required.';
                    wrapper.appendChild(msg);
                    if (!firstError) firstError = wrapper;
                });

                form.querySelectorAll('input[type="email"]').forEach(function (input) {
                    if (input.value.trim() === '') return;
                    if (!/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(input.value.trim())) {
                        hasClientErrors = true;
                        var wrapper = input.closest('.field');
                        if (!wrapper) return;
                        wrapper.classList.add('has-error');
                        var errMsg = document.createElement('span');
                        errMsg.className = 'field-error-msg';
                        var labelEl = wrapper.querySelector('label');
                        var labelText = labelEl ? labelEl.textContent.replace(/\\s*\\*\\s*$/, '').trim() : 'Email';
                        errMsg.textContent = labelText + ' must be a valid email address.';
                        wrapper.appendChild(errMsg);
                        if (!firstError) firstError = wrapper;
                    }
                });

                form.querySelectorAll('input[type="file"]').forEach(function (input) {
                    var maxMb = parseFloat(input.dataset.maxFileSizeMb);
                    if (!maxMb || !input.files || !input.files[0]) return;
                    var fileMb = input.files[0].size / (1024 * 1024);
                    if (fileMb > maxMb) {
                        hasClientErrors = true;
                        var wrapper = input.closest('.field');
                        if (!wrapper) return;
                        wrapper.classList.add('has-error');
                        var errMsg = document.createElement('span');
                        errMsg.className = 'field-error-msg';
                        var labelEl = wrapper.querySelector('label');
                        var labelText = labelEl ? labelEl.textContent.replace(/\\s*\\*\\s*$/, '').trim() : 'File';
                        errMsg.textContent = labelText + ' exceeds the maximum allowed size of ' + maxMb + ' MB.';
                        wrapper.appendChild(errMsg);
                        if (!firstError) firstError = wrapper;
                    }
                });

                if (hasClientErrors) {
                    if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }

                var submitBtn = form.querySelector('button[type=submit]');
                var originalLabel = submitBtn ? submitBtn.textContent : '';
                if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = '{$submitLabel}'; }

                fetch(form.action, { method: 'POST', body: new FormData(form) })
                    .then(function (res) {
                        return res.json().catch(function () {
                            throw new Error('The server returned an unexpected response (HTTP ' + res.status + '). Please reload the page and try again.');
                        });
                    })
                    .then(function (data) {
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalLabel; }
                        if (data.ok) {
                            document.querySelector('.card').innerHTML = {$successJson};
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        } else if (data.fieldErrors && Object.keys(data.fieldErrors).length) {
                            var firstWrapper = null;
                            var messages = [];
                            Object.keys(data.fieldErrors).forEach(function (fieldName) {
                                var msg = data.fieldErrors[fieldName];
                                messages.push(msg);
                                var wrapper = highlightFieldError(fieldName, msg);
                                if (wrapper && !firstWrapper) firstWrapper = wrapper;
                            });
                            showErrorBanner(messages);
                            if (firstWrapper) firstWrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        } else {
                            showErrorBanner(['An unexpected error occurred. Please try again.']);
                        }
                    })
                    .catch(function (err) {
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalLabel; }
                        var msg = (err && err.message) ? err.message : 'A network error occurred. Please check your connection and try again.';
                        showErrorBanner([msg]);
                    });
            });
        })();
        </script>
        JS;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return (string) round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }
}
