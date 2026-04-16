<?php
namespace Espo\Modules\ContactPortal\Actions;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;

/**
 * POST /api/v1/ContactPortal/save
 *
 * Re-validates the magic-link token, sanitises input, saves the Contact,
 * then nullifies the token (one-time use).
 */
class HandleSave implements Action
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly HtmlRenderer $htmlRenderer,
        private readonly ContactFieldProvider $fieldProvider,
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

        foreach ($this->fieldProvider->getFields() as $field) {
            $name = $field['name'];
            if (array_key_exists($name, $input)) {
                $contact->set($name, $input[$name]);
            }
        }

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
     * @return array<string, mixed>
     */
    private function sanitise(Request $request): array
    {
        $body   = $request->getParsedBody();
        $fields = $this->fieldProvider->getFields();
        $out    = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            if ($field['inputType'] === 'checkbox') {
                // Unchecked checkboxes are not submitted — default to false.
                $out[$name] = !empty($body->$name);
            } else {
                $out[$name] = trim(strip_tags((string) ($body->$name ?? '')));
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return string[]
     */
    private function validate(array $input): array
    {
        $errors = [];

        foreach ($this->fieldProvider->getFields() as $field) {
            $name  = $field['name'];
            $label = $field['label'];
            $value = $input[$name] ?? '';

            if ($field['required'] && $field['inputType'] !== 'checkbox' && $value === '') {
                $errors[] = "{$label} is required.";
                continue;
            }

            if ($field['inputType'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "{$label} must be a valid email address.";
            }

            if ($field['maxLength'] !== null && is_string($value) && strlen($value) > $field['maxLength']) {
                $errors[] = "{$label} must not exceed {$field['maxLength']} characters.";
            }

            if ($field['inputType'] === 'select' && $value !== '' && $field['options'] !== null
                && !in_array($value, $field['options'], true)) {
                $errors[] = "{$label} contains an invalid value.";
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
