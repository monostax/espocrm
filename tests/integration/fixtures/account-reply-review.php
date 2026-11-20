<?php
/** Run from an isolated installed application; never sends a message. */
declare(strict_types=1);

use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureJourney\Services\{AccountReplyReview, ActionContext, JourneyTargetPeople, JourneyWhatsAppOutbound,
    JourneyWhatsAppReplyTracker, PeriodParser, TransitionEvaluator, TransitionExecutor};

if (!str_starts_with((include 'data/config-internal.php')['database']['dbname'] ?? '', 'live_records_reply_review_')) {
    throw new RuntimeException('Requires a disposable reply-review test database.');
}
require 'bootstrap.php';
$app = new Application(new Espo\Core\Application\ApplicationParams(noErrorHandler: true));
$app->setupSystemUser();
$em = $app->getContainer()->get('entityManager');
$f = $app->getInjectableFactory();
$make = fn ($type, $values) => $em->createEntity($type, $values, ['skipHooks' => true]);
$check = static function ($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
};
$tenant = $make('Tenant', ['name' => 'Test'])->getId();
$t = ['tenantId' => $tenant];
$actor = $make('User', ['userName' => 'review' . bin2hex(random_bytes(4)), 'type' => 'admin',
    'isActive' => true, 'lastName' => 'Rep']);
$acl = $app->getContainer()->get('aclManager')->createUserAcl($em->getEntityById('User', $actor->getId()));
$a = $make('Account', $t + ['name' => 'Test account']);
$person = $make('Contact', $t + ['lastName' => 'Verified']);
$good = '+5511987654321';
$bad = '+5511991234567';
$person->set('phoneNumberData', [(object) ['phoneNumber' => $good, 'primary' => true]]);
$em->saveEntity($person);
$em->getRelation($a, 'contacts')->relate($person);
$j = $make('Journey', $t + ['name' => 'Review', 'status' => 'Active', 'targetEntityType' => 'Account',
    'reviewAccountWhatsAppReplies' => true, 'runAsUserId' => $actor->getId()]);
$base = $t + ['journeyId' => $j->getId()];
$d0 = $make('JourneyStage', $base + ['name' => 'D0', 'stageType' => 'Entry', 'isActive' => true]);
$exit = $make('JourneyStage', $base + ['name' => 'Reply', 'stageType' => 'Exit', 'isActive' => true]);
$cond = fn ($node) => json_decode(json_encode($node));
$edge = $make('JourneyTransition', $base + ['fromStageId' => $d0->getId(), 'toStageId' => $exit->getId(),
    'eventCodes' => ['whatsapp_replied'], 'isActive' => true,
    'conditionsGroup' => $cond(['type' => 'currentSignal', 'code' => 'whatsapp_replied'])]);
$cw = $make('ChatwootAccount', $t + ['name' => 'Test', 'chatwootAccountId' => random_int(100, 100000000)]);
$integration = $make('ChatwootInboxIntegration', $t + ['name' => 'test integration', 'channelType' => 'whatsappCoexistence']);
$inbox = $make('ChatwootInbox', $t + ['name' => 'WA', 'chatwootAccountId' => $cw->getId(),
    'chatwootInboxId' => 17, 'chatwootInboxIntegrationId' => $integration->getId()]);
$r = $make('JourneyRecord', $base + ['targetType' => 'Account', 'targetId' => $a->getId(), 'status' => 'Active',
    'currentStageId' => $d0->getId(), 'enteredStageAt' => gmdate('Y-m-d H:i:s'),
    'whatsAppChatwootConversationId' => '777', 'whatsAppChatwootAccountId' => $cw->getId()]);
$get = fn () => $em->getEntityById('JourneyRecord', $r->getId());
$tracker = $f->create(JourneyWhatsAppReplyTracker::class);
$payload = ['espoAccountId' => $cw->getId(), 'inboxExternalId' => 17, 'chatwootConversationId' => '777',
    'chatwootMessageId' => 42, 'createdAt' => time(), 'replyPhone' => $bad];
$tracker->handleWebhookIncoming($payload);
$check($get()->get('status') === 'Paused', 'Incoming correlated WhatsApp did not pause the enrollment.');
$exec = $f->create(TransitionExecutor::class);
$check(!$exec->execute($r->getId(), $edge->getId(), ['code' => 'whatsapp_replied']), 'Queued exit ran while paused.');
$review = $f->create(AccountReplyReview::class);
$decide = fn ($decision, $more = []) => $review->decide($r->getId(), (object) ($more +
    ['revision' => AccountReplyReview::summary($get())['revision'], 'decision' => $decision]), $actor->getId(),
    $acl, $f->create(JourneyTargetPeople::class), $f->create(JourneyWhatsAppOutbound::class), $exec);
$decide('wrongNumber', ['conversation' => $cw->getId() . ':777']);
try {
    $decide('resume', ['replacement' => $bad]);
    throw new RuntimeException('Resumed to the rejected number.');
} catch (BadRequest) {}
$check($get()->get('status') === 'Paused', 'Missing replacement did not stay paused.');
$decide('resume', ['replacement' => $good]);
$check($get()->get('status') === 'Active' && $get()->get('currentStageId') === $d0->getId(), 'Resume changed the stage.');
$ctx = new ActionContext($a, $get(), $d0, $j, 'OnEnter', [], $tenant);
$check($ctx->recipients([$a, $person]) === [$person] && $ctx->phones([$bad, $good]) === [$good], 'Wrong recipients.');
$event = $make('TrackingEvent', $t + ['code' => 'whatsapp_replied', 'parentType' => 'Account', 'parentId' => $a->getId(),
    'occurredAt' => gmdate('Y-m-d H:i:s'), 'payload' => (object) ['journeyRecordId' => $r->getId(), 'accountReplyReview' => true]]);
$hist = $em->getNewEntity('JourneyTransition');
$hist->set('conditionsGroup', $cond(['type' => 'eventHistory', 'code' => 'whatsapp_replied', 'sinceEnrollment' => true]));
$eval = $f->create(TransitionEvaluator::class);
$check(!$eval->evaluate($get(), $hist), 'Reviewed reply blocked the resumed timer.');
$event->set('payload', (object) ['channelType' => 'sms', 'journeyRecordId' => $r->getId()]);
$em->saveEntity($event, ['skipHooks' => true]);
$check($eval->evaluate($get(), $hist), 'An SMS was incorrectly consumed by the WhatsApp review.');
$clock = $get();
$clock->set('enteredStageAt', '2026-11-19 12:00:00'); // Thursday. Two business days ends Monday.
$clock->set('replyReviewState', (object) ['heldStage' => $d0->getId(), 'heldEntered' => $clock->get('enteredStageAt'),
    'heldSeconds' => 3 * 86400, 'heldWeekdaySeconds' => 86400]);
$parser = new PeriodParser();
$check(!$parser->isStageDue($clock, '2 business days', 'UTC', strtotime('2026-11-24 11:59 UTC')) &&
    $parser->isStageDue($clock, '2 business days', 'UTC', strtotime('2026-11-24 12:00 UTC')), 'Remaining wait changed.');
$tracker->handleWebhookIncoming(array_replace($payload, ['chatwootMessageId' => 43]));
$check($get()->get('status') === 'Active', 'Rejected number re-paused the enrollment.');
$active = $get();
$active->set('whatsAppChatwootConversationId', '778');
$em->saveEntity($active, ['skipHooks' => true]);
$tracker->handleWebhookIncoming(array_replace($payload, ['chatwootConversationId' => '778', 'chatwootMessageId' => 44, 'replyPhone' => $good]));
$check($get()->get('status') === 'Paused', 'New valid reply did not pause again.');
$decide('end');
$check($get()->get('status') === 'Exited' && $get()->get('currentStageId') === $exit->getId(), 'Configured exit did not run.');
$check(PeriodParser::weekdaySeconds(strtotime('2026-11-20 12:00 UTC'), strtotime('2026-11-23 12:00 UTC'), 'UTC')
    === 86400, 'Weekend incorrectly consumed business wait.');
echo "Passed: inbound webhook, pause, queued exit, wrong number, replacement validation, resume, recipients, history, second reply and end.\n";
