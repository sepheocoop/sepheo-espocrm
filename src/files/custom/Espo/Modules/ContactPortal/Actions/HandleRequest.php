<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Actions;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;
use Espo\ORM\Entity;

/**
 * POST /api/v1/ContactPortal/request
 *
 * Processes the "enter your email" form: generates a magic-link token and
 * sends it by email. Always shows a generic confirmation page.
 */
class HandleRequest implements Action
{
    /** Cooldown in seconds before a new token can be issued for the same contact. */
    private const COOLDOWN_SECONDS = 300; // 5 minutes

    /** Token validity in seconds. */
    private const TOKEN_TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly EmailSender $emailSender,
        private readonly HtmlRenderer $htmlRenderer,
        private readonly Log $log,
        private readonly Config $config,
    ) {}

    public function process(Request $request): Response
    {
        $body     = $request->getParsedBody();
        $rawEmail = (string) ($body->email ?? '');
        $email    = strtolower(trim($rawEmail));

        if ($this->isValidEmail($email)) {
            $contact = $this->findContactByEmail($email);

            if ($contact && $this->cooldownElapsed($contact)) {
                $token  = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);

                $contact->set('portalToken', $token);
                $contact->set('portalTokenExpiry', $expiry);
                $this->entityManager->saveEntity($contact);

                $this->sendMagicLinkEmail($contact, $token);
            }
        }

        // Always show the same page — no email enumeration.
        $html = $this->htmlRenderer->render('Check your email', $this->renderConfirmation());

        return ResponseComposer::empty()
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->writeBody($html);
    }

    // -------------------------------------------------------------------------

    private function findContactByEmail(string $email): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['emailAddress' => $email])
            ->findOne();
    }

    private function cooldownElapsed(Entity $contact): bool
    {
        $expiry = $contact->get('portalTokenExpiry');

        if (!$expiry) {
            return true;
        }

        $expiryTs    = strtotime((string) $expiry);
        $cooldownEnd = $expiryTs - self::TOKEN_TTL_SECONDS + self::COOLDOWN_SECONDS;

        return time() > $cooldownEnd;
    }

    private function sendMagicLinkEmail(Entity $contact, string $token): void
    {
        $firstName  = (string) $contact->get('firstName');
        $toEmail    = (string) $contact->get('emailAddress');
        $editUrl    = $this->buildEditUrl($token);
        $salutation = $firstName
            ? 'Hi ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ','
            : 'Hello,';

        $safeUrl = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
        <p>{$salutation}</p>
        <p>Click the link below to view and update your contact details.
           The link is valid for 24 hours and can only be used once.</p>
        <p><a href="{$safeUrl}">{$safeUrl}</a></p>
        <p>If you did not request this link, you can safely ignore this email.</p>
        HTML;

        $email = $this->entityManager->getNewEntity('Email');
        $email->set([
            'subject' => 'Your contact portal access link',
            'body'    => $body,
            'isHtml'  => true,
            'to'      => $toEmail,
        ]);

        try {
            $this->emailSender->send($email);
        } catch (\Throwable $e) {
            $this->log->error('ContactPortal: email send failed: ' . $e->getMessage());
        }
    }

    private function buildEditUrl(string $token): string
    {
        // Use the admin-configured site URL — never trust HTTP Host headers,
        // which can be forged to point the magic link at an attacker's domain.
        $siteUrl = rtrim((string) $this->config->get('siteUrl', ''), '/');

        return $siteUrl . '/?entryPoint=contactPortalEdit&token=' . urlencode($token);
    }

    private function isValidEmail(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // -------------------------------------------------------------------------

    private function renderConfirmation(): string
    {
        $requestUrl = HtmlRenderer::e('/?entryPoint=contactPortalRequest');

        return <<<HTML
        <div class="alert alert-success">
            If that email address is registered, you'll receive a link shortly.
        </div>
        <p>Please check your inbox (and spam folder). The link expires after 24 hours.</p>
        <div class="actions" style="margin-top:20px;">
            <a href="{$requestUrl}" class="link">← Send another link</a>
        </div>
        HTML;
    }
}
