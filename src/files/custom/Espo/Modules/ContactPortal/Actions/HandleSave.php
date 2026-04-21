<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Actions;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\ORM\EntityManager;
use Espo\Entities\Attachment;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;
use Espo\ORM\Entity;

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
        // Token is passed as a query parameter (not POST body) because
        // multipart/form-data empties php://input before EspoCRM parses the body.
        $token = trim((string) ($request->getQueryParam('token') ?? ''));

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

        // Apply field values and handle file uploads in a single pass.
        foreach ($this->fieldProvider->getFields() as $field) {
            $name = $field['name'];

            if ($field['inputType'] === 'file') {
                // If the "Remove this file" checkbox was ticked and no new file
                // was uploaded, delete the existing attachment(s) and move on.
                $deleteRequested = !empty($_POST['delete_' . $name]);
                $newFileProvided = isset($_FILES[$name]['tmp_name']) && $_FILES[$name]['tmp_name'] !== '';

                if ($deleteRequested && !$newFileProvided) {
                    $this->deleteAttachmentsForField($contact, $name);
                    continue;
                }

                $fileErr = $this->handleFileUpload($contact, $field);

                if ($fileErr !== null) {
                    return $this->htmlResponse(
                        $this->htmlRenderer->render('Upload error', $this->renderError($fileErr))
                    );
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
                $value = ($value !== '') ? [(string) $value] : [];
            }

            $contact->set($name, $value);
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
     * Handles a single file-upload field.
     * Reads from $_FILES[$name], validates, creates an Attachment entity, and
     * links it to the contact as a parent.
     *
     * Returns null on success (or no file submitted), or an error string.
     *
     * @param \Espo\ORM\Entity $contact
     * @param array<string, mixed> $field
     */
    private function handleFileUpload(Entity $contact, array $field): ?string
    {
        $name = $field['name'];

        $fileInfo = $_FILES[$name] ?? null;

        // No file chosen — skip silently (field is optional unless required).
        if ($fileInfo === null || !isset($fileInfo['tmp_name']) || $fileInfo['tmp_name'] === '') {
            return null;
        }

        if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
            return "Upload error for {$field['label']} (code {$fileInfo['error']}).";
        }

        // Verify this is a legitimate HTTP upload, not an injected path.
        if (!is_uploaded_file($fileInfo['tmp_name'])) {
            return "Invalid file upload for {$field['label']}.";
        }

        $originalName = basename((string) ($fileInfo['name'] ?? 'upload'));
        $tmpPath      = (string) ($fileInfo['tmp_name'] ?? '');
        $sizeMb       = $fileInfo['size'] / (1024 * 1024);

        // Validate file size.
        if ($field['maxFileSize'] !== null && $sizeMb > (float) $field['maxFileSize']) {
            return "{$field['label']} exceeds the maximum allowed size of {$field['maxFileSize']} MB.";
        }

        // Validate extension.
        $accept = (array) ($field['accept'] ?? []);
        if (!empty($accept)) {
            $ext    = '.' . strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $lowerAccept = array_map('strtolower', $accept);
            if (!in_array($ext, $lowerAccept, true)) {
                $allowed = implode(', ', $accept);
                return "{$field['label']}: file type not allowed. Accepted: {$allowed}.";
            }
        }

        // Detect MIME type from actual file content (not the browser-supplied type).
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath) ?: 'application/octet-stream';

        $contents = file_get_contents($tmpPath);

        if ($contents === false) {
            return "Could not read uploaded file for {$field['label']}.";
        }

        // Delete any pre-existing attachments for this field before saving the
        // new one, so we don't accumulate orphans (e.g. for maxCount:1 fields).
        $existing = $this->entityManager
            ->getRDBRepository(Attachment::ENTITY_TYPE)
            ->where([
                'parentType' => 'Contact',
                'parentId'   => $contact->getId(),
                'field'      => $name,
                'role'       => Attachment::ROLE_ATTACHMENT,
            ])
            ->find();

        foreach ($existing as $old) {
            $this->entityManager->removeEntity($old);
        }

        /** @var Attachment $attachment */
        $attachment = $this->entityManager->getNewEntity(Attachment::ENTITY_TYPE);
        $attachment
            ->setName($originalName)
            ->setType($mimeType)
            ->setSize((int) $fileInfo['size'])
            ->setRole(Attachment::ROLE_ATTACHMENT)
            ->setTargetField($name)
            ->setContents($contents);

        // setParent(Entity) uses the relation layer which does not write
        // parentType/parentId columns. Set them as plain attributes instead.
        $attachment->set('parentType', 'Contact');
        $attachment->set('parentId', $contact->getId());

        $this->entityManager->saveEntity($attachment);

        return null;
    }

    /**
     * Removes all attachments for a given field on the contact (used for explicit delete).
     */
    private function deleteAttachmentsForField(Entity $contact, string $fieldName): void
    {
        $existing = $this->entityManager
            ->getRDBRepository(Attachment::ENTITY_TYPE)
            ->where([
                'parentType' => 'Contact',
                'parentId'   => $contact->getId(),
                'field'      => $fieldName,
                'role'       => Attachment::ROLE_ATTACHMENT,
            ])
            ->find();

        foreach ($existing as $old) {
            $this->entityManager->removeEntity($old);
        }
    }

    private function findContactByToken(string $token): ?Entity
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
        // For multipart/form-data, EspoCRM's getParsedBody() returns an empty
        // object because php://input is consumed by PHP before it can be read.
        // $_POST is always populated by PHP itself for multipart submissions,
        // so we read directly from there. $_FILES is used separately for uploads.
        $post   = $_POST;
        $fields = $this->fieldProvider->getFields();
        $out    = [];

        foreach ($fields as $field) {
            $name      = $field['name'];
            $inputType = $field['inputType'];

            if ($inputType === 'checkbox') {
                // Unchecked checkboxes are not submitted — default to false.
                $out[$name] = !empty($post[$name]);
            } elseif ($inputType === 'multiselect') {
                // Checkboxes with name="field[]" — PHP puts these in $_POST as an array.
                $raw = $post[$name] ?? null;
                if (is_array($raw)) {
                    $out[$name] = array_map(fn($v) => strip_tags((string) $v), $raw);
                } elseif ($raw !== null && $raw !== '') {
                    $out[$name] = [strip_tags((string) $raw)];
                } else {
                    $out[$name] = [];
                }
            } elseif ($inputType === 'file') {
                // Handled separately via $_FILES in process() — skip here.
                continue;
            } else {
                $out[$name] = trim(strip_tags((string) ($post[$name] ?? '')));
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
            $name      = $field['name'];
            $label     = $field['label'];
            $inputType = $field['inputType'];
            $value     = $input[$name] ?? ($inputType === 'multiselect' ? [] : '');

            // bool checkboxes are always valid
            if ($inputType === 'checkbox') {
                continue;
            }

            // file uploads — validated separately at processing time
            if ($inputType === 'file') {
                continue;
            }

            // multiselect (multiEnum)
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

            // scalar fields
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
