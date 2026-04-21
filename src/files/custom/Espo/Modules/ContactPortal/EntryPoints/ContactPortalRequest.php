<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;

/**
 * GET ?entryPoint=contactPortalRequest
 *
 * Renders the email-input form. The form POSTs to /api/v1/ContactPortal/request
 * which is handled by Actions\HandleRequest — token generation and emailing
 * live there, not here.
 */
class ContactPortalRequest implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private readonly HtmlRenderer $htmlRenderer,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $response->writeBody(
            $this->htmlRenderer->render('Access your details', $this->renderForm())
        );
    }

    // -------------------------------------------------------------------------

    private function renderForm(): string
    {
        return <<<HTML
        <h1>Access your details</h1>
        <p class="subtitle">Enter the email address we have on file and we'll send you a secure link.</p>
        <form method="POST" action="/api/v1/ContactPortal/request">
            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" maxlength="254" required autofocus>
            </div>
            <div class="actions">
                <button type="submit" class="btn">Send me a link</button>
            </div>
        </form>
        HTML;
    }
}
