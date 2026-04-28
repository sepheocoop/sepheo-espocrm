<?php
namespace Espo\Modules\ContactPortal\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Mail\EmailSender;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;

/**
 * Entry point: GET/POST ?entryPoint=contactPortalRequest
 *
 * GET  – renders an email input form.
 * POST – validates the email, generates a magic-link token, e-mails it, and
 *        shows a generic confirmation (anti-enumeration).
 */
class ContactPortalRequest implements EntryPoint
{
    use NoAuth;

    /** Cooldown in seconds before a new token can be issued for the same contact. */
    private const COOLDOWN_SECONDS = 300; // 5 minutes

    /** Token validity in seconds. */
    private const TOKEN_TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly EmailSender $emailSender,
        private readonly HtmlRenderer $htmlRenderer,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $method = strtoupper($request->getMethod());

        if ($method === 'POST') {
            $this->handlePost($request, $response);
        } else {
            $this->handleGet($response);
        }
    }

    // -------------------------------------------------------------------------

    private function handleGet(Response $response): void
    {
        $html = $this->htmlRenderer->render('Request Access', $this->renderForm());
        $response->writeBody($html);
    }

    private function handlePost(Request $request, Response $response): void
    {
        $rawEmail = (string) ($request->getParsedBody()['email'] ?? '');
        $email    = strtolower(trim($rawEmail));

        // Always show the same confirmation to prevent email enumeration.
        $confirmation = $this->htmlRenderer->render(
            'Check your email',
            $this->renderConfirmation()
        );

        if (!$this->isValidEmail($email)) {
            $response->writeBody($confirmation);
            return;
        }

        $contact = $this->findContactByEmail($email);

        if (!$contact) {
            $response->writeBody($confirmation);
            return;
        }

        // Rate-limit: if a token was issued recently, do nothing extra.
        if (!$this->cooldownElapsed($contact)) {
            $response->writeBody($confirmation);
            return;
        }

        $token  = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);

        $contact->set('portalToken', $token);
        $contact->set('portalTokenExpiry', $expiry);
        $this->entityManager->saveEntity($contact);

        $this->sendMagicLinkEmail($contact, $token);

        $response->writeBody($confirmation);
    }

    // -------------------------------------------------------------------------

    /**
     * @return \Espo\ORM\Entity|null
     */
    private function findContactByEmail(string $email): mixed
    {
        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where([
                'emailAddress' => $email,
            ])
            ->findOne();
    }

    private function cooldownElapsed(mixed $contact): bool
    {
        $expiry = $contact->get('portalTokenExpiry');

        if (!$expiry) {
            return true;
        }

        $expiryTs    = strtotime((string) $expiry);
        $cooldownEnd = $expiryTs - self::TOKEN_TTL_SECONDS + self::COOLDOWN_SECONDS;

        return time() > $cooldownEnd;
    }

    private function sendMagicLinkEmail(mixed $contact, string $token): void
    {
        $firstName  = (string) $contact->get('firstName');
        $toEmail    = (string) $contact->get('emailAddress');
        $editUrl    = $this->buildEditUrl($token);
        $salutation = $firstName ? "Hi {$firstName}," : 'Hello,';

        $body = <<<HTML
        <p>{$salutation}</p>
        <p>Click the link below to view and update your contact details.
           The link is valid for 24 hours and can only be used once.</p>
        <p><a href="{$editUrl}">{$editUrl}</a></p>
        <p>If you did not request this link, you can safely ignore this email.</p>
        HTML;

        $email = $this->entityManager->getNewEntity('Email');
        $email->set([
            'subject'    => 'Your contact portal access link',
            'body'       => $body,
            'isHtml'     => true,
            'to'         => $toEmail,
        ]);

        try {
            $this->emailSender->send($email);
        } catch (\Throwable) {
            // Silently swallow: the confirmation page is shown regardless.
        }
    }

    private function buildEditUrl(string $token): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return "{$scheme}://{$host}/?entryPoint=contactPortalEdit&token=" . urlencode($token);
    }

    private function isValidEmail(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // -------------------------------------------------------------------------

    private function renderForm(?string $error = null): string
    {
        $e     = HtmlRenderer::e(...);
        $alert = $error
            ? '<div class="alert alert-error">' . $e($error) . '</div>'
            : '';

        return <<<HTML
        <h1>Access your details</h1>
        <p class="subtitle">Enter the email address we have on file and we'll send you a secure link.</p>
        {$alert}
        <form method="POST" action="/api/v1/ContactPortal/request">
            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" maxlength="254" required autofocus>
            </div>
            <div class="actions">
                <button type="submit" class="btn">Send me a link</button>
            </div>
        </form>
        HTML;
    }

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
