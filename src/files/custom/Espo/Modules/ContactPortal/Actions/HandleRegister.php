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
        private readonly ContactFieldProvider $fieldProvider,
        private readonly AttachmentSaver $attachmentSaver,
    ) {}

    public function process(Request $request): Response
    {
        $fields = $this->fieldProvider->getRegistrationFields();

        if ($errors = $this->fieldProvider->truncationErrors($fields)) {
            return $this->jsonResponse(['fieldErrors' => $errors]);
        }

        $input = $this->fieldProvider->sanitise($fields);
        $errors = $this->fieldProvider->validate($input, $fields);

        if ($errors) {
            return $this->jsonResponse(['fieldErrors' => $errors]);
        }

        // Don't reveal whether the email is already registered.
        $email = (string) ($input['emailAddress'] ?? '');
        if ($email !== '' && $this->emailExists($email)) {
            return $this->jsonResponse(['ok' => true]);
        }

        /** @var Entity $contact */
        $contact = $this->entityManager->getNewEntity('Contact');

        foreach ($fields as $field) {
            if ($field['inputType'] === 'file' || $field['readOnly']) {
                continue;
            }

            $name = $field['name'];
            $value = $input[$name] ?? null;

            if ($value === null) {
                continue;
            }

            // urlMultiple is stored as a JSON array; we capture the first URL only.
            if ($field['originalType'] === 'urlMultiple') {
                $value = $value !== '' ? [(string) $value] : [];
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
                return $this->jsonResponse([
                    'fieldErrors' => [$field['name'] => $fileErr],
                ]);
            }
        }

        return $this->jsonResponse(['ok' => true]);
    }

    // -------------------------------------------------------------------------

    private function emailExists(string $email): bool
    {
        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['emailAddress' => $email])
            ->findOne() !== null;
    }

    private function jsonResponse(mixed $data): Response
    {
        return ResponseComposer::empty()
            ->setHeader('Content-Type', 'application/json')
            ->writeBody((string) json_encode($data));
    }
}
