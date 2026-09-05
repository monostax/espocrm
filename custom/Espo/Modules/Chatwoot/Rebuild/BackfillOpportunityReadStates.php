<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Acl;
use Espo\Core\InjectableFactory;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\SystemUser;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityPostMentions;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\ORM\EntityManager;
use RuntimeException;

/** Runs after the normal metadata schema rebuild, never during an API request. */
class BackfillOpportunityReadStates implements RebuildAction
{
    private const FLAG = 'opportunityReadStatesBackfilledAt';
    private const CUTOFF = 'opportunityReadStatesBackfillCutoff';

    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private Config $config,
        private ConfigWriter $configWriter,
    ) {}

    public function process(): void
    {
        if ($this->config->get(self::FLAG)) {
            return;
        }

        // Rebuild runs with noSystemUser=true and constructs every action before
        // processing any of them. Resolve these user-dependent helpers only now,
        // after the core AddSystemUser action, with an explicit local context.
        // Do not replace the application's current user or its cached ACL service.
        $user = $this->entityManager->getRDBRepositoryByClass(User::class)
            ->where(['userName' => SystemUser::NAME])->findOne();
        if (!$user) {
            throw new RuntimeException('System user is not found.');
        }
        $user->setType(User::TYPE_SYSTEM);
        $acl = $this->injectableFactory->createWith(Acl::class, ['user' => $user]);
        $service = $this->injectableFactory->createWith(OpportunityReadStateService::class, [
            'user' => $user, 'acl' => $acl,
        ]);
        $mentions = $this->injectableFactory->createWith(OpportunityPostMentions::class, ['acl' => $acl]);

        $this->removeLegacyIndexes();
        // Persist the boundary before touching personal state. A retry must not
        // consume posts created after this migration first started.
        $cutoff = $this->config->get(self::CUTOFF);
        if (!$cutoff) {
            $cutoff = [
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'number' => (int) $this->entityManager->getRDBRepository('Note')->max('number'),
            ];
            $this->configWriter->set(self::CUTOFF, $cutoff);
            $this->configWriter->save();
        }
        $timestamp = $cutoff['timestamp'];
        $number = $cutoff['number'];
        $afterId = '';
        do {
            $opportunities = $this->entityManager->getRDBRepository('Opportunity')
                ->where(['id>' => $afterId])->order('id')->limit(0, 100)->find();
            foreach ($opportunities as $opportunity) {
                $id = $opportunity->getId();
                $afterId = $id;
                $users = array_filter([$opportunity->get('assignedUserId')]);
                foreach ($this->entityManager->getRDBRepository('StreamSubscription')->where([
                    'entityType' => 'Opportunity', 'entityId' => $id,
                ])->find() as $subscription) {
                    $users[] = $subscription->get('userId');
                }

                $afterNumber = 0;
                do {
                    $posts = $this->entityManager->getRDBRepository('Note')->where([
                        'parentType' => 'Opportunity', 'parentId' => $id, 'type' => Note::TYPE_POST,
                        'number>' => $afterNumber, 'number<=' => $number,
                    ])->order('number')->limit(0, 200)->find();
                    foreach ($posts as $post) {
                        $afterNumber = (int) $post->get('number');
                        $mentionedIds = $mentions->resolve($post);
                        $post->set('opportunityMentionUserIds', $mentionedIds);
                        // Do not touch modifiedAt or replay author/notification hooks for old posts.
                        $this->entityManager->saveEntity($post, ['skipAll' => true]);
                        $users = array_merge($users, [$post->get('createdById')], $mentionedIds);
                    }
                } while (count($posts) === 200);

                $participantIds = array_fill_keys(array_filter($users), true);
                // Include existing personal rows even for viewers who are not
                // participants. Clear historical unread without enrolling them.
                foreach ($this->entityManager->getRDBRepository('OpportunityReadState')
                    ->where(['opportunityId' => $id])->find() as $state) {
                    $users[] = $state->get('userId');
                }
                $users = array_values(array_unique(array_filter($users)));
                if (!$users) {
                    continue;
                }
                foreach ($this->entityManager->getRDBRepository('User')->where(['id' => $users])->find() as $user) {
                    if ($user->isRegular() || $user->isAdmin()) {
                        $service->initializeReadBaseline(
                            $id, $user->getId(), $timestamp, $number, isset($participantIds[$user->getId()]),
                        );
                    }
                }
            }
        } while (count($opportunities) === 100);

        $this->configWriter->set(self::FLAG, $timestamp);
        $this->configWriter->save();
    }

    private function removeLegacyIndexes(): void
    {
        $pdo = $this->entityManager->getPDO();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $rows = $pdo->query('SHOW INDEX FROM opportunity_read_state')->fetchAll(\PDO::FETCH_ASSOC);
            $names = array_column($rows, 'Key_name');
            foreach (['uniq_user_opp', 'idx_opp'] as $name) {
                if (in_array($name, $names, true)) {
                    $pdo->exec("ALTER TABLE opportunity_read_state DROP INDEX `$name`");
                }
            }
        } elseif ($driver === 'pgsql') {
            foreach (['uniq_user_opp', 'idx_opp'] as $name) {
                $query = $pdo->prepare("SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'opportunity_read_state' AND indexname = ?");
                $query->execute([$name]);
                if ($query->fetchColumn()) {
                    $pdo->exec("DROP INDEX $name");
                }
            }
        }
    }
}
