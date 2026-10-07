<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureKnowledgeBaseEditor;

use Espo\Core\Utils\Language;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\ReferenceSearch;
use PHPUnit\Framework\TestCase;

class ReferenceSearchTest extends TestCase
{
    public function testNamesAndEntityQualifiers(): void
    {
        $language = $this->createMock(Language::class);
        $language->method('translateLabel')->willReturnCallback(fn ($type, $category) => match ($type) {
            'Document' => $category === 'scopeNames' ? 'Documento' : 'Documentos',
            'CustomProject' => 'Projeto especial',
            default => $type,
        });
        $search = new ReferenceSearch($language);
        $types = ['Document', 'Opportunity', 'Contact', 'CustomProject'];
        foreach ([
            ['Kibu', $types, ['Kibu']],
            ['Overview IA', $types, ['Overview', 'IA']],
            ['oportunidade nowle', ['Opportunity'], ['nowle']],
            ['NOWLE OPORTUNIDADES', ['Opportunity'], ['NOWLE']],
            ['manuella contato', ['Contact'], ['manuella']],
            ['contacts manuella silva', ['Contact'], ['manuella', 'silva']],
            ['documentos Kibu', ['Document'], ['Kibu']],
            ['projeto especial nowle', ['CustomProject'], ['nowle']],
            ['contactless', $types, ['contactless']],
            ['Contact', $types, ['Contact']],
            ['  ', $types, []],
        ] as [$query, $expectedTypes, $terms]) {
            $this->assertSame(['types' => $expectedTypes, 'terms' => $terms], $search->parse($query, $types), $query);
        }
        $this->assertSame(['types' => ['Document'], 'terms' => ['contato', 'Kibu']],
            $search->parse('contato Kibu', ['Document']));
    }

    public function testContainsPatternTreatsUserWildcardsLiterally(): void
    {
        $this->assertSame('%Kibu%', ReferenceSearch::pattern('Kibu'));
        $this->assertSame('%50\\%\\_off\\\\sale%', ReferenceSearch::pattern('50%_off\\sale'));
    }

    public function testUsesTheUsersTranslatedSingularAndPluralLabels(): void
    {
        foreach ([['Usuário', 'Usuários'], ['User', 'Users']] as [$singular, $plural]) {
            $language = $this->createMock(Language::class);
            $language->method('translateLabel')->willReturnCallback(
                fn ($type, $category) => $category === 'scopeNames' ? $singular : $plural);
            $search = new ReferenceSearch($language);
            foreach ([$singular, $plural] as $label) {
                $this->assertSame(['types' => ['User'], 'terms' => ['Manuella']],
                    $search->parse("$label Manuella", ['User']));
            }
            $this->assertSame(['types' => ['User'], 'terms' => ['Manuella']],
                $search->parse('User Manuella', ['User']));
        }
    }
}
