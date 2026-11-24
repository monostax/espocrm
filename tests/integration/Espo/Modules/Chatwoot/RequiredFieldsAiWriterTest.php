<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

require_once __DIR__ . '/OpportunityWatcherTestCase.php';

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\ConflictSilent;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * An AI agent's save is never refused for an empty required custom field (user decision, 2026-11-24): it fills what
 * the conversation tells, the save goes through, and the stage pill names what a person still has to fill in.
 */
class RequiredFieldsAiWriterTest extends OpportunityWatcherTestCase
{
    private User $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai->set('type', 'api');
        $this->em->saveEntity($this->ai, ['skipHooks' => true]);
        $chatwootUser = $this->create('ChatwootUser', ['name' => 'IA', 'assignedUserId' => $this->ai->getId()]);
        $this->create('ChatwootAccountUserMembership', ['name' => 'IA', 'chatwootUserId' => $chatwootUser->getId(), 'isAI' => true]);
        $this->person = $this->user($this->team);
        // Always required, like Nowle's fields; and one the Ganho stage asks for on entry.
        $this->field('convenio', 'Convênio', 'varchar', ['isRequired' => true]);
        $this->field('plano', 'Plano', 'enum', ['options' => ['Ouro', 'Prata']]);
        $this->stages['won']->set('requiredCustomFieldKeys', ['plano']);
        $this->em->saveEntity($this->stages['won'], ['skipHooks' => true]);
    }

    public function testTheWatcherFillsWhatItCanMovesAnywayAndThePillNamesWhatIsLeft(): void
    {
        $deal = $this->deal('proposal');
        $id = $deal->getId();
        // The AI created the deal with the always-required field empty: the deal exists and its pill says so.
        $created = $this->notes($id, 'OpportunityStageChanged');
        self::assertCount(1, $created);
        self::assertTrue($created[0]->get('data')->created);
        self::assertSame(['Convênio'], $created[0]->get('data')->missingFields);

        $body = (object) [
            'outcome' => 'act', 'runId' => 'run-' . bin2hex(random_bytes(8)), 'model' => 'nace-ai/drex-max-20261016',
            'conversationId' => 77, 'probability' => 0.97, 'details' => (object) ['mode' => 'write'],
            'actions' => [
                (object) ['kind' => 'fields', 'reason' => 'A cliente disse o convênio.',
                    'values' => (object) ['convenio' => 'Unimed', 'plano' => 'Bronze', 'semCampo' => 'x']],
                (object) ['kind' => 'stage', 'reason' => 'Pagou.', 'expectedStageId' => $this->stages['proposal']->getId(),
                    'toStageId' => $this->stages['won']->getId()],
            ],
        ];
        $result = $this->service(null, $this->openAccess())->decide($id, $body);
        self::assertSame('moved', $result->outcome);
        self::assertSame(['applied', 'moved'], array_map(fn ($action) => $action->outcome, $result->actions));

        $fresh = $this->em->getEntityById('Opportunity', $id);
        self::assertSame('Won', $fresh->get('status'));
        self::assertEquals((object) ['convenio' => 'Unimed'], $fresh->get('customFields'));

        $row = $this->em->getRDBRepository('OpportunityWatcherDecision')->where(['runId' => $body->runId])->findOne();
        $actions = array_map(fn ($action) => (array) $action, $row->get('actions'));
        self::assertEquals([(object) ['label' => 'Convênio', 'value' => 'Unimed']], $actions[0]['fields']);
        self::assertSame(['Plano'], $actions[1]['missingFields']);

        $pills = $this->notes($id, 'OpportunityStageChanged');
        self::assertCount(2, $pills);
        self::assertSame('Ganho', $pills[1]->get('data')->toStageName);
        self::assertSame(['Plano'], $pills[1]->get('data')->missingFields);
        self::assertObjectNotHasProperty('filledFields', $pills[1]->get('data'));
    }

    public function testAnAiSaveGoesThroughUndoesAValueTheFieldRejectsAndSaysWhatIsLeft(): void
    {
        $deal = $this->deal('proposal');
        // Through the record service, as the AI's update_opportunity tool calls the API: the answer names the gap.
        $records = $this->app($this->ai)->getContainer()->getByClass(ServiceContainer::class)->get('Opportunity');
        $saved = $records->update($deal->getId(), (object) ['description' => 'Pediu proposta.'], UpdateParams::create());
        $left = $saved->getValueMap()->requiredFieldsLeftEmpty ?? null;
        self::assertNotNull($left, 'The API answer names what was left empty.');
        self::assertSame('save', $left->mode);
        self::assertSame(['convenio'], array_map(fn ($field) => ((object) $field)->valueKey, $left->fields));

        // A stage move that brings a value the field rejects: the move stands, the value is not stored.
        $em = $this->app($this->ai)->getContainer()->getByClass(EntityManager::class);
        $mine = $em->getEntityById('Opportunity', $deal->getId());
        $mine->set(['opportunityStageId' => $this->stages['won']->getId(),
            'customFields' => (object) ['convenio' => 'Bradesco', 'plano' => 'Bronze']]);
        $em->saveEntity($mine);
        $left = $mine->get('requiredFieldsLeftEmpty');
        self::assertSame('stageEntry', $left->mode);
        self::assertSame(['plano'], array_map(fn ($field) => ((object) $field)->valueKey, $left->fields));
        self::assertSame('required', ((object) $left->fields[0])->reason);

        $fresh = $this->em->getEntityById('Opportunity', $deal->getId());
        self::assertSame('Won', $fresh->get('status'));
        self::assertEquals((object) ['convenio' => 'Bradesco'], $fresh->get('customFields'));
        $pill = array_slice($this->notes($deal->getId(), 'OpportunityStageChanged'), -1)[0]->get('data');
        self::assertEquals([(object) ['label' => 'Convênio', 'value' => 'Bradesco']], $pill->filledFields);
        self::assertSame(['Plano'], $pill->missingFields);
    }

    public function testAPersonIsStillAskedForTheFields(): void
    {
        $deal = $this->deal('proposal');
        $em = $this->app($this->person)->getContainer()->getByClass(EntityManager::class);
        $mine = $em->getEntityById('Opportunity', $deal->getId());
        $mine->set('opportunityStageId', $this->stages['won']->getId());
        $this->expectException(ConflictSilent::class);
        $em->saveEntity($mine);
    }

    private function field(string $key, string $label, string $type, array $extra = []): void
    {
        $this->create('CustomFieldDef', [
            'name' => $key, 'label' => $label, 'entityType' => 'Opportunity', 'type' => $type, 'valueKey' => $key,
            'tenantId' => $this->tenant->getId(), 'teamsIds' => [$this->team->getId()], 'isActive' => true,
        ] + $extra);
    }

    private function app(User $user): Application
    {
        return new Application(new ApplicationParams(noErrorHandler: true,
            services: ['user' => $this->em->getEntityById('User', $user->getId())]));
    }

    private function openAccess(): ChatwootAccess
    {
        return new class extends ChatwootAccess {
            public function __construct() {}

            public function forUser(User $user): ChatwootAccess
            {
                return $this;
            }

            public function dealsWithOpenConversation(array $dealIds): array
            {
                return array_fill_keys($dealIds, true);
            }
        };
    }
}
