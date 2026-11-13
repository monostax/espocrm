<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\Chatwoot\Hooks;

use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Hooks\Note\NormalizeOpportunityMentions;
use Espo\Modules\Chatwoot\Services\OpportunityPostMentions;
use Espo\Modules\FeatureCredits\Accounting\AgreementModels;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/** Per-user model choice on mural mentions (2026-11-13): the picked model rides on each AI target. */
class NormalizeOpportunityMentionsModelTest extends TestCase
{
    private function note(?string $model): Note
    {
        $defs = ['attributes' => []];
        foreach (['id', 'type', 'post', 'parentType', 'parentId', 'createdById', 'opportunityAiModel'] as $field) {
            $defs['attributes'][$field] = ['type' => 'varchar'];
        }
        $defs['attributes']['data'] = ['type' => 'jsonObject'];
        $defs['attributes']['opportunityMentionUserIds'] = ['type' => 'jsonArray'];
        $note = new Note('Note', $defs);
        $note->set(['type' => 'Post', 'parentType' => 'Opportunity', 'parentId' => 'opp', 'post' => '@IA resuma',
            'opportunityAiModel' => $model]);
        return $note;
    }

    private function hook(array $targets, ?AgreementModels $models = null): NormalizeOpportunityMentions
    {
        $mentions = $this->createMock(OpportunityPostMentions::class);
        $mentions->method('resolve')->willReturn([]);
        $mentions->method('resolveAiTargets')->willReturnCallback(static fn () => array_map(static fn ($t) => clone $t, $targets));
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('human');
        if (!$models) {
            $models = $this->createMock(AgreementModels::class);
            $models->method('allows')->willReturnCallback(static fn (string $tenant, ?string $model) =>
                $tenant === 'tenant' && $model === 'openrouter/openai/gpt-6.1-terra');
        }
        return new NormalizeOpportunityMentions($mentions, $user, $models);
    }

    private function targets(): array
    {
        return [
            (object) ['aiAgentMembershipId' => 'ai-1', 'chatwootAccountCrmId' => 'account', 'crmTenantId' => 'tenant'],
            (object) ['aiAgentMembershipId' => 'ai-2', 'chatwootAccountCrmId' => 'account', 'crmTenantId' => 'tenant'],
        ];
    }

    public function testPickedModelIsStampedOnEveryMentionedAi(): void
    {
        $note = $this->note('openrouter/openai/gpt-6.1-terra');
        $this->hook($this->targets())->beforeSave($note, SaveOptions::fromAssoc([]));
        $targets = $note->getData()->opportunityAiMentionTargets;
        self::assertSame(['openrouter/openai/gpt-6.1-terra', 'openrouter/openai/gpt-6.1-terra'], array_column($targets, 'model'));
        self::assertSame('human', $note->getData()->opportunityAiInitiatorUserId);
    }

    public function testNoModelKeepsTargetsExactlyAsBefore(): void
    {
        $models = $this->createMock(AgreementModels::class);
        $models->expects(self::never())->method('allows');
        foreach ([null, ''] as $model) {
            $note = $this->note($model);
            $this->hook($this->targets(), $models)->beforeSave($note, SaveOptions::fromAssoc([]));
            self::assertEquals($this->targets(), $note->getData()->opportunityAiMentionTargets);
        }
    }

    public function testUnavailableModelRejectsThePostInsteadOfDroppingIt(): void
    {
        $note = $this->note('openrouter/openai/gpt-5.6-terra');
        $this->expectException(BadRequest::class);
        $this->hook($this->targets())->beforeSave($note, SaveOptions::fromAssoc([]));
    }

    public function testModelWithoutMentionedAiIsIgnored(): void
    {
        $note = $this->note('openrouter/openai/gpt-5.6-terra');
        $this->hook([])->beforeSave($note, SaveOptions::fromAssoc([]));
        self::assertSame([], $note->getData()->opportunityAiMentionTargets);
    }
}
