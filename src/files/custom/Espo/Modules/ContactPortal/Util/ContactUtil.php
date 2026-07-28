<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Util;

use Espo\Modules\Crm\Entities\Contact;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;

class ContactUtil
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly Log $log,
    ) {}

    /** Make a new contact instance
     */
    public function newContact(): Contact
    {
        return $this->entityManager->getNewEntity('Contact');
    }

    /** Find a contact from the email field
     */
    public function findContactByEmail(string $email): ?Contact
    {
        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['emailAddress' => $email])
            ->findOne();
    }

    /** Find a contact from the token field
     */
    public function findContactByToken(string $token): ?Contact
    {
        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where([
                'portalToken' => $token,
                'portalTokenExpiry>' => date('Y-m-d H:i:s'),
            ])
            ->findOne();
    }

    /** Test an email is valid */
    public function isValidEmail(string $email): bool
    {
        return strlen($email) <= 254 &&
            filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
