<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

require_once __DIR__ . '/OpportunityWatcherTestCase.php';

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use DateTimeImmutable;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Database\Schema\SchemaManager;
use Espo\Entities\User;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Modules\Chatwoot\Rebuild\StartOpportunityWatcher;
use Espo\Modules\Chatwoot\Services\OpportunityWatcher;
use Espo\Modules\FeatureGoogleAdsConversions\Services\GoogleAdsUploadFactory;
use Espo\Modules\FeatureMetaConversionsApi\Jobs\SendCapiEvent;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Uses a disposable installed database (data/config.php), real ACL, Opportunity and Note hooks, and transactions. */
class OpportunityWatcherTest extends OpportunityWatcherTestCase
{
    public function testNewDealsAreWatchedByDefault(): void
    {
        self::assertTrue($this->deal()->get('aiWatchEnabled'));
    }

    public function testDealsThatExistedBeforeTheWatcherStartSwitchedOffOnce(): void
    {
        $fresh = fn () => new Application(new ApplicationParams(noErrorHandler: true));
        $writer = $fresh()->getInjectableFactory()->create(ConfigWriter::class);
        $writer->remove(OpportunityWatcher::STARTED_AT);
        $writer->save();
        $old = $this->deal('proposal');
        self::assertTrue($old->get('aiWatchEnabled'));

        $fresh()->getInjectableFactory()->create(StartOpportunityWatcher::class)->process();
        self::assertFalse($this->em->getEntityById('Opportunity', $old->getId())->get('aiWatchEnabled'));
        self::assertNotEmpty($fresh()->getContainer()->getByClass(\Espo\Core\Utils\Config::class)->get(OpportunityWatcher::STARTED_AT));
        self::assertSame('off', $this->service()->decide($old->getId(), $this->body('move', 'proposal', 'won'))->outcome);

        // From then on: new deals are watched, a person can switch an old one on, and later rebuilds leave both alone.
        $new = $this->deal('proposal');
        $switchedOn = $this->em->getEntityById('Opportunity', $old->getId());
        $switchedOn->set('aiWatchEnabled', true);
        $this->em->saveEntity($switchedOn);
        $fresh()->getInjectableFactory()->create(StartOpportunityWatcher::class)->process();
        self::assertTrue($this->em->getEntityById('Opportunity', $new->getId())->get('aiWatchEnabled'));
        self::assertTrue($this->em->getEntityById('Opportunity', $old->getId())->get('aiWatchEnabled'));
    }

    public function testAMoveChangesTheStageExplainsItOnTheStreamAndIsLoggedOnce(): void
    {
        $deal = $this->deal('proposal');
        $body = $this->body('move', 'proposal', 'won');
        $result = $this->service()->decide($deal->getId(), $body);
        self::assertSame('moved', $result->outcome);
        self::assertSame('Won', $result->status);

        $moved = $this->em->getEntityById('Opportunity', $deal->getId());
        self::assertSame($this->stages['won']->getId(), $moved->get('opportunityStageId'));
        self::assertSame('Won', $moved->get('status'));
        self::assertSame('FollowupInactive', $moved->get('followupStatus'));

        $posts = array_values(array_filter($this->notes($deal->getId(), 'Post'), fn ($note) => $note->get('data')->opportunityWatcher ?? null));
        self::assertCount(1, $posts);
        self::assertSame($body->reason, $posts[0]->get('post'));
        self::assertTrue($posts[0]->get('isInternal'));
        self::assertSame($this->ai->getId(), $posts[0]->get('createdById'));
        $marker = $posts[0]->get('data')->opportunityWatcher;
        self::assertSame('moved', $marker->kind);
        self::assertSame('Proposta enviada', $marker->fromStageName);
        self::assertSame('Ganho', $marker->toStageName);
        self::assertSame(0.97, $marker->probability);

        $pills = $this->notes($deal->getId(), 'OpportunityStageChanged');
        self::assertCount(1, $pills);
        self::assertSame('Ganho', $pills[0]->get('data')->toStageName);
        self::assertSame('Proposta enviada', $pills[0]->get('data')->fromStageName);
        self::assertSame($this->ai->getId(), $pills[0]->get('createdById'));

        // The same run delivered twice changes nothing.
        self::assertSame('moved', $this->service()->decide($deal->getId(), $body)->outcome);
        self::assertSame(['moved'], $this->decisions($deal->getId()));
        self::assertCount(1, $this->notes($deal->getId(), 'OpportunityStageChanged'));
    }

    public function testASwitchedOffDealIsLeftAlone(): void
    {
        $deal = $this->deal('proposal');
        $deal->set('aiWatchEnabled', false);
        $this->em->saveEntity($deal);
        self::assertSame('off', $this->service()->decide($deal->getId(), $this->body('move', 'proposal', 'won'))->outcome);
        self::assertSame($this->stages['proposal']->getId(), $this->em->getEntityById('Opportunity', $deal->getId())->get('opportunityStageId'));
        self::assertSame(['off'], $this->decisions($deal->getId()));
    }

    public function testMovesGoToAnyOtherActiveStageOfTheFunnelFromTheExpectedStage(): void
    {
        $deal = $this->deal('proposal');
        $id = $deal->getId();
        // The deal is no longer where the decision saw it.
        self::assertSame('stale', $this->service()->decide($id, $this->body('move', 'lead', 'proposal'))->outcome);
        // Its own stage is no move.
        self::assertSame('stale', $this->service()->decide($id, $this->body('move', 'proposal', 'proposal'))->outcome);
        // A stage of another funnel, or a switched-off stage.
        $other = $this->create('Funnel', ['name' => 'Outro', 'tenantId' => $this->tenant->getId(), 'isActive' => true]);
        $foreign = $this->create('OpportunityStage', ['name' => 'X', 'order' => 90, 'probability' => 100, 'funnelId' => $other->getId(), 'isActive' => true]);
        self::assertSame('stale', $this->service()->decide($id, $this->body('move', 'proposal', null, ['toStageId' => $foreign->getId()]))->outcome);
        $retired = $this->create('OpportunityStage', ['name' => 'Antigo', 'order' => 5, 'probability' => 5, 'funnelId' => $this->funnel->getId(), 'isActive' => false]);
        self::assertSame('stale', $this->service()->decide($id, $this->body('move', 'proposal', null, ['toStageId' => $retired->getId()]))->outcome);
        self::assertSame($this->stages['proposal']->getId(), $this->em->getEntityById('Opportunity', $id)->get('opportunityStageId'));

        // Backwards is a move like any other.
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'proposal', 'lead'))->outcome);
        self::assertSame($this->stages['lead']->getId(), $this->em->getEntityById('Opportunity', $id)->get('opportunityStageId'));
    }

    public function testAClosedDealCanBeReopenedOrSwitchedBetweenWonAndLost(): void
    {
        $deal = $this->deal('proposal');
        $id = $deal->getId();
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'proposal', 'lost'))->outcome);
        self::assertSame('Lost', $this->em->getEntityById('Opportunity', $id)->get('status'));

        // Lost to Won: the deal was closed the wrong way.
        $result = $this->service()->decide($id, $this->body('move', 'lost', 'won'));
        self::assertSame('moved', $result->outcome);
        self::assertSame('Won', $result->status);

        // Won back to an open stage reopens it: the status follows the stage and the close date is cleared.
        $result = $this->service()->decide($id, $this->body('move', 'won', 'proposal'));
        self::assertSame('moved', $result->outcome);
        self::assertSame('Open', $result->status);
        $reopened = $this->em->getEntityById('Opportunity', $id);
        self::assertSame($this->stages['proposal']->getId(), $reopened->get('opportunityStageId'));
        self::assertNull($reopened->get('closeDate'));

        $posts = array_values(array_filter($this->notes($id, 'Post'), fn ($note) => $note->get('data')->opportunityWatcher ?? null));
        $last = end($posts)->get('data')->opportunityWatcher;
        self::assertSame('moved', $last->kind);
        self::assertSame('Won', $last->fromStatus);
        self::assertSame('Open', $last->toStatus);
        self::assertSame(['moved', 'moved', 'moved'], $this->decisions($id));
    }

    public function testTheWatcherMayReturnToAStageItLeftItself(): void
    {
        $deal = $this->deal('lead');
        $id = $deal->getId();
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'lead', 'proposal'))->outcome);
        sleep(1); // createdAt has second precision: the move out must come after the move in.
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'proposal', 'lead'))->outcome);
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'lead', 'proposal'))->outcome);
        self::assertSame($this->stages['proposal']->getId(), $this->em->getEntityById('Opportunity', $id)->get('opportunityStageId'));
    }

    public function testAReopenedDealRecordsItsWinOnlyOnce(): void
    {
        $deal = $this->deal('proposal');
        $id = $deal->getId();
        $wins = fn () => $this->em->getRDBRepository('TrackingEvent')
            ->where(['code' => 'opportunity_won', 'parentType' => 'Opportunity', 'parentId' => $id])->count();
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'proposal', 'won'))->outcome);
        self::assertSame(1, $wins(), 'the first win is tracked');
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'won', 'proposal'))->outcome);
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'proposal', 'won'))->outcome);
        self::assertSame(1, $wins());
    }

    public function testAMetaStageEventAlreadySentForTheDealIsNotSentAgain(): void
    {
        $deal = $this->deal('proposal');
        $id = $deal->getId();
        $this->create('MetaCapiEventLog', [
            'name' => 'Purchase — Opportunity', 'eventName' => 'Purchase', 'status' => 'Sent',
            'subjectType' => 'Opportunity', 'subjectId' => $id, 'opportunityId' => $id, 'tenantId' => $this->tenant->getId(),
        ]);
        $logs = fn (string $event) => $this->em->getRDBRepository('MetaCapiEventLog')->where(['opportunityId' => $id, 'eventName' => $event])->count();
        $job = $this->app->getInjectableFactory()->create(SendCapiEvent::class);
        $context = ['opportunityStageFromId' => $this->stages['proposal']->getId(), 'opportunityStageToId' => $this->stages['won']->getId()];

        $job->run(Data::create(['entityType' => 'Opportunity', 'entityId' => $id, 'eventName' => 'Purchase', 'context' => $context]));
        self::assertSame(1, $logs('Purchase'), 'nothing new for an event the deal already sent');
        // Another event still goes through the dispatcher, which logs it (skipped here: no dataset is configured).
        $job->run(Data::create(['entityType' => 'Opportunity', 'entityId' => $id, 'eventName' => 'Lead', 'context' => $context]));
        self::assertSame(1, $logs('Lead'));
    }

    public function testAGoogleAdsConversionAlreadyUploadedForTheDealIsNotUploadedAgain(): void
    {
        $deal = $this->deal('proposal');
        $mapping = $this->em->getEntityById('GoogleAdsConversionMapping', $this->create('GoogleAdsConversionMapping', [
            'name' => 'Ganho', 'tenantId' => $this->tenant->getId(), 'funnelId' => $this->funnel->getId(),
            'opportunityStageId' => $this->stages['won']->getId(),
        ])->getId());
        $upload = $this->create('GoogleAdsConversionUpload', [
            'name' => 'Ganho', 'opportunityId' => $deal->getId(), 'mappingId' => $mapping->getId(), 'status' => 'Sent',
            'validateOnly' => false, 'idempotencyKey' => bin2hex(random_bytes(16)), 'tenantId' => $this->tenant->getId(),
        ]);
        $factory = $this->app->getInjectableFactory()->create(GoogleAdsUploadFactory::class);
        $deal = $this->em->getEntityById('Opportunity', $deal->getId());
        self::assertNull($factory->createForOpportunity($deal, $mapping, $this->stages['proposal']->getId(), $this->stages['won']->getId(), new DateTimeImmutable()));

        // An upload that failed for good does not count: the factory goes on (and stops here at the missing destination).
        $upload->set('status', 'FailedPermanent');
        $this->em->saveEntity($upload, ['skipHooks' => true]);
        $this->expectExceptionMessage('Google Ads destination is missing.');
        $factory->createForOpportunity($deal, $mapping, $this->stages['proposal']->getId(), $this->stages['won']->getId(), new DateTimeImmutable());
    }

    public function testDecisionsThatMoveNothingAreOnlyLogged(): void
    {
        $deal = $this->deal('lead');
        foreach (['none', 'vetoed', 'noCredits'] as $outcome) {
            self::assertSame($outcome, $this->service()->decide($deal->getId(), $this->body($outcome, 'lead', 'proposal'))->outcome);
        }
        self::assertSame($this->stages['lead']->getId(), $this->em->getEntityById('Opportunity', $deal->getId())->get('opportunityStageId'));
        self::assertSame([], $this->notes($deal->getId(), 'OpportunityStageChanged'));
        self::assertEqualsCanonicalizing(['none', 'vetoed', 'noCredits'], $this->decisions($deal->getId()));
    }

    public function testAStageAPersonMovedTheDealBackOutOfIsNotEnteredAgain(): void
    {
        $deal = $this->deal('lead');
        $id = $deal->getId();
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'lead', 'proposal'))->outcome);
        $back = $this->em->getEntityById('Opportunity', $id);
        $back->set('opportunityStageId', $this->stages['lead']->getId());
        $this->em->saveEntity($back);
        self::assertCount(2, $this->notes($id, 'OpportunityStageChanged'), 'a person\'s move leaves a pill too');
        self::assertSame('reverted', $this->service()->decide($id, $this->body('move', 'lead', 'proposal'))->outcome);
        self::assertSame($this->stages['lead']->getId(), $this->em->getEntityById('Opportunity', $id)->get('opportunityStageId'));
        // Another stage is still open to it.
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'lead', 'won'))->outcome);
    }

    public function testAStageMissingItsRequiredFieldsBlocksTheMoveAndSaysSoOnce(): void
    {
        $this->create('CustomFieldDef', [
            'name' => 'convenio', 'label' => 'Convênio', 'entityType' => 'Opportunity', 'type' => 'varchar', 'valueKey' => 'convenio',
            'tenantId' => $this->tenant->getId(), 'teamsIds' => [$this->team->getId()], 'isActive' => true,
        ]);
        $this->stages['won']->set('requiredCustomFieldKeys', ['convenio']);
        $this->em->saveEntity($this->stages['won'], ['skipHooks' => true]);
        $deal = $this->deal('proposal');
        $id = $deal->getId();

        // Two looks at the same deal: neither moves it, and the stream is told once.
        foreach ([1, 2] as $look) {
            self::assertSame('blocked', $this->service()->decide($id, $this->body('move', 'proposal', 'won'))->outcome, "look $look");
        }
        $still = $this->em->getEntityById('Opportunity', $id);
        self::assertSame($this->stages['proposal']->getId(), $still->get('opportunityStageId'));
        self::assertSame('Open', $still->get('status'));
        self::assertSame([], $this->notes($id, 'OpportunityStageChanged'));
        self::assertSame(['blocked', 'blocked'], $this->decisions($id));

        $posts = array_values(array_filter($this->notes($id, 'Post'), fn ($note) => $note->get('data')->opportunityWatcher ?? null));
        self::assertCount(1, $posts);
        self::assertTrue($posts[0]->get('isInternal'));
        $marker = $posts[0]->get('data')->opportunityWatcher;
        self::assertSame('blocked', $marker->kind);
        self::assertSame('Ganho', $marker->toStageName);
        self::assertSame(['Convênio'], $marker->missingFields);

        // With the field filled in, the same move goes through.
        $still->set('customFields', (object) ['convenio' => 'Unimed']);
        $this->em->saveEntity($still);
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'proposal', 'won'))->outcome);
        self::assertSame('Won', $this->em->getEntityById('Opportunity', $id)->get('status'));
    }

    public function testAnotherTenantsUserCannotDecide(): void
    {
        $deal = $this->deal('proposal');
        $team = $this->create('Team', ['name' => 'Other tenant']);
        $this->create('Tenant', ['name' => 'Other tenant', 'baseUserTeamId' => $team->getId()]);
        $outsider = $this->user($team);
        foreach (['move', 'none'] as $outcome) {
            try {
                $this->service($outsider)->decide($deal->getId(), $this->body($outcome, 'proposal', 'won'));
                self::fail('A user of another tenant decided on the deal.');
            } catch (Forbidden) {
            }
        }
        self::assertSame($this->stages['proposal']->getId(), $this->em->getEntityById('Opportunity', $deal->getId())->get('opportunityStageId'));
        self::assertSame([], $this->decisions($deal->getId()));
    }
}
