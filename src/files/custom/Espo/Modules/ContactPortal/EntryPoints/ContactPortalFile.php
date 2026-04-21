<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\ORM\EntityManager;
use Espo\Entities\Attachment;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;

/**
 * Entry point: GET ?entryPoint=contactPortalFile&token=XXXX&field=FIELDNAME
 *
 * Validates the magic-link token and streams the attachment for the given
 * field back to the browser so the user can preview / download it.
 *
 * The token doubles as the auth mechanism — only the person who holds the
 * magic link can download attachments belonging to that contact.
 */
class ContactPortalFile implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly FileStorageManager $fileStorageManager,
        private readonly HtmlRenderer $htmlRenderer,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $token     = trim((string) ($request->getQueryParam('token') ?? ''));
        $fieldName = trim((string) ($request->getQueryParam('field') ?? ''));

        if ($token === '' || $fieldName === '') {
            $response->setStatus(400);
            $response->writeBody('Bad request.');
            return;
        }

        // Validate the magic-link token.
        $contact = $this->entityManager
            ->getRDBRepository('Contact')
            ->where([
                'portalToken'        => $token,
                'portalTokenExpiry>' => date('Y-m-d H:i:s'),
            ])
            ->findOne();

        if (!$contact) {
            $response->setStatus(403);
            $response->writeBody('Link invalid or expired.');
            return;
        }

        // Find the attachment that belongs to this contact + field.
        /** @var Attachment|null $attachment */
        $attachment = $this->entityManager
            ->getRDBRepository(Attachment::ENTITY_TYPE)
            ->where([
                'parentType' => 'Contact',
                'parentId'   => $contact->getId(),
                'field'      => $fieldName,
                'role'       => Attachment::ROLE_ATTACHMENT,
            ])
            ->findOne();

        if (!$attachment) {
            $response->setStatus(404);
            $response->writeBody('File not found.');
            return;
        }

        if (!$this->fileStorageManager->exists($attachment)) {
            $response->setStatus(404);
            $response->writeBody('File not found in storage.');
            return;
        }

        $mimeType = $attachment->getType() ?: 'application/octet-stream';
        $fileName = $attachment->getName() ?: 'download';

        // Decide whether to inline (browser preview) or force download.
        // SVG is intentionally excluded from this list — SVG can contain
        // embedded <script> tags and would execute JS in our domain if served inline.
        $previewable = in_array($mimeType, [
            'application/pdf',
        ], true);

        $disposition = $previewable
            ? 'inline'
            : 'attachment';

        $safeFileName = rawurlencode($fileName);

        $contents = $this->fileStorageManager->getContents($attachment);

        $response->setHeader('Content-Type', $mimeType);
        $response->setHeader(
            'Content-Disposition',
            "{$disposition}; filename=\"{$fileName}\"; filename*=UTF-8''{$safeFileName}"
        );
        $response->setHeader('Content-Length', (string) strlen($contents));
        // Prevent MIME-type sniffing attacks.
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        // Restrictive CSP — inline files should not load external resources or run scripts.
        $response->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        // Do not cache — the token may be invalidated at any time.
        $response->setHeader('Cache-Control', 'no-store');
        $response->writeBody($contents);
    }
}
