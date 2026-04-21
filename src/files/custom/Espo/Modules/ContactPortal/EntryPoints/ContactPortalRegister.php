<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;

/**
 * Entry point: GET /?entryPoint=contactPortalRegister
 *
 * Renders a blank registration form for new contacts.
 * The form POSTs to /api/v1/ContactPortal/register (HandleRegister action).
 */
class ContactPortalRegister implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private readonly HtmlRenderer $htmlRenderer,
        private readonly ContactFieldProvider $fieldProvider,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $response->writeBody(
            $this->htmlRenderer->render('Join Sepheo', $this->renderForm())
        );
    }

    // -------------------------------------------------------------------------

    private function renderForm(): string
    {
        $saveUrl    = HtmlRenderer::e('/api/v1/ContactPortal/register');
        $fieldsHtml = '';

        foreach ($this->fieldProvider->getRegistrationFields() as $field) {
            $fieldsHtml .= $this->renderField($field);
        }

        return <<<HTML
        <h1>Registration Form</h1>

        <div class="description">
            <p>If you're a freelancer, and you're interested in being in our directory of freelancers, please let us know using this form. Besides expressing your interest and giving us a way to contact and consult you, it's a first opportunity for you to tell us what a freelancer network could do for you, and what you could do for the network. Thanks!
            <br><br>Fields marked <span style="color:#c0392b;">*</span> are required.</p>
        </div>

        <form method="POST" action="{$saveUrl}" enctype="multipart/form-data" novalidate>

            {$fieldsHtml}

            <div class="actions">
                <button type="submit" class="btn">Submit</button>
            </div>
        </form>

        <script>
        document.querySelector('form').addEventListener('submit', function (e) {
            this.querySelectorAll('.field-error-msg').forEach(function (el) { el.remove(); });
            this.querySelectorAll('.has-error').forEach(function (el) { el.classList.remove('has-error'); });

            var firstError = null;

            this.querySelectorAll('[required]').forEach(function (input) {
                if (input.value.trim() !== '') return;

                e.preventDefault();

                var wrapper = input.closest('.field');
                if (!wrapper) return;

                wrapper.classList.add('has-error');

                var msg = document.createElement('span');
                msg.className = 'field-error-msg';
                var labelEl = wrapper.querySelector('label');
                var labelText = labelEl ? labelEl.textContent.replace('*', '').trim() : 'This field';
                msg.textContent = labelText + ' is required.';
                wrapper.appendChild(msg);

                if (!firstError) firstError = wrapper;
            });

            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        document.querySelector('form').addEventListener('input', function (e) {
            var wrapper = e.target.closest('.field');
            if (!wrapper) return;
            wrapper.classList.remove('has-error');
            wrapper.querySelectorAll('.field-error-msg').forEach(function (el) { el.remove(); });
        });
        </script>
        HTML;
    }

    /**
     * Renders a single blank form field (no pre-filled value).
     * ReadOnly fields are rendered as editable in registration context.
     *
     * @param array<string, mixed> $field
     */
    private function renderField(array $field): string
    {
        $name      = $field['name'];
        $label     = HtmlRenderer::e($field['label']);
        $inputType = $field['inputType'];
        $required  = $field['required'] ? ' required' : '';
        $reqMarker = $field['required'] ? ' <span style="color:#c0392b;">*</span>' : '';
        $maxLength = $field['maxLength'] !== null ? ' maxlength="' . (int) $field['maxLength'] . '"' : '';

        $rawHint  = (string) ($field['hint'] ?? '');
        $hintHtml = $rawHint !== ''
            ? '<span class="field-desc">' . nl2br(HtmlRenderer::e($rawHint)) . '</span>'
            : '';

        // ── checkbox (bool) ──────────────────────────────────────────────────
        if ($inputType === 'checkbox') {
            return <<<HTML
            <div class="field field-checkbox">
                <label>
                    <input type="checkbox" name="{$name}" value="1">
                    {$label}
                </label>
                {$hintHtml}
            </div>
            HTML;
        }

        // ── multiselect (multiEnum) — rendered as checkbox list ──────────────
        if ($inputType === 'multiselect') {
            $checkboxes = '';
            foreach ((array) ($field['options'] ?? []) as $opt) {
                if ((string) $opt === '') {
                    continue;
                }
                $safeOpt     = HtmlRenderer::e((string) $opt);
                $checkboxes .= <<<HTML
                <label class="checkbox-option">
                    <input type="checkbox" name="{$name}[]" value="{$safeOpt}"> {$safeOpt}
                </label>
                HTML;
            }
            return <<<HTML
            <div class="field">
                <label>{$label}{$reqMarker}</label>
                {$hintHtml}
                <div class="checkbox-group">{$checkboxes}</div>
            </div>
            HTML;
        }

        // ── textarea (text) ──────────────────────────────────────────────────
        if ($inputType === 'textarea') {
            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}{$reqMarker}</label>
                {$hintHtml}
                <textarea id="{$name}" name="{$name}"{$required}{$maxLength}></textarea>
            </div>
            HTML;
        }

        // ── select (enum) ────────────────────────────────────────────────────
        if ($inputType === 'select') {
            $options = '<option value=""></option>';
            foreach ((array) ($field['options'] ?? []) as $opt) {
                if ((string) $opt === '') {
                    continue;
                }
                $safeOpt  = HtmlRenderer::e((string) $opt);
                $options .= "<option value=\"{$safeOpt}\">{$safeOpt}</option>";
            }
            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}{$reqMarker}</label>
                {$hintHtml}
                <select id="{$name}" name="{$name}"{$required}>{$options}</select>
            </div>
            HTML;
        }

        // ── file (attachmentMultiple) ────────────────────────────────────────
        if ($inputType === 'file') {
            $accept     = implode(',', (array) ($field['accept'] ?? []));
            $acceptAttr = $accept !== '' ? ' accept="' . HtmlRenderer::e($accept) . '"' : '';
            $sizeHint   = $field['maxFileSize'] !== null
                ? '<span class="field-hint">Max file size: ' . (int) $field['maxFileSize'] . ' MB.</span>'
                : '';
            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}{$reqMarker}</label>
                {$hintHtml}
                <input type="file" id="{$name}" name="{$name}"{$acceptAttr}{$required}>
                {$sizeHint}
            </div>
            HTML;
        }

        // ── all other inputs (text, email, tel, url, number, date, …) ────────
        $step = $field['step'] !== null ? ' step="' . HtmlRenderer::e($field['step']) . '"' : '';

        return <<<HTML
        <div class="field">
            <label for="{$name}">{$label}{$reqMarker}</label>
            {$hintHtml}
            <input type="{$inputType}" id="{$name}" name="{$name}"{$required}{$maxLength}{$step}>
        </div>
        HTML;
    }
}
