<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Util;

use Espo\Core\ORM\EntityManager;
use Espo\Entities\Attachment;
use Espo\ORM\Entity;

/**
 * Validates and persists a file-upload field for a Contact entity.
 *
 * Used by both HandleRegister (pruneExisting=false — new contact, no prior files)
 * and HandleSave (pruneExisting=true — replace the existing attachment on edit).
 */
class AttachmentSaver
{
    public function __construct(
        private readonly EntityManager $entityManager,
    ) {}

    /**
     * Processes a single file-upload field from $_FILES[$field['name']].
     *
     * Returns null on success (or no file chosen), or an error string on failure.
     *
     * @param array<string, mixed> $field          Field definition from ContactFieldProvider.
     * @param bool                 $pruneExisting  Delete existing attachments for this field
     *                                             before saving the new one. Set true on edit,
     *                                             false on first registration.
     */
    public function save(Entity $contact, array $field, bool $pruneExisting = false): ?string
    {
        $name     = $field['name'];
        $fileInfo = $_FILES[$name] ?? null;

        // Nothing chosen — skip silently.
        if ($fileInfo === null || !isset($fileInfo['tmp_name']) || $fileInfo['tmp_name'] === '') {
            return null;
        }

        if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
            return "Upload error for {$field['label']} (code {$fileInfo['error']}).";
        }

        // Guard against path injection — only accept legitimate HTTP uploads.
        if (!is_uploaded_file($fileInfo['tmp_name'])) {
            return "Invalid file upload for {$field['label']}.";
        }

        $originalName = basename((string) ($fileInfo['name'] ?? 'upload'));
        $tmpPath      = (string) $fileInfo['tmp_name'];
        $sizeMb       = $fileInfo['size'] / (1024 * 1024);

        if ($field['maxFileSize'] !== null && $sizeMb > (float) $field['maxFileSize']) {
            return "{$field['label']} exceeds the maximum allowed size of {$field['maxFileSize']} MB.";
        }

        // Validate by extension.
        $accept = (array) ($field['accept'] ?? []);
        if (!empty($accept)) {
            $ext         = '.' . strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $lowerAccept = array_map('strtolower', $accept);
            if (!in_array($ext, $lowerAccept, true)) {
                $allowed = implode(', ', $accept);
                return "{$field['label']}: file type not allowed. Accepted: {$allowed}.";
            }
        }

        // Detect MIME from actual file content — not the browser-supplied type.
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath) ?: 'application/octet-stream';

        $contents = file_get_contents($tmpPath);
        if ($contents === false) {
            return "Could not read uploaded file for {$field['label']}.";
        }

        if ($pruneExisting) {
            // Remove any prior attachments for this field to avoid accumulating orphans.
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
        // parentType/parentId columns; set them as plain attributes instead.
        $attachment->set('parentType', 'Contact');
        $attachment->set('parentId', $contact->getId());

        $this->entityManager->saveEntity($attachment);

        return null;
    }
}
