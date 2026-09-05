<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Entities\Note;
use Espo\Modules\Chatwoot\Services\OpportunityPostMentions;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\ORM\EntityManager;

/** Runs after the normal metadata schema rebuild, never during an API request. */
class BackfillOpportunityReadStates implements RebuildAction
{
    private const FLAG = 'opportunityReadStatesBackfilledAt';

    public function __construct(
        private EntityManager $entityManager,
        private OpportunityReadStateService $service,
        private OpportunityPostMentions $mentions,
        private Config $config,
        private ConfigWriter $configWriter,
    ) {}

    public function process(): void
    {
        if ($this->config->get(self::FLAG)) {
            return;
        }
        $this->removeLegacyIndexes();
        $timestamp = gmdate('Y-m-d H:i:s');
        $number = (int) $this->entityManager->getRDBRepository('Note')->max('number');
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
                        $mentionedIds = $this->mentions->resolve($post);
                        $post->set('opportunityMentionUserIds', $mentionedIds);
                        // Do not touch modifiedAt or replay author/notification hooks for old posts.
                        $this->entityManager->saveEntity($post, ['skipAll' => true]);
                        $users = array_merge($users, [$post->get('createdById')], $mentionedIds);
                    }
                } while (count($posts) === 200);

                $users = array_values(array_unique(array_filter($users)));
                if (!$users) {
                    continue;
                }
                foreach ($this->entityManager->getRDBRepository('User')->where(['id' => $users])->find() as $user) {
                    if ($user->isRegular() || $user->isAdmin()) {
                        // Existing read/unread cutoffs survive repeated/partially completed rebuilds.
                        $this->service->addParticipant($id, $user->getId(), $timestamp, $number, true);
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
