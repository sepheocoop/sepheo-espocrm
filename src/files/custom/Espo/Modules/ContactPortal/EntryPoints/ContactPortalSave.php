<?php
namespace Espo\Modules\ContactPortal\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;

/**
 * Entry point: POST ?entryPoint=contactPortalSave
 *
 * Re-validates the token, sanitises input, saves the Contact, then
 * invalidates the token (one-time use).
 */
class ContactPortalSave implements EntryPoint
{
    use NoAuth;

    /** Maximum allowed byte lengths for text inputs. */
    private const MAX_LENGTHS = [
        'firstName'   => 100,
        'lastName'    => 100,
        'title'       => 100,
        'emailAddress'=> 254,
        'phoneNumber' => 50,
    ];

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly HtmlRenderer $htmlRenderer,
    ) {}

    public function run(Request $request, Response $response): void
    {
        // Only accept POST.
        if (strtoupper($request->getMethod()) !== 'POST') {
            $response->setStatus(405);
            return;
        }

        $body  = $request->getParsedBody() ?? [];
        $token = trim((string) ($body['token'] ?? ''));

        if ($token === '') {
            $response->writeBody(
                $this->htmlRenderer->render('Invalid request', $this->renderError('No token provided.'))
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

        // Validate and sanitise.
        $input  = $this->sanitise($body);
        $errors = $this->validate($input);

        if ($errors) {
            $response->writeBody(
                $this->htmlRenderer->render('Validation error', $this->renderValidationError($errors))
            );
            return;
        }

        // Apply changes to the Contact.
        $contact->set('firstName',    $input['firstName']);
        $contact->set('lastName',     $input['lastName']);
        $contact->set('title',        $input['title']);
        $contact->set('emailAddress', $input['emailAddress']);
        $contact->set('phoneNumber',  $input['phoneNumber']);

        // Invalidate the token (one-time use).
        $contact->set('portalToken',       null);
        $contact->set('portalTokenExpiry', null);

        $this->entityManager->saveEntity($contact);

        $response->writeBody(
            $this->htmlRenderer->render('Details updated', $this->renderSuccess())
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
                'portalToken'        => $token,
                'portalTokenExpiry>' => date('Y-m-d H:i:s'),
            ])
            ->findOne();
    }

    /**
     * Strip tags and trim every incoming field.  Only known keys are returned.
     *
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function sanitise(array $body): array
    {
        $out = [];

        foreach (array_keys(self::MAX_LENGTHS) as $field) {
            $raw       = (string) ($body[$field] ?? '');
            $out[$field] = trim(strip_tags($raw));
        }

        return $out;
    }

    /**
     * @param array<string, string> $input
     * @return string[]
     */
    private function validate(array $input): array
    {
        $errors = [];

        if ($input['firstName'] === '') {
            $errors[] = 'First name is required.';
        }
        if ($input['lastName'] === '') {
            $errors[] = 'Last name is required.';
        }
        if ($input['emailAddress'] === '' || !filter_var($input['emailAddress'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }

        // Enforce max lengths.
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (strlen($input[$field]) > $max) {
                $label    = ucfirst($field);
                $errors[] = "{$label} must not exceed {$max} characters.";
            }
        }

        return $errors;
    }

    // -------------------------------------------------------------------------

    private function renderSuccess(): string
    {
        $requestUrl = HtmlRenderer::e('/?entryPoint=contactPortalRequest');

        return <<<HTML
        <div class="alert alert-success">
            Your details have been updated successfully.
        </div>
        <p>Thank you! Your changes are now saved.</p>
        <div class="actions" style="margin-top:20px;">
            <a href="{$requestUrl}" class="link">← Request another access link</a>
        </div>
        HTML;
    }

    private function renderError(string $detail = ''): string
    {
        $requestUrl = HtmlRenderer::e('/?entryPoint=contactPortalRequest');
        $detailHtml = $detail ? '<p>' . HtmlRenderer::e($detail) . '</p>' : '';

        return <<<HTML
        <div class="alert alert-error">
            This link is invalid or has expired.
        </div>
        {$detailHtml}
        <p>Magic links can only be used once and expire after 24 hours.</p>
        <div class="actions">
            <a href="{$requestUrl}" class="btn">Request a new link</a>
        </div>
        HTML;
    }

    /**
     * @param string[] $errors
     */
    private function renderValidationError(array $errors): string
    {
        $items = implode('', array_map(
            fn(string $e) => '<li>' . HtmlRenderer::e($e) . '</li>',
            $errors
        ));

        $backUrl = HtmlRenderer::e('javascript:history.back()');

        return <<<HTML
        <div class="alert alert-error">
            <ul style="padding-left:16px;">{$items}</ul>
        </div>
        <div class="actions">
            <a href="{$backUrl}" class="btn btn-secondary">← Go back</a>
        </div>
        HTML;
    }
}
