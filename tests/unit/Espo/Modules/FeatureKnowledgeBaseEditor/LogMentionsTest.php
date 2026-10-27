<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureKnowledgeBaseEditor;

use Espo\Entities\Note;
use Espo\Modules\FeatureKnowledgeBaseEditor\Hooks\Note\LogMentions;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\EditorReferences;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

class LogMentionsTest extends TestCase
{
    private function note(string $type, string $post, ?string $fetchedPost = null): Note
    {
        $note = new Note('Note', ['attributes' => array_fill_keys(['id', 'type', 'post'], ['type' => 'varchar'])]);
        if ($fetchedPost !== null) {
            $note->set(['id' => 'note', 'type' => $type, 'post' => $fetchedPost]);
            $note->setAsNotNew();
            $note->setAsFetched();
        }
        $note->set(['type' => $type, 'post' => $post]);
        return $note;
    }

    private function link(string $type, string $id): string
    {
        return "[$id](#crm-reference/v1/record/$type/$id)";
    }

    /** @return list<array<string, string>> refs passed to logMentions, one entry per call */
    private function logged(Note $note, array $options = []): array
    {
        $calls = [];
        $references = $this->createMock(EditorReferences::class);
        $references->method('logMentions')->willReturnCallback(function (array $refs) use (&$calls) {
            $calls[] = array_map(fn ($ref) => $ref['entityType'] . '/' . $ref['recordId'], $refs);
        });
        (new LogMentions($references))->afterSave($note, SaveOptions::fromAssoc($options));
        return $calls;
    }

    public function testNewPostLogsEachRecordReferenceOnce(): void
    {
        $post = 'Ping ' . $this->link('User', 'u1') . ' about ' . $this->link('Opportunity', 'o1') . ' and ' .
            $this->link('User', 'u1') . ', see [docs](https://example.com)';
        $this->assertSame([['User/u1', 'Opportunity/o1']], $this->logged($this->note(Note::TYPE_POST, $post)));
    }

    public function testEditedPostLogsOnlyAddedReferences(): void
    {
        $note = $this->note(Note::TYPE_POST, $this->link('User', 'u1') . ' ' . $this->link('Contact', 'c1'),
            $this->link('User', 'u1'));
        $this->assertSame([['Contact/c1']], $this->logged($note));
    }

    public function testSilentSavesAndNonPostNotesAreIgnored(): void
    {
        $post = $this->link('User', 'u1');
        $this->assertSame([], $this->logged($this->note(Note::TYPE_POST, $post), ['silent' => true]));
        $this->assertSame([], $this->logged($this->note('Update', $post)));
        $this->assertSame([], $this->logged($this->note(Note::TYPE_POST, 'no references')));
    }
}
