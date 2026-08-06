<?php
declare(strict_types=1);

namespace tests\unit\Espo\Modules\ContactPortal\Util;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config\SystemConfig;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Log;
use Espo\Core\Webhook\Manager as WebhookManager;
use Espo\Entities\WebhookEventQueueItem;
use Espo\Modules\ContactPortal\Util\WebhookDispatcher;
use Espo\Modules\Crm\Entities\Contact;
use tests\integration\Core\BaseTestCase;
use Espo\Core\Utils\SystemUser;

class WebhookDispatcherTest extends BaseTestCase
{
    private $webhookManager;
    private $webhookDispatcher;

    protected function afterStartApplication(): void
    {
        $container = $this->getContainer();
        $factory = $this->getInjectableFactory();

        $this->webhookManager = $factory->create(WebhookManager::class);

        $this->webhookDispatcher = new WebhookDispatcher(
            $this->createMock(DataCache::class),
            $this->createMock(SystemConfig::class),
            $this->getEntityManager(),
            $this->webhookManager,
            $factory->create(SystemUser::class),
            $container->getByClass(Log::class),
        );
    }

    public function testRegistration(): void
    {
        $entityManager = $this->getEntityManager();

        // We need to tell the whm that there is an event listener for this event.
        $this->webhookManager->addEvent('Contact.create');

        $contact = $entityManager->createEntity(Contact::ENTITY_TYPE, [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'emailAddress' => 'john.doe@example.test',
        ]);

        $this->webhookDispatcher->processRegistration($contact);

        // assert
        // expected webhook event queued
        $list = $entityManager
            ->getRDBRepositoryByClass(WebhookEventQueueItem::class)
            //            ->select([])
            ->where(['targetId' => $contact->getId()])
            ->find();

        $found = false;
        foreach ($list as $item) {
            // DEBUG
            // var_dump($item->getValueMap());
            // echo "ID:  {$item->getTargetId()} / {$item->getId()} - {$item->getData()->source}\n";
            if (
                property_exists($item->getData(), 'source') &&
                $item->getData()->source == 'ContactPortal'
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }
}
