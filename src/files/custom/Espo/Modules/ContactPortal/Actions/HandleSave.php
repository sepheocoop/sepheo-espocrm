<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Actions;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Entities\Attachment;
use Espo\Modules\ContactPortal\Util\AttachmentSaver;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;
use Espo\Modules\ContactPortal\Util\ContactUtil;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;
use Espo\ORM\Entity;

/**
 * POST /api/v1/ContactPortal/save
 *
 * Updates an existing Contact from the contactPortalRequest form.
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
        private readonly ContactUtil $contactUtil,
        private readonly AttachmentSaver $attachmentSaver,
        private readonly Log $log,
    ) {}

    public function process(Request $request): Response
    {
        // Token is passed as a query parameter (not POST body) because
        // multipart/form-data empties php://input before EspoCRM parses the body.
        $token = trim((string) ($request->getQueryParam('token') ?? ''));

        if ($token === '') {
            $this->log->debug('Token is empty, rejecting it');
            return $this->htmlResponse(
                $this->htmlRenderer->render(
                    'Invalid request',
                    $this->renderError('No token provided.'),
                ),
            );
        }

        $contact = $this->contactUtil->findContactByToken($token);

        if (!$contact) {
            $this->log->debug("Token $token has expired, rejecting it");
            return $this->htmlResponse(
                $this->htmlRenderer->render(
                    'Link expired',
                    $this->renderError(),
                ),
            );
        }

        $email = $contact->get('emailAddress');
        $fields = $this->fieldProvider->getFields();

        if ($errors = $this->fieldProvider->truncationErrors($fields)) {
            $this->log->warning(
                'Form update for $email has field truncation errors: ' .
                    json_encode(['errors' => $errors]),
            );
            return $this->jsonResponse(['fieldErrors' => $errors]);
        }

        $input = $this->fieldProvider->sanitise($fields);
        $errors = $this->fieldProvider->validate($input, $fields);

        if ($errors) {
            $this->log->warning(
                'Form update for $email is invalid: ' .
                    json_encode(['errors' => $errors]),
            );
            return $this->jsonResponse(['fieldErrors' => $errors]);
        }

        // Apply field values and handle file uploads in a single pass.
        foreach ($fields as $field) {
            $name = $field['name'];

            if ($field['readOnly']) {
                continue; // never write a POST-supplied value to a read-only field
            }

            if ($field['inputType'] === 'file') {
                // If the "Remove this file" checkbox was ticked and no new file
                // was uploaded, delete the existing attachment(s) and move on.
                $deleteRequested = !empty($_POST['delete_' . $name]);
                $newFileProvided =
                    isset($_FILES[$name]['tmp_name']) &&
                    $_FILES[$name]['tmp_name'] !== '';

                if ($deleteRequested && !$newFileProvided) {
                    $this->deleteAttachmentsForField($contact, $name);
                    continue;
                }

                $fileErr = $this->attachmentSaver->save($contact, $field, true);

                if ($fileErr !== null) {
                    $this->log->warning(
                        'Form update for $email file field $field cannot be saved: ' .
                            json_encode(['errors' => $errors]),
                    );
                    return $this->jsonResponse([
                        'fieldErrors' => [$name => $fileErr],
                    ]);
                }

                continue;
            }

            if (!array_key_exists($name, $input)) {
                continue;
            }

            $value = $input[$name];

            // urlMultiple is stored as a JSON array in EspoCRM.
            // We capture only the first URL from the portal form.
            if ($field['originalType'] === 'urlMultiple') {
                $value = $value !== '' ? [(string) $value] : [];
            }

            $contact->set($name, $value);
        }

        // Invalidate — one-time use only.
        $contact->set('portalToken', null);
        $contact->set('portalTokenExpiry', null);

        $this->entityManager->saveEntity($contact);
        $this->log->debug("Update complete for $email, invaldating token");

        return $this->jsonResponse(['ok' => true]);
    }

    // -------------------------------------------------------------------------

    /**
     * Removes all attachments for a given field on the contact (used for explicit delete).
     */
    private function deleteAttachmentsForField(
        Entity $contact,
        string $fieldName,
    ): void {
        $existing = $this->entityManager
            ->getRDBRepository(Attachment::ENTITY_TYPE)
            ->where([
                'parentType' => 'Contact',
                'parentId' => $contact->getId(),
                'field' => $fieldName,
                'role' => Attachment::ROLE_ATTACHMENT,
            ])
            ->find();

        foreach ($existing as $old) {
            $this->entityManager->removeEntity($old);
        }
    }

    private function htmlResponse(string $html): Response
    {
        return ResponseComposer::empty()
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->writeBody($html);
    }

    private function jsonResponse(mixed $data): Response
    {
        return ResponseComposer::empty()
            ->setHeader('Content-Type', 'application/json')
            ->writeBody((string) json_encode($data));
    }

    // -------------------------------------------------------------------------

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
}
