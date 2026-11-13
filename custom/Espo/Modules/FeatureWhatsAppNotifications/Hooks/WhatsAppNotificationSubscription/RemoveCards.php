<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Hooks\WhatsAppNotificationSubscription;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;

/**
 * A removed subscription takes its deck cards along: they hold the customer's name, the conversation's context and
 * the AI draft. Tenant offboarding removes subscriptions through their `tenant` link, so this runs there too. The
 * delivery log (WhatsAppNotification) stays, like the rest of the billing history.
 */
class RemoveCards implements AfterRemove
{
    public function __construct(private EntityManager $em) {}

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('WhatsAppDeckCard')
                ->set(['deleted' => true])
                ->where(['subscriptionId' => $entity->getId(), 'deleted' => false])
                ->build()
        );
    }
}
