<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Actions;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\ContactPortal\Util\AttachmentSaver;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;
use Espo\ORM\Entity;

/**
 * POST /api/v1/ContactPortal/register
 *
 * Creates a new Contact from a public registration form and returns a
 * simple "Thanks, we'll be in touch" confirmation.
 *
 * If the submitted email address already exists in the CRM, the request
 * silently succeeds — we do not reveal whether the address is known.
 */
class HandleRegister implements Action
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly HtmlRenderer $htmlRenderer,
        private readonly ContactFieldProvider $fieldProvider,
        private readonly AttachmentSaver $attachmentSaver,
    ) {}

    public function process(Request $request): Response
    {
        $fields = $this->fieldProvider->getRegistrationFields();
        $input  = $this->sanitise($fields);
        $errors = $this->validate($input, $fields);

        if ($errors) {
            return $this->htmlResponse(
                $this->htmlRenderer->render('Validation error', $this->renderValidationError($errors))
            );
        }

        // Don't reveal whether the email is already registered.
        $email = (string) ($input['emailAddress'] ?? '');
        if ($email !== '' && $this->emailExists($email)) {
            return $this->htmlResponse(
                $this->htmlRenderer->render('Thank you', $this->renderSuccess())
            );
        }

        /** @var Entity $contact */
        $contact = $this->entityManager->getNewEntity('Contact');

        foreach ($fields as $field) {
            if ($field['inputType'] === 'file' || $field['readOnly']) {
                continue;
            }

            $name  = $field['name'];
            $value = $input[$name] ?? null;

            if ($value === null) {
                continue;
            }

            // urlMultiple is stored as a JSON array; we capture the first URL only.
            if ($field['originalType'] === 'urlMultiple') {
                $value = ($value !== '') ? [(string) $value] : [];
            }

            $contact->set($name, $value);
        }

        $this->entityManager->saveEntity($contact);

        // Handle file uploads after save so the contact ID is available.
        foreach ($fields as $field) {
            if ($field['inputType'] !== 'file') {
                continue;
            }

            $fileErr = $this->attachmentSaver->save($contact, $field);

            if ($fileErr !== null) {
                return $this->htmlResponse(
                    $this->htmlRenderer->render('Upload error', $this->renderError($fileErr))
                );
            }
        }

        return $this->htmlResponse(
            $this->htmlRenderer->render('Thank you', $this->renderSuccess())
        );
    }

    // -------------------------------------------------------------------------

    private function emailExists(string $email): bool
    {
        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['emailAddress' => $email])
            ->findOne() !== null;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function sanitise(array $fields): array
    {
        $post = $_POST;
        $out  = [];

        foreach ($fields as $field) {
            $name      = $field['name'];
            $inputType = $field['inputType'];

            if ($inputType === 'file') {
                continue;
            }

            if ($inputType === 'checkbox') {
                $out[$name] = !empty($post[$name]);
            } elseif ($inputType === 'multiselect') {
                $raw = $post[$name] ?? null;
                if (is_array($raw)) {
                    $out[$name] = array_map(fn($v) => strip_tags((string) $v), $raw);
                } elseif ($raw !== null && $raw !== '') {
                    $out[$name] = [strip_tags((string) $raw)];
                } else {
                    $out[$name] = [];
                }
            } else {
                $out[$name] = trim(strip_tags((string) ($post[$name] ?? '')));
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>      $input
     * @param list<array<string, mixed>> $fields
     * @return string[]
     */
    private function validate(array $input, array $fields): array
    {
        $errors = [];

        foreach ($fields as $field) {
            $name      = $field['name'];
            $label     = $field['label'];
            $inputType = $field['inputType'];
            $value     = $input[$name] ?? ($inputType === 'multiselect' ? [] : '');

            if ($inputType === 'checkbox' || $inputType === 'file') {
                continue;
            }

            if ($inputType === 'multiselect') {
                if ($field['required'] && empty($value)) {
                    $errors[] = "{$label} is required.";
                }
                if ($field['options'] !== null && is_array($value)) {
                    foreach ($value as $v) {
                        if (!in_array($v, $field['options'], true)) {
                            $errors[] = "{$label} contains an invalid value.";
                            break;
                        }
                    }
                }
                continue;
            }

            if ($field['required'] && (string) $value === '') {
                $errors[] = "{$label} is required.";
                continue;
            }

            if ($inputType === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "{$label} must be a valid email address.";
            }

            if ($field['maxLength'] !== null && is_string($value) && strlen($value) > $field['maxLength']) {
                $errors[] = "{$label} must not exceed {$field['maxLength']} characters.";
            }

            if ($inputType === 'select' && $value !== '' && $field['options'] !== null
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
        return <<<HTML
        <div class="alert alert-success">
            Thanks — we'll be in touch!
        </div>
        <p>We've received your details. A member of the Sepheo team will be in touch with you soon.</p>
        HTML;
    }

    private function renderError(string $detail = ''): string
    {
        $registerUrl = HtmlRenderer::e('/?entryPoint=contactPortalRegister');
        $detailHtml  = $detail ? '<p>' . HtmlRenderer::e($detail) . '</p>' : '';

        return <<<HTML
        <div class="alert alert-error">
            Something went wrong.
        </div>
        {$detailHtml}
        <div class="actions">
            <a href="{$registerUrl}" class="btn btn-secondary">← Go back</a>
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
            <button type="button" class="btn btn-secondary" onclick="history.back()">← Go back</button>
        </div>
        HTML;
    }
}
