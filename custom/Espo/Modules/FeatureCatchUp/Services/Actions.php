<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Utils\Language;
use Espo\ORM\EntityManager;

/** Card commits that change the deal. Both go through the record services, so ACL, validation and hooks apply. */
class Actions
{
    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private ServiceContainer $records,
        private Workspace $workspace,
        private Feed $feed,
        private Verdicts $verdicts,
        private Language $language,
    ) {}

    /** Ganho or Perdido: the deal moves to its funnel's 100% or 0% stage; the stage hook sets the status. */
    public function close(Request $request, string $id): array
    {
        $status = (string) ($request->getParsedBody()->status ?? '');
        if (!in_array($status, ['Won', 'Lost'], true)) throw new BadRequest('Invalid status.');
        $deal = $this->feed->deal($id, $this->workspace->resolve($request)['tenant']->getId());
        if (!$this->acl->checkEntityEdit($deal)) throw new Forbidden();
        if (in_array($deal->get('status'), ['Won', 'Lost'], true)) throw new Conflict('This deal is already closed.');
        $stage = $this->em->getRDBRepository('OpportunityStage')->where([
            'funnelId' => $deal->get('funnelId'), 'isActive' => true, 'probability' => $status === 'Won' ? 100 : 0,
        ])->order('order')->findOne();
        if (!$deal->get('funnelId') || !$stage) throw new BadRequest('This funnel has no stage for that outcome.');
        $this->records->get('Opportunity')->update($id, (object) ['opportunityStageId' => $stage->getId()], UpdateParams::create());
        $this->verdicts->resolve($id);
        return ['success' => true, 'status' => $status];
    }

    /** "Já resolvi por fora": the work Monostax couldn't see becomes a note the next analysis reads. */
    public function resolvedElsewhere(Request $request, string $id): array
    {
        $text = trim((string) ($request->getParsedBody()->text ?? ''));
        if ($text === '' || mb_strlen($text) > 500) throw new BadRequest('Invalid note.');
        $this->feed->deal($id, $this->workspace->resolve($request)['tenant']->getId());
        $prefix = $this->language->translateLabel('resolvedElsewhere', 'labels', 'CatchUpVerdict');
        $this->records->get('Note')->create((object) [
            'type' => 'Post', 'parentType' => 'Opportunity', 'parentId' => $id, 'post' => "$prefix: $text",
        ], CreateParams::create());
        $this->verdicts->resolve($id);
        return ['success' => true];
    }
}
