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
            $this->htmlRenderer->render('Join Sepheo', $this->renderForm()),
        );
    }

    // -------------------------------------------------------------------------

    private function renderForm(): string
    {
        $saveUrl = HtmlRenderer::e('/api/v1/ContactPortal/register');
        $fieldsHtml = '';

        foreach ($this->fieldProvider->getRegistrationFields() as $field) {
            $fieldsHtml .= $this->htmlRenderer->renderFormField($field);
        }

        $successHtml = <<<HTML
        <div class="alert alert-success">Thanks &mdash; we&#039;ll be in touch!</div>
        <p>We&#039;ve received your details. A member of the Sepheo team will be in touch with you soon.</p>
        HTML;

        $script = $this->htmlRenderer->formScript(
            $successHtml,
            'Submitting\u2026',
        );

        return <<<HTML
        <h1>Registration Form</h1>

        <div class="description">
            <p>If you're a freelancer, and you're interested in being in our directory of freelancers, please let us know using this form. Besides expressing your interest and giving us a way to contact and consult you, it's a first opportunity for you to tell us what a freelancer network could do for you, and what you could do for the network. Thanks!
            <br><br>Fields marked <span class="required-asterisk" aria-hidden="true">*</span> are required.</p>
        </div>

        <form method="POST" action="{$saveUrl}" enctype="multipart/form-data" novalidate>

            {$fieldsHtml}

            <div class="actions">
                <button type="submit" class="btn">Submit</button>
            </div>
        </form>

        {$script}
        HTML;
    }
}
