<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;
use Espo\ORM\Entity;

/**
 * Entry point: GET ?entryPoint=contactPortalEdit&token=XXXX
 *
 * Validates the magic-link token and renders a pre-filled edit form.
 */
class ContactPortalEdit implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly HtmlRenderer $htmlRenderer,
        private readonly ContactFieldProvider $fieldProvider,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $token = trim((string) ($request->getQueryParam('token') ?? ''));

        if ($token === '') {
            $response->writeBody(
                $this->htmlRenderer->render(
                    'Invalid link',
                    $this->renderError(),
                ),
            );
            return;
        }

        $contact = $this->findContactByToken($token);

        if (!$contact) {
            $response->writeBody(
                $this->htmlRenderer->render(
                    'Link expired',
                    $this->renderError(),
                ),
            );
            return;
        }

        $response->writeBody(
            $this->htmlRenderer->render(
                'Edit your details',
                $this->renderForm($contact, $token),
            ),
        );
    }

    // -------------------------------------------------------------------------

    /**
     * @return \Espo\ORM\Entity|null
     */
    private function findContactByToken(string $token): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where([
                'portalToken' => $token,
                'portalTokenExpiry>' => date('Y-m-d H:i:s'),
            ])
            ->findOne();
    }

    // -------------------------------------------------------------------------

    private function renderForm(Entity $contact, string $token): string
    {
        $saveUrl = '/api/v1/ContactPortal/save?token=' . rawurlencode($token);
        $saveUrl = HtmlRenderer::e($saveUrl);

        $fields = $this->fieldProvider->getFields();

        // Pre-fetch existing attachments for all file-type fields.
        $existingFiles = [];
        foreach ($fields as $field) {
            if ($field['inputType'] !== 'file') {
                continue;
            }
            $attachments = $this->entityManager
                ->getRDBRepository('Attachment')
                ->where([
                    'parentType' => 'Contact',
                    'parentId' => $contact->getId(),
                    'field' => $field['name'],
                    'role' => 'Attachment',
                ])
                ->find();
            $files = [];
            foreach ($attachments as $att) {
                $files[] = [
                    'name' => (string) ($att->get('name') ?? 'file'),
                    'size' => (int) $att->get('size'),
                ];
            }
            $existingFiles[$field['name']] = $files;
        }

        $fieldsHtml = '';
        foreach ($fields as $field) {
            $fieldsHtml .= $this->htmlRenderer->renderFormField(
                $field,
                $contact,
                $existingFiles[$field['name']] ?? [],
                $token,
            );
        }

        $successHtml =
            '<h1>Details updated</h1>' .
            '<div class="alert alert-success" style="margin-top:20px;">Your details have been updated successfully.</div>' .
            '<p style="margin-top:16px;">Thank you! Your changes are now saved.</p>' .
            '<div class="actions" style="margin-top:20px;"><a href="/?entryPoint=contactPortalRequest" class="link">&larr; Request another access link</a></div>';

        $script = $this->htmlRenderer->formScript($successHtml, 'Saving\u2026');

        return <<<HTML
        <h1>Your member details</h1>

        <div class="description">
            <p>We use this information to maintain our member directory and to connect you with
            relevant freelancers, organisations, and opportunities within the Sepheo network.
            Your data is held securely and is only shared with other Sepheo members and
            partner organisations in line with our co-operative values.</p>
            <p>Update any fields below and click <strong>Save changes</strong>. Your magic link
            can be used once — request a fresh one at any time to return to this form.</p>
        </div>

        <form method="POST" action="{$saveUrl}" enctype="multipart/form-data" novalidate>

            {$fieldsHtml}

            <div class="actions">
                <button type="submit" class="btn">Save changes</button>
            </div>
        </form>

        {$script}
        HTML;
    }

    private function renderError(): string
    {
        $requestUrl = HtmlRenderer::e('/?entryPoint=contactPortalRequest');

        return <<<HTML
        <div class="alert alert-error">
            This link is invalid or has expired.
        </div>
        <p>Magic links can only be used once and expire after 24 hours.</p>
        <div class="actions">
            <a href="{$requestUrl}" class="btn">Request a new link</a>
        </div>
        HTML;
    }
}
