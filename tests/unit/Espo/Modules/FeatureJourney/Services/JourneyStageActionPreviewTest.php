<?php

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureJourney\Controllers\JourneyStageAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Template previews say what is missing instead of a bare 500.
 */
class JourneyStageActionPreviewTest extends TestCase
{
    private function call(string $method, mixed ...$args): mixed
    {
        $class = new ReflectionClass(JourneyStageAction::class);
        $controller = $class->newInstanceWithoutConstructor();

        return $class->getMethod($method)->invoke($controller, ...$args);
    }

    public function testEmptyWhatsAppParametersAreNamedWithTheirExpression(): void
    {
        $this->assertSame(
            ['{{2}} = {{customFields.prospeccao.empresa_nome_amigavel}}'],
            $this->call(
                'emptyParameters',
                ['1' => 'Nayane', '2' => ''],
                ['1' => '{{Contact.firstName}}', '2' => '{{customFields.prospeccao.empresa_nome_amigavel}}'],
                '',
            )
        );
    }

    public function testLeftoverEmailPlaceholdersAreListedOnce(): void
    {
        $this->assertSame(
            ['{Account.customFields.prospeccao.empresa_nome_amigavel}', '{Account.customFields.prospeccao.empresa_artigo}'],
            $this->call(
                'unresolvedPlaceholders',
                'Posso te ligar? / {Account.customFields.prospeccao.empresa_nome_amigavel} | Oi Nayane, ' .
                'organograma d{Account.customFields.prospeccao.empresa_artigo}' .
                '{Account.customFields.prospeccao.empresa_nome_amigavel}. {sem ponto} {{1}}'
            )
        );
    }

    public function testPreviewErrorCarriesTheMessageInAJsonBody(): void
    {
        /** @var BadRequest $error */
        $error = $this->call('previewError', 'Sem valor para Nívia: {{2}}');

        $this->assertInstanceOf(BadRequest::class, $error);
        $this->assertSame(['message' => 'Sem valor para Nívia: {{2}}'], json_decode((string) $error->getBody(), true));
    }
}
