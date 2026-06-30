<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Actions;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\ContactPortal\Util\AttachmentSaver;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;
use Espo\Modules\ContactPortal\Util\MagicLinkSender;
use Espo\ORM\Entity;

/**
 * POST /api/v1/ContactPortal/register
 *
 * Creates a new Contact from a public registration form and returns a
 * simple "Thanks, we'll be in touch" confirmation.
 *
 * If the submitted email address already exists in the CRM, the request
 * still responds the same way — we do not reveal whether the address is
 * known — but a magic link is emailed to the existing contact so they can
 * access their details instead of being silently dropped.
 */
class HandleRegister implements Action
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly ContactFieldProvider $fieldProvider,
        private readonly AttachmentSaver $attachmentSaver,
        private readonly MagicLinkSender $magicLinkSender,
        private readonly Log $log,
    ) {}

    public function process(Request $request): Response
    {
        $fields = $this->fieldProvider->getRegistrationFields();

        if ($errors = $this->fieldProvider->truncationErrors($fields)) {
            $this->log->warning(
                'Trucation errors for the following registration fields: ' .
                    json_encode(['fields' => $fields, 'errors' => $errors]),
            );
            return $this->jsonResponse(['fieldErrors' => $errors]);
        }

        $input = $this->fieldProvider->sanitise($fields);
        $errors = $this->fieldProvider->validate($input, $fields);

        if ($errors) {
            $this->log->warning(
                'Validation errors for the following registration fields: ' .
                    json_encode(['fields' => $fields, 'errors' => $errors]),
            );
            return $this->jsonResponse(['fieldErrors' => $errors]);
        }

        $email = (string) ($input['emailAddress'] ?? '');
        if ($email !== '') {
            // Check if we know this email
            $existing = $this->findContactByEmail($email);
            if ($existing !== null) {
                // Don't reveal that the email is registered — return the same
                // response as a successful registration. Send a "you're already
                // a member" magic-link email to the address so the real owner
                // can access the portal. Cooldown is respected internally.
                $this->magicLinkSender->send($existing, true);
                return $this->jsonResponse(['ok' => true]);
            }
        }

        /** @var Entity $contact */
        $contact = $this->entityManager->getNewEntity('Contact');

        foreach ($fields as $field) {
            // Files are handled later; readonly fields cannot be handled
            if ($field['inputType'] === 'file' || $field['readOnly']) {
                continue;
            }

            $name = $field['name'];
            $value = $input[$name]; // a boolean, string, or array of strings expected

            // Skip empty values
            if (!$value) {
                continue;
            }

            // urlMultiple is stored as a JSON array; we capture the first URL only.
            // FIXME is this use of the first URL only univerally correct for our URL fields?
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
                $this->log->warning("Error saving file attachment: $fileError");
                return $this->jsonResponse([
                    'fieldErrors' => [$field['name'] => $fileErr],
                ]);
            }
        }

        return $this->jsonResponse(['ok' => true]);
    }

    // -------------------------------------------------------------------------

    private function findContactByEmail(string $email): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['emailAddress' => $email])
            ->findOne();
    }

    private function jsonResponse(mixed $data): Response
    {
        return ResponseComposer::empty()
            ->setHeader('Content-Type', 'application/json')
            ->writeBody((string) json_encode($data));
    }
}
