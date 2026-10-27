<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Classes\Console\Commands;

use Espo\Core\Api\Starter;
use Espo\Core\ApplicationUser;
use Espo\Core\Authentication\AuthToken\Manager;
use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureLiveRecords\Subscription\Projection;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use GuzzleHttp\Psr7\ServerRequest;

/** One fresh application context per viewer batch; never reuse system/other-viewer ACL services. */
final class LiveRecordQuery implements Command
{
    public function run(Params $params, IO $io): void
    {
        $io->setExitStatus(1);
        try {
            $input = json_decode(stream_get_contents(STDIN, 1048577), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($input) || !is_string($input['token'] ?? null) || !is_string($input['userId'] ?? null)) return;
            // command.php's container already holds the system user. Never
            // reuse it for a viewing identity or its ACL-bound services.
            $container = (new Application(new ApplicationParams(noErrorHandler: true)))->getContainer();
            $token = $container->getByClass(Manager::class)->get($input['token']);
            if (!$token?->isActive() || $token->getUserId() !== $input['userId']) return;
            $user = $container->getByClass(EntityManager::class)->getEntityById(User::ENTITY_TYPE, $input['userId']);
            if (!$user instanceof User || !$user->isActive() || $user->isPortal() || $user->isApi()) return;
            if ($token->getPasswordVersion() !== null && $token->getPasswordVersion() !== ($user->getPasswordVersion() ?? 0)) return;
            $container->getByClass(ApplicationUser::class)->setUser($user);
            $topics = $input['topics'] ?? [$input['topic'] ?? ''];
            if (!is_array($topics) || !array_is_list($topics) || !$topics || count($topics) > 32) return;
            $factory = $container->getByClass(\Espo\Core\InjectableFactory::class);
            $starter = $factory->create(Starter::class);
            // Lets the socket skip other tenants' change hints for this viewer.
            // Empty (no tenant) means the viewer matches every hint.
            $tenantIds = $factory->create(UserTenantResolver::class)->resolveTenantIds($user);
            $results = [];
            foreach ($topics as $index => $topic) {
                try {
                    $projection = Projection::decode($topic);
                    parse_str($projection['query'], $query);
                    $request = (new ServerRequest('GET', 'http://localhost' . $projection['path']))->withQueryParams($query);
                    $response = $starter->handleAuthenticatedRead($request);
                    $status = $response->getStatusCode();
                    $results[] = match (true) {
                        in_array($status, [401, 403, 404], true) => ['denied' => true, 'tenantIds' => $tenantIds],
                        $status === 200 => ['fingerprint' => Projection::fingerprint((string) $response->getBody()), 'tenantIds' => $tenantIds],
                        default => [], // Transient failure does not revoke the projection.
                    };
                } catch (\Throwable) {
                    $results[] = [];
                }
                if (($input['stream'] ?? false) === true) {
                    $io->writeLine(json_encode(['index' => $index, 'result' => $results[$index]], JSON_THROW_ON_ERROR));
                }
            }
            if (($input['stream'] ?? false) !== true) {
                $io->write(json_encode(isset($input['topics']) ? ['results' => $results] : $results[0], JSON_THROW_ON_ERROR));
            }
            $io->setExitStatus(0);
        } catch (\Throwable) {
            $io->setExitStatus(2);
        }
    }
}
