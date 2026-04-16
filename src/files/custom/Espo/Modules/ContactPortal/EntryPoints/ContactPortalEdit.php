<?php
namespace Espo\Modules\ContactPortal\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;

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
    private function findContactByToken(string $token): mixed
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
        $saveUrl   = HtmlRenderer::e('/api/v1/ContactPortal/save');
        $safeToken = HtmlRenderer::e($token);

        $fieldsHtml = '';
        foreach ($this->fieldProvider->getFields() as $field) {
            $fieldsHtml .= $this->renderField($field, $contact);
        }

        return <<<HTML
        <h1>Your details</h1>
        <p class="subtitle">Update the fields below and click Save.</p>

        <form method="POST" action="{$saveUrl}">
            <input type="hidden" name="token" value="{$safeToken}">

            {$fieldsHtml}

            <div class="actions">
                <button type="submit" class="btn">Save changes</button>
            </div>
        </form>
        HTML;
    }

    /**
     * Renders a single form field appropriate to its type.
     *
     * @param array<string, mixed> $field
     */
    private function renderField(array $field, mixed $contact): string
    {
        $name      = $field['name'];
        $label     = HtmlRenderer::e($field['label']);
        $inputType = $field['inputType'];
        $required  = $field['required'] ? ' required' : '';
        $maxLength = $field['maxLength'] !== null ? ' maxlength="' . (int) $field['maxLength'] . '"' : '';

        if ($inputType === 'checkbox') {
            $checked = $contact->get($name) ? ' checked' : '';
            return <<<HTML
            <div class="field field-checkbox">
                <label>
                    <input type="checkbox" name="{$name}" value="1"{$checked}>
                    {$label}
                </label>
            </div>
            HTML;
        }

        $value = HtmlRenderer::e((string) ($contact->get($name) ?? ''));

        if ($inputType === 'textarea') {
            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}</label>
                <textarea id="{$name}" name="{$name}"{$required}{$maxLength}>{$value}</textarea>
            </div>
            HTML;
        }

        if ($inputType === 'select') {
            $options = '<option value=""></option>';
            foreach ((array) ($field['options'] ?? []) as $opt) {
                $safeOpt  = HtmlRenderer::e((string) $opt);
                $selected = $value === $safeOpt ? ' selected' : '';
                $options .= "<option value=\"{$safeOpt}\"{$selected}>{$safeOpt}</option>";
            }
            return <<<HTML
            <div class="field">
                <label for="{$name}">{$label}</label>
                <select id="{$name}" name="{$name}"{$required}>{$options}</select>
            </div>
            HTML;
        }

        $step = $field['step'] !== null ? ' step="' . HtmlRenderer::e($field['step']) . '"' : '';

        return <<<HTML
        <div class="field">
            <label for="{$name}">{$label}</label>
            <input type="{$inputType}" id="{$name}" name="{$name}"
                   value="{$value}"{$required}{$maxLength}{$step}>
        </div>
        HTML;
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
