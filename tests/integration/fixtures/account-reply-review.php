<?php
/** Run from an isolated installed application; never sends a message. */
declare(strict_types=1);

use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureJourney\Services\{AccountReplyReview, ActionContext, JourneyCatchUp, JourneyTargetPeople,
    JourneyWhatsAppOutbound, JourneyWhatsAppReplyTracker, PeriodParser, TransitionEvaluator, TransitionExecutor};

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
$tenant = $make('Tenant', ['name' => 'Test', 'baseUserTeamId' => $make('Team', ['name' => 'Test team'])->getId()])->getId();
$t = ['tenantId' => $tenant];
$actor = $make('User', ['userName' => 'review' . bin2hex(random_bytes(4)), 'type' => 'admin',
    'isActive' => true, 'lastName' => 'Rep']);
$acl = $app->getContainer()->get('aclManager')->createUserAcl($em->getEntityById('User', $actor->getId()));
$a = $make('Account', $t + ['name' => 'Test account']);
$person = $make('Contact', $t + ['lastName' => 'Verified']);
$second = $make('Contact', $t + ['lastName' => 'Second']);
$good = '+5511987654321';
$bad = '+5511991234567';
$new = '+5511976543210';
$new2 = '+5511965432109';
$person->set('phoneNumberData', [(object) ['phoneNumber' => $good, 'primary' => true]]);
$em->saveEntity($person);
$second->set('phoneNumberData', [(object) ['phoneNumber' => $bad, 'primary' => true]]);
$em->saveEntity($second);
$em->getRelation($a, 'contacts')->relate($person);
$em->getRelation($a, 'contacts')->relate($second);
$j = $make('Journey', $t + ['name' => 'Review', 'status' => 'Active', 'targetEntityType' => 'Account',
    'reviewAccountWhatsAppReplies' => true, 'runAsUserId' => $actor->getId()]);
$base = $t + ['journeyId' => $j->getId()];
$d0 = $make('JourneyStage', $base + ['name' => 'D0', 'stageType' => 'Entry', 'isActive' => true, 'order' => 0]);
$d2 = $make('JourneyStage', $base + ['name' => 'D2', 'stageType' => 'Normal', 'isActive' => true, 'order' => 10]);
$exit = $make('JourneyStage', $base + ['name' => 'Reply', 'stageType' => 'Exit', 'isActive' => true, 'order' => 20]);
// The company already got D2's task: a catch-up must not create it again.
$make('JourneyStageAction', $t + ['stageId' => $d2->getId(), 'trigger' => 'OnEnter', 'type' => 'createTask',
    'isActive' => true, 'params' => (object) ['name' => 'Catch-up must skip me']]);
$cond = fn ($node) => json_decode(json_encode($node));
$step = $make('JourneyTransition', $base + ['fromStageId' => $d0->getId(), 'toStageId' => $d2->getId(), 'isActive' => true]);
$edge = $make('JourneyTransition', $base + ['fromStageId' => $d2->getId(), 'toStageId' => $exit->getId(),
    'eventCodes' => ['whatsapp_replied'], 'isActive' => true,
    'conditionsGroup' => $cond(['type' => 'currentSignal', 'code' => 'whatsapp_replied'])]);
$cw = $make('ChatwootAccount', $t + ['name' => 'Test', 'chatwootAccountId' => random_int(100, 100000000)]);
$integration = $make('ChatwootInboxIntegration', $t + ['name' => 'test integration', 'channelType' => 'whatsappCoexistence']);
$inbox = $make('ChatwootInbox', $t + ['name' => 'WA', 'chatwootAccountId' => $cw->getId(),
    'chatwootInboxId' => 17, 'chatwootInboxIntegrationId' => $integration->getId()]);
$atD2 = '2026-11-20 12:00:00';
$r = $make('JourneyRecord', $base + ['targetType' => 'Account', 'targetId' => $a->getId(), 'status' => 'Active',
    'currentStageId' => $d2->getId(), 'enteredStageAt' => $atD2, 'createdAt' => '2026-11-18 12:00:00',
    'whatsAppChatwootConversationId' => '777', 'whatsAppChatwootAccountId' => $cw->getId()]);
foreach ([[null, $d0], [$d0, $d2]] as [$from, $to]) {
    $make('JourneyRecordLog', $t + ['recordId' => $r->getId(), 'journeyId' => $j->getId(),
        'fromStageId' => $from?->getId(), 'toStageId' => $to->getId(), 'firedBy' => 'system']);
}
$get = fn () => $em->getEntityById('JourneyRecord', $r->getId());
$state = fn () => AccountReplyReview::state($get());
$tasks = fn () => $em->getRDBRepository('Task')->where(['name' => 'Catch-up must skip me'])->count();
$tracker = $f->create(JourneyWhatsAppReplyTracker::class);
$message = 42;
$reply = function (string $conversation, string $phone) use ($em, $get, $tracker, $cw, &$message) {
    $record = $get();
    $record->set('whatsAppChatwootConversationId', $conversation);
    $em->saveEntity($record, ['skipHooks' => true]);
    $tracker->handleWebhookIncoming(['espoAccountId' => $cw->getId(), 'inboxExternalId' => 17,
        'chatwootConversationId' => $conversation, 'chatwootMessageId' => ++$message, 'createdAt' => time(),
        'replyPhone' => $phone]);
};
$reply('777', $bad);
$check($get()->get('status') === 'Paused', 'Incoming correlated WhatsApp did not pause the enrollment.');
$exec = $f->create(TransitionExecutor::class);
$check(!$exec->execute($r->getId(), $edge->getId(), ['code' => 'whatsapp_replied']), 'Queued exit ran while paused.');
$review = $f->create(AccountReplyReview::class);
$decide = fn ($decision, $more = []) => $review->decide($r->getId(), (object) ($more +
    ['revision' => AccountReplyReview::summary($get())['revision'], 'decision' => $decision]), $actor->getId(),
    $acl, $f->create(JourneyTargetPeople::class), $f->create(JourneyWhatsAppOutbound::class), $exec);
$decide('wrongNumber', ['conversation' => $cw->getId() . ':777']);
$listed = $review->contacts($get(), $acl, $f->create(JourneyTargetPeople::class), $f->create(JourneyWhatsAppOutbound::class));
$check($listed['canAddNumber'] && $listed['contacts'][0]['contactId'] === $second->getId() &&
    $listed['contacts'][0]['wrongNumber'], 'The wrong number\'s contact is not offered first.');
foreach ([$good => 'a number another contact has', '+551140041234' => 'a landline', $bad => 'the wrong number'] as $phone => $why) {
    try {
        $decide('resume', ['phone' => $phone, 'contactId' => $second->getId()]);
        throw new RuntimeException("Started a catch-up for {$why}.");
    } catch (BadRequest) {}
}
$check($get()->get('status') === 'Paused', 'A rejected number did not stay paused.');

// New number: back to D0 for it alone; the others wait at D2.
$decide('resume', ['phone' => $new, 'contactId' => $second->getId()]);
$check($get()->get('status') === 'Active' && $get()->get('currentStageId') === $d0->getId(), 'The new number did not start from D0.');
$check(in_array($new, $f->create(JourneyWhatsAppOutbound::class)->resolvePhones($em->getEntityById('Contact', $second->getId())), true),
    'The new number was not added to the contact.');
$check(($state()['catchUp']['until'] ?? null) === $d2->getId() && ($state()['catchUp']['untilEnteredAt'] ?? null) === $atD2,
    'The catch-up does not remember where the others wait.');
// D0 already went to the new number; D2 is next, for it alone.
$check((new ActionContext($a, $get(), $d0, $j, 'OnEnter', [], $tenant))->recipients([$person, $second]) === [],
    'D0 would go out twice.');
$ctx = new ActionContext($a, $get(), $d2, $j, 'OnEnter', [], $tenant);
$check($ctx->recipients([$person, $second]) === [$second] && $ctx->phones([$bad, $good, $new]) === [$new], 'Wrong catch-up recipients.');
$check($em->getRDBRepository('JourneyRecordLog')->where(['recordId' => $r->getId(), 'fromStageId' => $d2->getId(),
    'toStageId' => $d0->getId()])->count() === 1, 'The restart was not logged.');

// The new number is wrong too: nobody is left to catch up, the others continue from D2.
$reply('779', $new);
$check($get()->get('status') === 'Paused', 'A reply during the catch-up did not pause.');
$decide('wrongNumber', ['conversation' => $cw->getId() . ':779']);
$check(JourneyCatchUp::abandoned($state()), 'The wrong new number is still catching up.');
$decide('resume');
$check($get()->get('status') === 'Active' && $get()->get('currentStageId') === $d2->getId() &&
    $get()->get('enteredStageAt') === $atD2 && !isset($state()['catchUp']), 'The others did not return to D2.');

// Another wrong number and a second new one: it walks D0 → D2, D2's task stays skipped, then everyone continues.
$reply('780', $good);
$decide('wrongNumber', ['conversation' => $cw->getId() . ':780']);
$decide('resume', ['phone' => $new2, 'contactId' => $person->getId()]);
$check($get()->get('currentStageId') === $d0->getId() && JourneyCatchUp::active($state()), 'The second number did not start from D0.');
$check($exec->execute($r->getId(), $step->getId()), 'The catch-up could not move to D2.');
$check($get()->get('currentStageId') === $d2->getId() && !isset($state()['catchUp']), 'Reaching D2 did not end the catch-up.');
$check($tasks() === 0, 'The catch-up created a task the company already had.');
$check($get()->get('status') === 'Active', 'The record is not active after the catch-up.');

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
$clock->set('replyReviewState', (object) ['heldStage' => $d2->getId(), 'heldEntered' => $clock->get('enteredStageAt'),
    'heldSeconds' => 3 * 86400, 'heldWeekdaySeconds' => 86400]);
$parser = new PeriodParser();
$check(!$parser->isStageDue($clock, '2 business days', 'UTC', strtotime('2026-11-24 11:59 UTC')) &&
    $parser->isStageDue($clock, '2 business days', 'UTC', strtotime('2026-11-24 12:00 UTC')), 'Remaining wait changed.');
$reply('781', $bad);
$check($get()->get('status') === 'Active', 'Rejected number re-paused the enrollment.');
$reply('782', $new2);
$check($get()->get('status') === 'Paused', 'New valid reply did not pause again.');
$decide('end');
$check($get()->get('status') === 'Exited' && $get()->get('currentStageId') === $exit->getId(), 'Configured exit did not run.');
$check(PeriodParser::weekdaySeconds(strtotime('2026-11-20 12:00 UTC'), strtotime('2026-11-23 12:00 UTC'), 'UTC')
    === 86400, 'Weekend incorrectly consumed business wait.');
// Control: outside a catch-up the same stage does create its task.
$plain = $make('JourneyRecord', $base + ['targetType' => 'Account', 'targetId' => $make('Account', $t + ['name' => 'Plain'])->getId(),
    'status' => 'Active', 'currentStageId' => $d0->getId(), 'enteredStageAt' => gmdate('Y-m-d H:i:s')]);
$check($exec->execute($plain->getId(), $step->getId()) && $tasks() === 1, 'D2 did not create its task outside a catch-up.');
echo "Passed: pause, wrong number, number validation, catch-up from D0, wrong new number back to D2, second number catch-up, history, second reply and end.\n";
