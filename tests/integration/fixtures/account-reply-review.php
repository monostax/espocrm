<?php
/** Run from an isolated installed application; never sends a message. */
declare(strict_types=1);

use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
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
$extra = '+5511954321098';
$new2 = '+5511965432109';
$person->set('phoneNumberData', [(object) ['phoneNumber' => $good, 'primary' => true], (object) ['phoneNumber' => $extra]]);
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
$numbers = function () use ($review, $get, $acl, $f): array {
    $listed = $review->contacts($get(), $acl, $f->create(JourneyTargetPeople::class), $f->create(JourneyWhatsAppOutbound::class));
    return array_column($listed['contacts'], 'numbers', 'contactId') + ['listed' => $listed];
};
$listed = $numbers();
$check($listed['listed']['canAddNumber'] && $listed['listed']['contacts'][0]['contactId'] === $second->getId() &&
    $listed['listed']['contacts'][0]['wrongNumber'], 'The wrong number\'s contact is not offered first.');
$check($listed[$second->getId()] === [] && array_column($listed[$person->getId()], 'phone') === [$good, $extra] &&
    array_unique(array_column($listed[$person->getId()], 'source')) === ['record'], 'The contacts\' own numbers are not listed.');
foreach ([[$good, 'manual', 'a number another contact has'], ['+551140041234', 'manual', 'a landline'], [$bad, 'manual', 'the wrong number'],
    [$good, 'enrichment', 'an enrichment number another contact has'], [$new, 'record', 'a number the contact does not have'],
    [$good, 'record', 'another contact\'s number']] as [$phone, $source, $why]) {
    try {
        $decide('resume', ['phone' => $phone, 'contactId' => $second->getId(), 'source' => $source]);
        throw new RuntimeException("Started a catch-up for {$why}.");
    } catch (BadRequest) {}
}
$check($get()->get('status') === 'Paused', 'A rejected number did not stay paused.');

// New number from enrichment: saved as a WhatsApp identity, back to D0 for it alone; the others wait at D2.
$decide('resume', ['phone' => $new, 'contactId' => $second->getId(), 'source' => 'enrichment']);
$check($get()->get('status') === 'Active' && $get()->get('currentStageId') === $d0->getId(), 'The new number did not start from D0.');
$check(in_array($new, $f->create(JourneyWhatsAppOutbound::class)->resolvePhones($em->getEntityById('Contact', $second->getId())), true),
    'The new number was not added to the contact.');
$check($em->getRDBRepository('ContactChannelIdentity')->where(['contactId' => $second->getId(), 'channelType' => 'whatsapp',
    'sourceId' => $new])->count() === 1, 'The enrichment number is not a WhatsApp channel identity.');
$check($numbers()[$second->getId()] === [['phone' => $new, 'source' => 'record', 'catchingUp' => true]],
    'The catching-up number is not marked.');
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

// Another wrong number, then two numbers of two contacts in one resume: one the contact already has
// (replayed, not saved again) and a typed one. They walk D0 → D2 together, D2's task stays skipped,
// then everyone continues.
$reply('780', $good);
$decide('wrongNumber', ['conversation' => $cw->getId() . ':780']);
$decide('resume', ['numbers' => [
    (object) ['phone' => $extra, 'contactId' => $person->getId(), 'source' => 'record'],
    (object) ['phone' => $new2, 'contactId' => $second->getId(), 'source' => 'manual'],
    (object) ['phone' => ' ' . $new2, 'contactId' => $person->getId(), 'source' => 'manual'],
]]);
$check($get()->get('currentStageId') === $d0->getId() && ($state()['catchUp']['phones'][$extra]['contactId'] ?? null) === $person->getId() &&
    ($state()['catchUp']['phones'][$new2]['contactId'] ?? null) === $second->getId() && count($state()['catchUp']['phones']) === 2,
    'The two numbers did not start from D0 together.');
$check(count($em->getRepository('PhoneNumber')->getPhoneNumberData($em->getEntityById('Contact', $person->getId()))) === 2,
    'Replaying a number the contact has changed its phones.');
$ctx = new ActionContext($a, $get(), $d2, $j, 'OnEnter', [], $tenant);
$check($ctx->recipients([$person, $second]) === [$person, $second] && $ctx->phones([$good, $extra, $new2]) === [$extra, $new2],
    'Both numbers do not get the catch-up.');
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
$reply('782', $extra);
$check($get()->get('status') === 'Paused', 'New valid reply did not pause again.');
$decide('end');
$check($get()->get('status') === 'Exited' && $get()->get('currentStageId') === $exit->getId(), 'Configured exit did not run.');
$check(PeriodParser::weekdaySeconds(strtotime('2026-11-20 12:00 UTC'), strtotime('2026-11-23 12:00 UTC'), 'UTC')
    === 86400, 'Weekend incorrectly consumed business wait.');
// Control: outside a catch-up the same stage does create its task.
$plain = $make('JourneyRecord', $base + ['targetType' => 'Account', 'targetId' => $make('Account', $t + ['name' => 'Plain'])->getId(),
    'status' => 'Active', 'currentStageId' => $d0->getId(), 'enteredStageAt' => gmdate('Y-m-d H:i:s')]);
$check($exec->execute($plain->getId(), $step->getId()) && $tasks() === 1, 'D2 did not create its task outside a catch-up.');
// Remove a contact at any time. Three contacts of another company, running at D2.
$co = $make('Account', $t + ['name' => 'Removal']);
[$ana, $bruno, $carla] = array_map(function ($name, $phone) use ($make, $t, $em, $co) {
    $c = $make('Contact', $t + ['lastName' => $name]);
    $c->set('phoneNumberData', [(object) ['phoneNumber' => $phone, 'primary' => true]]);
    $em->saveEntity($c);
    $em->getRelation($co, 'contacts')->relate($c);
    return $em->getEntityById('Contact', $c->getId());
}, ['Ana', 'Bruno', 'Carla'], ['+5511943210987', '+5511932109876', '+5511921098765']);
$rr = $make('JourneyRecord', $base + ['targetType' => 'Account', 'targetId' => $co->getId(), 'status' => 'Active',
    'currentStageId' => $d2->getId(), 'enteredStageAt' => $atD2, 'createdAt' => '2026-11-18 12:00:00',
    'whatsAppChatwootAccountId' => $cw->getId()]);
foreach ([[null, $d0], [$d0, $d2]] as [$from, $to]) {
    $make('JourneyRecordLog', $t + ['recordId' => $rr->getId(), 'journeyId' => $j->getId(),
        'fromStageId' => $from?->getId(), 'toStageId' => $to->getId(), 'firedBy' => 'system']);
}
$getR = fn () => $em->getEntityById('JourneyRecord', $rr->getId());
$decideR = fn ($decision, $more = []) => $review->decide($rr->getId(), (object) ($more +
    ['revision' => AccountReplyReview::summary($getR())['revision'], 'decision' => $decision]), $actor->getId(),
    $acl, $f->create(JourneyTargetPeople::class), $f->create(JourneyWhatsAppOutbound::class), $exec);
$replyR = function (string $conversation, string $phone) use ($em, $getR, $tracker, $cw, &$message) {
    $record = $getR();
    $record->set('whatsAppChatwootConversationId', $conversation);
    $em->saveEntity($record, ['skipHooks' => true]);
    $tracker->handleWebhookIncoming(['espoAccountId' => $cw->getId(), 'inboxExternalId' => 17,
        'chatwootConversationId' => $conversation, 'chatwootMessageId' => ++$message, 'createdAt' => time(),
        'replyPhone' => $phone]);
};
$listR = fn () => $review->contacts($getR(), $acl, $f->create(JourneyTargetPeople::class), $f->create(JourneyWhatsAppOutbound::class));

// While running: Bruno gets nothing more and his replies don't pause the company.
$decideR('removeContact', ['contactId' => $bruno->getId()]);
$check($getR()->get('status') === 'Active', 'Removing a contact paused or stopped the cadence.');
$check((new ActionContext($co, $getR(), $d2, $j, 'OnEnter', [], $tenant))->recipients([$ana, $bruno, $carla]) === [$ana, $carla],
    'A removed contact still receives.');
$replyR('790', '+5511932109876');
$check($getR()->get('status') === 'Active', 'A removed contact\'s reply paused the cadence.');
$sorted = static function (array $ids): array {
    sort($ids);
    return $ids;
};
$check($sorted(array_column($listR()['contacts'], 'contactId')) === $sorted([$ana->getId(), $carla->getId()]) &&
    array_column($listR()['removedContacts'], 'contactId') === [$bruno->getId()], 'The removed contact is still listed.');

// Ana's reply waits for review; removing her drops it, and she can't get a new number.
$replyR('791', '+5511943210987');
$check($getR()->get('status') === 'Paused' &&
    ($listR()['pendingContacts'][$cw->getId() . ':791']['contactId'] ?? null) === $ana->getId(), 'The reply is not tied to Ana.');
$decideR('removeContact', ['contactId' => $ana->getId()]);
$check(AccountReplyReview::summary($getR())['pending'] === [] && $getR()->get('status') === 'Paused',
    'Removing the replying contact did not drop her reply.');
try {
    $decideR('resume', ['phone' => '+5511910987654', 'contactId' => $ana->getId(), 'source' => 'manual']);
    throw new RuntimeException('A removed contact got a new number.');
} catch (BadRequest) {}

// Carla gets a new number from D0; removing her while it catches up sends the run back to D2.
$decideR('resume', ['phone' => '+5511910987654', 'contactId' => $carla->getId(), 'source' => 'manual']);
$check($getR()->get('status') === 'Active' && $getR()->get('currentStageId') === $d0->getId(), 'Carla\'s number did not start from D0.');
$decideR('removeContact', ['contactId' => $carla->getId()]);
$check($getR()->get('status') === 'Active' && $getR()->get('currentStageId') === $d2->getId() &&
    $getR()->get('enteredStageAt') === $atD2 && !isset(AccountReplyReview::state($getR())['catchUp']),
    'Removing the only contact catching up did not return the run to D2.');

// An ended cadence can't be changed.
$ended = $getR();
$ended->set('status', 'Exited');
$em->saveEntity($ended, ['skipHooks' => true]);
try {
    $decideR('removeContact', ['contactId' => $ana->getId()]);
    throw new RuntimeException('Removed a contact from an ended cadence.');
} catch (Conflict) {}

echo "Passed: pause, wrong number, numbers listed, number validation, enrichment number catch-up from D0, wrong new number back to D2, two numbers from two contacts in one resume, history, second reply and end, remove a contact at any time.\n";
