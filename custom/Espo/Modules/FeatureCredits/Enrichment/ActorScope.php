<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\ApplicationUser;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Background bulk work runs as the human who confirmed it, never the daemon's system user.
 *
 * A job's container already holds the system user, and Espo refuses to replace a user once set. So the
 * work gets an application of its own whose user is the purchaser: ACL, record services, hooks and the
 * creator/modifier stamps all see that user, as in the request where they confirmed the run. The job's
 * own container keeps the system user.
 */
class ActorScope
{
    /**
     * @template T
     * @param callable(callable(class-string): object): T $work receives a factory for actor-bound services
     * @return T
     */
    public function run(string $actorId, callable $work): mixed
    {
        $container = (new Application(new ApplicationParams(noErrorHandler: true)))->getContainer();
        $user = $container->getByClass(EntityManager::class)->getEntityById(User::ENTITY_TYPE, $actorId);
        if (!$user instanceof User || $user->get('deleted') || !$user->isActive() || $user->isPortal() ||
            $user->isApi() || $user->isSystem()) {
            throw new Forbidden('Enrichment purchaser is unavailable.');
        }
        $container->getByClass(ApplicationUser::class)->setUser($user);
        $factory = $container->getByClass(InjectableFactory::class);
        return $work(fn (string $class): object => $factory->create($class));
    }
}
