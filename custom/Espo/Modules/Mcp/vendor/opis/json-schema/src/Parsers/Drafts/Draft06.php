<?php

/* ============================================================================
 * Copyright 2020 Zindex Software
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */
namespace Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Drafts;

use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Draft;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\KeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\AdditionalItemsKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\AdditionalPropertiesKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\AllOfKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\AnyOfKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\ConstKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\ContainsKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\ContentEncodingKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\ContentMediaTypeKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\DefaultKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\DependenciesKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\EnumKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\ExclusiveMaximumKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\ExclusiveMinimumKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\FormatKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\ItemsKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\MaximumKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\MaxItemsKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\MaxLengthKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\MaxPropertiesKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\MinimumKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\MinItemsKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\MinLengthKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\MinPropertiesKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\MultipleOfKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\NotKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\OneOfKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\PatternKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\PatternPropertiesKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\PropertiesKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\PropertyNamesKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\RefKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\RequiredKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\TypeKeywordParser;
use Espo\Modules\Mcp\Vendor\Opis\JsonSchema\Parsers\Keywords\UniqueItemsKeywordParser;
class Draft06 extends Draft
{
    /**
     * @inheritDoc
     */
    public function version(): string
    {
        return '06';
    }
    public function allowKeywordsAlongsideRef(): bool
    {
        return \false;
    }
    /**
     * @inheritDoc
     */
    public function supportsAnchorId(): bool
    {
        return \false;
    }
    /**
     * @inheritDoc
     */
    protected function getRefKeywordParser(): KeywordParser
    {
        return new RefKeywordParser('$ref');
    }
    /**
     * @inheritDoc
     */
    protected function getKeywordParsers(): array
    {
        return [
            // Generic
            new TypeKeywordParser('type'),
            new ConstKeywordParser('const'),
            new EnumKeywordParser('enum'),
            new FormatKeywordParser('format'),
            // String
            new MinLengthKeywordParser('minLength'),
            new MaxLengthKeywordParser('maxLength'),
            new PatternKeywordParser("pattern"),
            new ContentEncodingKeywordParser('contentEncoding'),
            new ContentMediaTypeKeywordParser('contentMediaType'),
            // Number
            new MinimumKeywordParser('minimum', 'exclusiveMinimum'),
            new MaximumKeywordParser('maximum', 'exclusiveMaximum'),
            new ExclusiveMinimumKeywordParser('exclusiveMinimum'),
            new ExclusiveMaximumKeywordParser('exclusiveMaximum'),
            new MultipleOfKeywordParser('multipleOf'),
            // Array
            new MinItemsKeywordParser('minItems'),
            new MaxItemsKeywordParser('maxItems'),
            new UniqueItemsKeywordParser('uniqueItems'),
            new ContainsKeywordParser('contains'),
            new ItemsKeywordParser('items'),
            new AdditionalItemsKeywordParser('additionalItems'),
            // Object
            new MinPropertiesKeywordParser('minProperties'),
            new MaxPropertiesKeywordParser('maxProperties'),
            new RequiredKeywordParser('required'),
            new DependenciesKeywordParser('dependencies'),
            new PropertyNamesKeywordParser('propertyNames'),
            new PropertiesKeywordParser('properties'),
            new PatternPropertiesKeywordParser('patternProperties'),
            new AdditionalPropertiesKeywordParser('additionalProperties'),
            // Conditionals
            new NotKeywordParser('not'),
            new AnyOfKeywordParser('anyOf'),
            new AllOfKeywordParser('allOf'),
            new OneOfKeywordParser('oneOf'),
            // Optional
            new DefaultKeywordParser('default'),
        ];
    }
}
