<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use Espo\Modules\FeatureLiveRecords\Subscription\Projection;
use PHPUnit\Framework\TestCase;

final class ProjectionTest extends TestCase
{
    public function testOnlyBoundedReadRoutesAreAdmitted(): void
    {
        foreach (['/Account', '/Account/one', '/Contact/action/inbox', '/ActivityInbox/counts',
            '/MeetingInbox', '/MeetingInbox/counts', '/MeetingInbox/metadata', '/MeetingInbox/options', '/MeetingInbox/one', '/MeetingInbox/one/stream',
            '/CallInbox', '/CallInbox/counts', '/CallInbox/metadata', '/CallInbox/options', '/CallInbox/one', '/CallInbox/one/stream',
            '/RecordKnowledge/relations', '/JourneyRelationships/Contact/one', '/Opportunity/one/stream'] as $path) {
            self::assertTrue(Projection::accepts($path), $path);
        }
        foreach (['/User', '/Admin', '/Account/action/massDelete', '/Account/action/stream', '/Lead',
            '/MeetingInbox/bulkReadState', '/MeetingInbox/one/posts', '/MeetingInbox/one/readState', '/MeetingInbox/Task/one',
            '/CallInbox/bulkReadState', '/CallInbox/one/posts', '/CallInbox/one/readState', '/CallInbox/Task/one', '/CallInbox/one/recurrence', '/CallInbox/action/stream',
            '/CallInbox/counts/stream', '/CallInbox/metadata/stream', '/CallInbox/options/stream', '/CallInbox/bulkReadState/stream',
            'https://example.test/Account', '//Account', '/Account/../User', '/Account%2fone'] as $path) {
            self::assertFalse(Projection::accepts($path), $path);
        }
    }

    public function testCanonicalHashPreservesMembershipOrderingAndValues(): void
    {
        self::assertSame(Projection::fingerprint('{"list":[],"total":0}'), Projection::fingerprint('{"total":0,"list":[]}'));
        self::assertNotSame(Projection::fingerprint('{"list":[],"total":0}'), Projection::fingerprint('{"list":[],"total":1}'));
        self::assertNotSame(Projection::fingerprint('["a","b"]'), Projection::fingerprint('["b","a"]'));
        self::assertNotSame(Projection::fingerprint('{"name":"A"}'), Projection::fingerprint('{"name":"a"}'));
    }

    public function testDescriptorCannotAddMethodsOrHeaders(): void
    {
        $data = ['path' => '/Account', 'query' => 'maxSize=20', 'fingerprint' => str_repeat('a', 64)];
        $encode = static fn ($value) => 'liveQuery.' . rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        self::assertSame($data, Projection::decode($encode($data)));
        $this->expectException(\InvalidArgumentException::class);
        Projection::decode($encode([...$data, 'method' => 'DELETE']));
    }
}
