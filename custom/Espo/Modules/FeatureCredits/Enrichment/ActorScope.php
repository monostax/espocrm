<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\ApplicationState;
use Espo\Core\ApplicationUser;
use Espo\Core\Binding\BindingContainerBuilder;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\ServiceFactory;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Background bulk work runs as the human who confirmed it: services get that user's User/Acl/record
 * services, and saves record that user as creator/modifier. Never the daemon's system user.
 */
class ActorScope
{
    public function __construct(private EntityManager $em, private AclManager $aclManager,
        private InjectableFactory $factory, private ApplicationUser $applicationUser, private ApplicationState $state) {}

    /**
     * @template T
     * @param callable(callable(class-string): object): T $work receives a factory for actor-bound services
     * @return T
     */
    public function run(string $actorId, callable $work): mixed
    {
        $user = $this->em->getEntityById('User', $actorId);
        if (!$user instanceof User || $user->get('deleted') || !$user->isActive() || $user->isPortal() ||
            $user->isApi() || $user->isSystem()) {
            throw new Forbidden('Enrichment purchaser is unavailable.');
        }
        $acl = $this->aclManager->createUserAcl($user);
        $base = BindingContainerBuilder::create()->bindInstance(User::class, $user)->bindInstance(Acl::class, $acl);
        $records = new ServiceContainer($this->factory->createWithBinding(ServiceFactory::class, $base->build()));
        $binding = $base->bindInstance(ServiceContainer::class, $records)->build();
        $previous = $this->state->hasUser() ? $this->state->getUser() : null;
        $this->applicationUser->setUser($user);
        try {
            return $work(fn (string $class): object => $this->factory->createWithBinding($class, $binding));
        } finally {
            if ($previous) $this->applicationUser->setUser($previous);
            else $this->applicationUser->setupSystemUser();
        }
    }
}
