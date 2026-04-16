<?php
namespace Espo\Modules\ContactPortal\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\ORM\EntityManager;
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
        $e         = HtmlRenderer::e(...);
        $saveUrl   = $e('/api/v1/ContactPortal/save');
        $safeToken = $e($token);

        $firstName   = $e((string) $contact->get('firstName'));
        $lastName    = $e((string) $contact->get('lastName'));
        $title       = $e((string) $contact->get('title'));
        $emailAddr   = $e((string) $contact->get('emailAddress'));
        $phoneNumber = $e((string) $contact->get('phoneNumber'));

        return <<<HTML
        <h1>Your details</h1>
        <p class="subtitle">Update the fields below and click Save.</p>

        <form method="POST" action="{$saveUrl}">
            <input type="hidden" name="token" value="{$safeToken}">

            <div class="row">
                <div class="field">
                    <label for="firstName">First name</label>
                    <input type="text" id="firstName" name="firstName"
                           value="{$firstName}" maxlength="100" required>
                </div>
                <div class="field">
                    <label for="lastName">Last name</label>
                    <input type="text" id="lastName" name="lastName"
                           value="{$lastName}" maxlength="100" required>
                </div>
            </div>

            <div class="field">
                <label for="title">Job title</label>
                <input type="text" id="title" name="title"
                       value="{$title}" maxlength="100">
            </div>

            <div class="field">
                <label for="emailAddress">Email address</label>
                <input type="email" id="emailAddress" name="emailAddress"
                       value="{$emailAddr}" maxlength="254" required>
            </div>

            <div class="field">
                <label for="phoneNumber">Phone number</label>
                <input type="tel" id="phoneNumber" name="phoneNumber"
                       value="{$phoneNumber}" maxlength="50">
            </div>

            <div class="actions">
                <button type="submit" class="btn">Save changes</button>
            </div>
        </form>
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
