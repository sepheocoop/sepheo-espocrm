<?php
namespace Espo\Modules\ContactPortal\Actions;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;

/**
 * POST /api/v1/ContactPortal/save
 *
 * Re-validates the magic-link token, sanitises input, saves the Contact,
 * then nullifies the token (one-time use).
 */
class HandleSave implements Action
{
    /** Maximum allowed byte lengths for each writable field. */
    private const MAX_LENGTHS = [
        'firstName'    => 100,
        'lastName'     => 100,
        'title'        => 100,
        'emailAddress' => 254,
        'phoneNumber'  => 50,
    ];

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly HtmlRenderer $htmlRenderer,
    ) {}

    public function process(Request $request): Response
    {
        $token = trim((string) ($request->getParsedBody()->token ?? ''));

        if ($token === '') {
            return $this->htmlResponse(
                $this->htmlRenderer->render('Invalid request', $this->renderError('No token provided.'))
            );
        }

        $contact = $this->findContactByToken($token);

        if (!$contact) {
            return $this->htmlResponse(
                $this->htmlRenderer->render('Link expired', $this->renderError())
            );
        }

        $input  = $this->sanitise($request);
        $errors = $this->validate($input);

        if ($errors) {
            return $this->htmlResponse(
                $this->htmlRenderer->render('Validation error', $this->renderValidationError($errors))
            );
        }

        $contact->set('firstName',    $input['firstName']);
        $contact->set('lastName',     $input['lastName']);
        $contact->set('title',        $input['title']);
        $contact->set('emailAddress', $input['emailAddress']);
        $contact->set('phoneNumber',  $input['phoneNumber']);

        // Invalidate — one-time use only.
        $contact->set('portalToken',       null);
        $contact->set('portalTokenExpiry', null);

        $this->entityManager->saveEntity($contact);

        return $this->htmlResponse(
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
     * @return array<string, string>
     */
    private function sanitise(Request $request): array
    {
        $body = $request->getParsedBody();
        $out  = [];

        foreach (array_keys(self::MAX_LENGTHS) as $field) {
            $out[$field] = trim(strip_tags((string) ($body->$field ?? '')));
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

        foreach (self::MAX_LENGTHS as $field => $max) {
            if (strlen($input[$field]) > $max) {
                $errors[] = ucfirst($field) . " must not exceed {$max} characters.";
            }
        }

        return $errors;
    }

    private function htmlResponse(string $html): Response
    {
        return ResponseComposer::empty()
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->writeBody($html);
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

        return <<<HTML
        <div class="alert alert-error">
            <ul style="padding-left:16px;">{$items}</ul>
        </div>
        <div class="actions">
            <a href="javascript:history.back()" class="btn btn-secondary">← Go back</a>
        </div>
        HTML;
    }
}
