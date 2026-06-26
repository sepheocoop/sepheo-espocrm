<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Util;

use Espo\Core\Mail\EmailSender;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;

/**
 * Issues magic-link tokens for Contact records and emails them out.
 *
 * Used both when a contact actively requests a link, and when a registration
 * form is submitted with an email that already belongs to an existing
 * contact (in which case a differently-worded email is sent).
 */
class MagicLinkSender
{
    /** Cooldown in seconds before a new token can be issued for the same contact. */
    private const COOLDOWN_SECONDS = 300; // 5 minutes

    /** Token validity in seconds. */
    private const TOKEN_TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly EmailSender $emailSender,
        private readonly Config $config,
        private readonly Log $log,
    ) {}

    /**
     * Issues a magic-link token and sends it by email.
     * Respects the 5-minute cooldown — if a valid token was recently issued
     * no new token is generated and no email is sent.
     *
     * @param bool $alreadyRegistered  True when called from the registration
     *                                 duplicate-email path; sends different copy.
     * @return int  Seconds remaining in cooldown. 0 means the link was sent.
     */
    public function send(Entity $contact, bool $alreadyRegistered = false): int
    {
        $secondsLeft = $this->cooldownSecondsRemaining($contact);

        if ($secondsLeft > 0) {
            return $secondsLeft;
        }

        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);

        $contact->set('portalToken', $token);
        $contact->set('portalTokenExpiry', $expiry);
        $this->entityManager->saveEntity($contact);

        if ($alreadyRegistered) {
            $this->sendAlreadyRegisteredEmail($contact, $token);
        } else {
            $this->sendRequestEmail($contact, $token);
        }

        return 0;
    }

    // -------------------------------------------------------------------------

    private function cooldownSecondsRemaining(Entity $contact): int
    {
        $expiry = $contact->get('portalTokenExpiry');

        if (!$expiry) {
            return 0;
        }

        $expiryTs = strtotime((string) $expiry);

        // Token is already expired — no cooldown applies, issue a new one.
        if ($expiryTs < time()) {
            return 0;
        }

        // Infer when the token was issued: issuedAt = expiryTs - TOKEN_TTL
        // Cooldown window ends at: issuedAt + COOLDOWN_SECONDS
        $cooldownEnd =
            $expiryTs - self::TOKEN_TTL_SECONDS + self::COOLDOWN_SECONDS;

        return max(0, $cooldownEnd - time());
    }

    private function buildEditUrl(string $token): string
    {
        // Use the admin-configured site URL — never trust HTTP Host headers,
        // which can be forged to point the magic link at an attacker's domain.
        $siteUrl = rtrim((string) $this->config->get('siteUrl', ''), '/');

        return $siteUrl .
            '/?entryPoint=contactPortalEdit&token=' .
            urlencode($token);
    }

    private function sendRequestEmail(Entity $contact, string $token): void
    {
        $firstName = (string) $contact->get('firstName');
        $toEmail = (string) $contact->get('emailAddress');
        $editUrl = $this->buildEditUrl($token);
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
            'body' => $body,
            'isHtml' => true,
            'to' => $toEmail,
        ]);

        try {
            $this->emailSender->send($email);
        } catch (\Throwable $e) {
            $this->log->error(
                'ContactPortal: email send failed: ' . $e->getMessage(),
            );
        }
    }

    private function sendAlreadyRegisteredEmail(
        Entity $contact,
        string $token,
    ): void {
        $firstName = (string) $contact->get('firstName');
        $toEmail = (string) $contact->get('emailAddress');
        $editUrl = $this->buildEditUrl($token);
        $salutation = $firstName
            ? 'Hi ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ','
            : 'Hello,';

        $safeUrl = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
        <p>{$salutation}</p>
        <p>Looks like you tried to register with the Sepheo member portal —
           but you already have an account registered with this email address.
           Welcome back!</p>
        <p>If you'd like to review or
           update your details, just use the link below:</p>
        <p><a href="{$safeUrl}">{$safeUrl}</a></p>
        <p>It's valid for 24 hours and can only be used once.</p>
        <p>If this wasn't you, don't worry — nothing has changed on your
           account, and you can safely ignore this email.</p>
        HTML;

        $email = $this->entityManager->getNewEntity('Email');
        $email->set([
            'subject' => 'You already have a Sepheo account',
            'body' => $body,
            'isHtml' => true,
            'to' => $toEmail,
        ]);

        try {
            $this->emailSender->send($email);
        } catch (\Throwable $e) {
            $this->log->error(
                'ContactPortal: already-registered email send failed: ' .
                    $e->getMessage(),
            );
        }
    }
}
