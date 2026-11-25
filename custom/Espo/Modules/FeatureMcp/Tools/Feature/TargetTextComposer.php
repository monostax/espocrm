<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Feature;

use Espo\Core\Utils\Language;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\TextComposer;

/** The feature's "Target" column for the knowledge base and AI agent tools. */
class TargetTextComposer implements TextComposer
{
    private const array TARGETS = [
        KnowledgeBaseData::TYPE => 'KnowledgeBaseArticle',
        KnowledgeBaseEditData::TYPE => 'KnowledgeBaseArticle',
        AiAgentsData::TYPE => 'ChatwootAccountUserMembership',
        AiAgentsEditData::TYPE => 'ChatwootAccountUserMembership',
        ConversationsData::TYPE => 'ChatwootConversation',
        ConversationNotesData::TYPE => 'ChatwootConversation',
        ConversationRepliesData::TYPE => 'ChatwootConversation',
        ConversationActionsData::TYPE => 'ChatwootConversation',
    ];

    public function __construct(
        private Language $language,
    ) {}

    public function compose(Data $data): string
    {
        $scope = self::TARGETS[$data->getKey()] ?? null;

        return $scope ? $this->language->translateLabel($scope, 'scopeNamesPlural') : $data->getKey();
    }
}
