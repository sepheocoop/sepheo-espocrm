<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;
use Espo\ORM\Entity;

/**
 * Entry point: GET ?entryPoint=contactPortalEdit&token=XXXX
 *
 * Validates the magic-link token and renders a pre-filled edit form.
 */
class ContactPortalEdit implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly HtmlRenderer $htmlRenderer,
        private readonly ContactFieldProvider $fieldProvider,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $token = trim((string) ($request->getQueryParam('token') ?? ''));

        if ($token === '') {
            $response->writeBody(
                $this->htmlRenderer->render('Invalid link', $this->renderError())
            );
            return;
        }

        $contact = $this->findContactByToken($token);

        if (!$contact) {
            $response->writeBody(
                $this->htmlRenderer->render('Link expired', $this->renderError())
            );
            return;
        }

        $response->writeBody(
            $this->htmlRenderer->render(
                'Edit your details',
                $this->renderForm($contact, $token)
            )
        );
    }

    // -------------------------------------------------------------------------

    /**
     * @return \Espo\ORM\Entity|null
     */
    private function findContactByToken(string $token): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where([
                'portalToken'       => $token,
                'portalTokenExpiry>' => date('Y-m-d H:i:s'),
            ])
            ->findOne();
    }

    // -------------------------------------------------------------------------

    private function renderForm(mixed $contact, string $token): string
    {
        // Token goes in the query string, not the POST body.
        // Reason: enctype="multipart/form-data" causes PHP to consume php://input
        // before EspoCRM reads it, so getParsedBody() returns an empty object
        // and any hidden field value is lost. getQueryParam() is body-independent.
        $saveUrl = '/api/v1/ContactPortal/save?token=' . rawurlencode($token);
        $saveUrl = HtmlRenderer::e($saveUrl);

        // Pre-fetch existing attachments for all file-type fields so we can
        // display "current file" info in the form.
        $existingFiles = [];
        foreach ($this->fieldProvider->getFields() as $field) {
            if ($field['inputType'] !== 'file') {
                continue;
            }
            $attachments = $this->entityManager
                ->getRDBRepository('Attachment')
                ->where([
                    'parentType' => 'Contact',
                    'parentId'   => $contact->getId(),
                    'field'      => $field['name'],
                    'role'       => 'Attachment',
                ])
                ->find();
            $files = [];
            foreach ($attachments as $att) {
                $files[] = [
                    'name' => (string) ($att->get('name') ?? 'file'),
                    'size' => (int) $att->get('size'),
                ];
            }
            $existingFiles[$field['name']] = $files;
        }

        $fieldsHtml = '';
        foreach ($this->fieldProvider->getFields() as $field) {
            $fieldsHtml .= $this->renderField($field, $contact, $existingFiles, $token);
        }

        return <<<HTML
        <h1>Your member details</h1>

        <div class="description">
            <p>We use this information to maintain our member directory and to connect you with
            relevant freelancers, organisations, and opportunities within the Sepheo network.
            Your data is held securely and is only shared with other Sepheo members and
            partner organisations in line with our co-operative values.</p>
            <p>Update any fields below and click <strong>Save changes</strong>. Your magic link
            can be used once — request a fresh one at any time to return to this form.</p>
        </div>

        <form method="POST" action="{$saveUrl}" enctype="multipart/form-data" novalidate>

            {$fieldsHtml}

            <div class="actions">
                <button type="submit" class="btn">Save changes</button>
            </div>
        </form>

        <script>
        document.querySelector('form').addEventListener('submit', function (e) {
            // Clear previous inline errors.
            this.querySelectorAll('.field-error-msg').forEach(function (el) { el.remove(); });
            this.querySelectorAll('.has-error').forEach(function (el) { el.classList.remove('has-error'); });

            var firstError = null;

            // Check every required input / textarea / select.
            this.querySelectorAll('[required]').forEach(function (input) {
                var empty = input.value.trim() === '';
                if (!empty) return;

                e.preventDefault();

                var wrapper = input.closest('.field');
                if (!wrapper) return;

                wrapper.classList.add('has-error');

                var msg = document.createElement('span');
                msg.className = 'field-error-msg';

                // Find the label text for a friendlier message.
                var labelEl = wrapper.querySelector('label');
                var labelText = labelEl ? labelEl.textContent.trim() : 'This field';
                msg.textContent = labelText + ' is required.';
                wrapper.appendChild(msg);

                if (!firstError) firstError = wrapper;
            });

            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // Clear the error state as soon as the user starts correcting a field.
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
     * Renders a single form field appropriate to its type.
     *
     * @param array<string, mixed> $field
     * @param array<string, list<array{name:string,size:int}>> $existingFiles
     */
    private function renderField(array $field, mixed $contact, array $existingFiles = [], string $token = ''): string
    {
        $name      = $field['name'];
        $label     = HtmlRenderer::e($field['label']);
        $inputType = $field['inputType'];
        $required  = $field['required'] ? ' required' : '';
        $maxLength = $field['maxLength'] !== null ? ' maxlength="' . (int) $field['maxLength'] . '"' : '';
        $raw       = $contact->get($name);

        $rawHint  = (string) ($field['hint'] ?? '');
        $hintHtml = $rawHint !== ''
            ? '<span class="field-desc">' . nl2br(HtmlRenderer::e($rawHint)) . '</span>'
            : '';

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

        // ── multiselect (multiEnum) — rendered as a checkbox list ───────────
        if ($inputType === 'multiselect') {
            // EspoCRM may return an EntityCollection for some field types instead
            // of a plain PHP array — guard with is_array to avoid a cast exception.
            $currentValues = is_array($raw) ? array_map('strval', $raw) : [];
            $checkboxes = '';
            foreach ((array) ($field['options'] ?? []) as $opt) {
                if ((string) $opt === '') {
                    continue; // skip blank placeholder options
                }
                $safeOpt  = HtmlRenderer::e((string) $opt);
                $checked  = in_array((string) $opt, $currentValues, true) ? ' checked' : '';
                $checkboxes .= <<<HTML
                <label class="checkbox-option">
                    <input type="checkbox" name="{$name}[]" value="{$safeOpt}"{$checked}> {$safeOpt}
                </label>
                HTML;
            }
            return <<<HTML
            <div class="field">
                <label>{$label}</label>
                {$hintHtml}
                <div class="checkbox-group">{$checkboxes}</div>
            </div>
            HTML;
        }

        // ── textarea (text) ──────────────────────────────────────────────────
        if ($inputType === 'textarea') {
            $value = HtmlRenderer::e((string) ($raw ?? ''));
            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}</label>
                {$hintHtml}
                <textarea id="{$name}" name="{$name}"{$required}{$maxLength}>{$value}</textarea>
            </div>
            HTML;
        }

        // ── select (enum) ────────────────────────────────────────────────────
        if ($inputType === 'select') {
            $currentStr = (string) ($raw ?? '');
            // Always prepend one explicit blank placeholder option.
            // EspoCRM metadata often includes '' as first item too — skip those
            // to avoid a duplicate blank option appearing in the dropdown.
            $options = '<option value=""></option>';
            foreach ((array) ($field['options'] ?? []) as $opt) {
                if ((string) $opt === '') {
                    continue; // skip blank entries from metadata
                }
                $safeOpt  = HtmlRenderer::e((string) $opt);
                $selected = $currentStr === (string) $opt ? ' selected' : '';
                $options .= "<option value=\"{$safeOpt}\"{$selected}>{$safeOpt}</option>";
            }
            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}</label>
                {$hintHtml}
                <select id="{$name}" name="{$name}"{$required}>{$options}</select>
            </div>
            HTML;
        }

        // ── file (attachmentMultiple) — render before computing $value ───────
        // cAttachment->get() returns an EntityCollection, not a scalar.
        if ($inputType === 'file') {
            $accept = implode(',', (array) ($field['accept'] ?? []));
            $acceptAttr = $accept !== '' ? ' accept="' . HtmlRenderer::e($accept) . '"' : '';
            $sizeHint = $field['maxFileSize'] !== null
                ? 'Max file size: ' . (int) $field['maxFileSize'] . ' MB.'
                : '';
            $hint = $sizeHint !== '' ? '<span class="field-hint">' . HtmlRenderer::e($sizeHint) . '</span>' : '';

            // Render existing file pill(s) with a preview link and an X button to remove.
            $currentHtml = '';
            foreach ($existingFiles[$name] ?? [] as $file) {
                $safeName   = HtmlRenderer::e($file['name']);
                $sizeStr    = HtmlRenderer::e($this->formatFileSize($file['size']));
                $safeKey    = HtmlRenderer::e('delete_' . $name);
                $fileUrl    = HtmlRenderer::e(
                    '/?entryPoint=contactPortalFile&token=' . rawurlencode($token) . '&field=' . rawurlencode($name)
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
            $uploadHint = !empty($existingFiles[$name])
                ? '<span class="field-hint">Upload a new file to replace the current one.</span>'
                : '';

            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}</label>
                {$hintHtml}
                {$currentHtml}
                <input type="file" id="{$name}" name="{$name}"{$acceptAttr}>
                {$uploadHint}
                {$hint}
            </div>
            HTML;
        }

        // ── all other inputs (text, email, tel, url, number, date, …) ————
        // urlMultiple stores a PHP array of strings — use the first entry.
        // For both branches, guard against EntityCollection or other objects.
        if ($field['originalType'] === 'urlMultiple') {
            $value = HtmlRenderer::e(is_array($raw) ? (string) ($raw[0] ?? '') : '');
        } else {
            $value = HtmlRenderer::e(is_scalar($raw) || $raw === null ? (string) ($raw ?? '') : '');
        }

        $step = $field['step'] !== null ? ' step="' . HtmlRenderer::e($field['step']) . '"' : '';

        return <<<HTML
        <div class="field">
            <label for="{$name}">{$label}</label>
            {$hintHtml}
            <input type="{$inputType}" id="{$name}" name="{$name}"
                   value="{$value}"{$required}{$maxLength}{$step}>
        </div>
        HTML;
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

    private function renderError(): string
    {
        $requestUrl = HtmlRenderer::e('/?entryPoint=contactPortalRequest');

        return <<<HTML
        <div class="alert alert-error">
            This link is invalid or has expired.
        </div>
        <p>Magic links can only be used once and expire after 24 hours.</p>
        <div class="actions">
            <a href="{$requestUrl}" class="btn">Request a new link</a>
        </div>
        HTML;
    }
}
