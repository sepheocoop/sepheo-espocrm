<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Actions;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\ContactPortal\Util\ContactUtil;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;
use Espo\Modules\ContactPortal\Util\MagicLinkSender;
use Espo\Modules\ContactPortal\Util\WebhookDispatcher;
use Espo\ORM\Entity;

/**
 * POST /api/v1/ContactPortal/request
 *
 * Processes the "enter your email" form: generates a magic-link token and
 * sends it by email. Always shows a generic confirmation page.
 */
class HandleRequest implements Action
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly MagicLinkSender $magicLinkSender,
        private readonly HtmlRenderer $htmlRenderer,
        private readonly ContactUtil $contactUtil,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly Log $log,
    ) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();
        $rawEmail = (string) ($body->email ?? '');
        $email = strtolower(trim($rawEmail));

        $html = $this->handleRequest($email);

        return ResponseComposer::empty()
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->writeBody($html);
    }

    private function handleRequest(string $email): string
    {
        // Always return a response that doesn't confirm whether the email is
        // registered — except for the cooldown case, where we explicitly tell
        // the user a link was recently sent. For a private member portal this
        // trade-off is acceptable: an attacker who already knows an email
        // address can learn it is registered by triggering the cooldown message,
        // but the 5-minute window limits any practical enumeration value.
        if (!$this->contactUtil->isValidEmail($email)) {
            $this->log->debug("Invalid email '$email', don't send a link.");
            return $this->htmlRenderer->render(
                'Check your email',
                $this->renderConfirmation(),
            );
        }

        $contact = $this->contactUtil->findContactByEmail($email);

        if (!$contact) {
            $this->log->debug(
                "No contact found for $email, don't send a link.",
            );
            return $this->htmlRenderer->render(
                'Check your email',
                $this->renderConfirmation(),
            );
        }

        $secondsLeft = $this->magicLinkSender->send($contact);

        if ($secondsLeft > 0) {
            // Valid link was issued recently — don't issue another, tell them to
            // check their inbox instead.
            $this->log->debug(
                "Valid link issued recently, $secondsLeft cooldown seconds left," .
                    ' no link sent',
            );
            return $this->htmlRenderer->render(
                'Link already sent',
                $this->renderCooldownMessage($secondsLeft),
            );
        }

        $this->webhookDispatcher->processRequest($contact);

        $this->log->debug('No cooldown, link sent');
        return $this->htmlRenderer->render(
            'Check your email',
            $this->renderConfirmation(),
        );
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

    private function renderCooldownMessage(int $secondsRemaining): string
    {
        $minutes = (int) ceil($secondsRemaining / 60);
        $timeMsg = HtmlRenderer::e(
            $minutes <= 1 ? 'about 1 minute' : "about {$minutes} minutes",
        );
        $requestUrl = HtmlRenderer::e('/?entryPoint=contactPortalRequest');

        return <<<HTML
        <div class="alert alert-info">
            A link has already been sent to your email address recently.
        </div>
        <p>Please check your inbox (and spam folder) — the link is valid for 24 hours.</p>
        <p>For security, a new link can only be issued once every 5 minutes.
           You can try again in <strong>{$timeMsg}</strong>.</p>
        <div class="actions" style="margin-top:20px;">
            <a href="{$requestUrl}" class="link">← Try again</a>
        </div>
        HTML;
    }
}
