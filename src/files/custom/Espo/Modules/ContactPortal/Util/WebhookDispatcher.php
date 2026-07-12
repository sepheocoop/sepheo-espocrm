<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Util;

use Espo\Core\Name\Field;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Config\SystemConfig;
use Espo\Core\Webhook\Events\UpdateGlobal;
use Espo\Core\Webhook\Manager as WebhookManager;
use Espo\Entities\WebhookEventQueueItem;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Core\Utils\SystemUser;

/** An emulation of WebhookManager which adds our own webhook queueing functionality */
class WebhookDispatcher
{
    protected const string ENTITY_TYPE = 'Contact';
    //    protected const string CACHE_KEY = 'webhooks';

    /* Fields not to include in update data - copied from Webhook/Manager */
    /** @var string[] */
    protected $skipAttributeList = [
        Field::IS_FOLLOWED,
        Field::IS_STARRED,
        Field::FOLLOWERS . 'Ids',
        Field::FOLLOWERS . 'Names',
        Field::MODIFIED_AT,
        Field::MODIFIED_BY,
        Field::STREAM_UPDATED_AT,
        Field::VERSION_NUMBER,
    ];

    /** @var ?array<string, bool> */
    protected $data = null;

    /** @var */
    protected $eventExistsMethod;

    public function __construct(
        protected readonly DataCache $dataCache,
        protected SystemConfig $systemConfig,
        protected readonly EntityManager $entityManager,
        protected readonly WebhookManager $webhookManager,
        protected readonly SystemUser $systemUser,
        protected readonly Log $log,
    ) {
        // Circumvent the private accessibility of Webhook\Manager::eventExists
        $this->eventExistsMethod = new \ReflectionMethod(
            WebhookManager::class,
            'eventExists',
        );
    }

    protected function eventExists(string $event): bool
    {
        return $this->eventExistsMethod->invokeArgs($this->webhookManager, [
            $event,
        ]);
    }

    protected function buildUpdateData(Contact $contact): object
    {
        // Emulate Webhook/Managre's payload construction
        // (Except for the contact ID, which we needn't include here)

        $updateData = (object) [];

        foreach ($contact->getAttributeList() as $attribute) {
            if (in_array($attribute, $this->skipAttributeList)) {
                continue;
            }

            if ($contact->isAttributeChanged($attribute)) {
                $updateData->$attribute = $contact->get($attribute);
            }
        }

        return $updateData;
    }

    /** Shared processing functionality
     *
     * This is the method we want to implement, all the others are here to
     * support it by supplying hidden WebhookManager functionality
     */
    protected function process(
        string $event,
        Contact $contact,
        object $data,
    ): void {
        // Don't even queue the event if there is nothing listening for it.
        if (!$this->eventExists($event)) {
            $this->log->debug(
                "Nothing listening for '$event' events, ignoring",
            );
            return;
        }

        $item = $this->entityManager
            ->getRDBRepositoryByClass(WebhookEventQueueItem::class)
            ->getNew();

        $item
            ->setEvent($event)
            ->setTarget($contact)
            ->setUserId($this->systemUser->getId())
            ->setData($data);

        $this->entityManager->saveEntity($item);

        $this->debugLog($event, $contact);
    }

    /** Process registrations (from HandleRegister) */
    public function processRegistration(Contact $contact): void
    {
        $data = [
            'source' => 'ContactPortal',
            'contact' => $contact->getValueMap(),
        ];
        $this->process(self::ENTITY_TYPE . '.create', $contact, (object) $data);
    }

    /** Process requests for magic links (from HandleRequest) */
    public function processRequest(Contact $contact): void
    {
        // Nothing done at the moment
    }

    /** Process updates (from HandleSave) */
    public function processUpdate(Contact $contact): void
    {
        $data = [
            'source' => 'ContactPortal',
            'updated' => $this->buildUpdateData($contact),
            'contact' => $contact->getValueMap(),
        ];
        $this->process(self::ENTITY_TYPE . '.update', $contact, (object) $data);
    }

    protected function debugLog(string $event, Contact $contact): void
    {
        $this->log->debug(
            "Processing $event for contact {$contact->get(
                'emailAddress',
            )} ({$contact->getId()})",
        );
    }
}
