<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\GoogleGemini\Hooks\KnowledgeBaseArticle;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Utils\Log;
use Espo\Modules\GoogleGemini\Services\GeminiFileSearchService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\RemoveOptions;

/**
 * Hook to index KnowledgeBaseArticle to Google Gemini File Search Store.
 * 
 * @implements AfterSave<Entity>
 */
class IndexToGemini implements AfterSave, AfterRemove
{
    public static int $order = 20;

    public function __construct(
        private GeminiFileSearchService $geminiService,
        private Log $log
    ) {}

    /**
     * Index article to Gemini File Search Store after save.
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // Only index published articles
        if ($entity->get('status') !== 'Published') {
            $this->log->debug('Skipping Gemini indexing for non-published article: ' . $entity->getId());
            return;
        }

        // Skip if article is deleted
        if ($entity->get('deleted')) {
            return;
        }

        $articleId = $entity->getId();
        $name = $entity->get('name');
        $bodyPlain = $entity->get('bodyPlain') ?: strip_tags($entity->get('body') ?: '');
        $description = $entity->get('description') ?: '';
        
        // Build the content to index
        $content = "# {$name}\n\n";
        
        if ($description) {
            $content .= "{$description}\n\n";
        }
        
        $content .= $bodyPlain;

        // Prepare metadata
        $metadata = [
            [
                'key' => 'article_id',
                'stringValue' => $articleId
            ],
            [
                'key' => 'article_name',
                'stringValue' => $name
            ],
            [
                'key' => 'entity_type',
                'stringValue' => 'KnowledgeBaseArticle'
            ]
        ];

        // Add language if available
        if ($entity->get('language')) {
            $metadata[] = [
                'key' => 'language',
                'stringValue' => $entity->get('language')
            ];
        }

        // Upload to Gemini File Search Store
        $this->log->info('Indexing KnowledgeBaseArticle to Gemini: ' . $articleId);
        
        $operation = $this->geminiService->uploadToFileSearchStore(
            $content,
            'KB Article: ' . $name,
            $metadata
        );

        if ($operation) {
            $this->log->info('Successfully queued indexing for article: ' . $articleId);
            
            // Optionally wait for operation to complete (non-blocking in background)
            if (isset($operation['name'])) {
                // Store operation name if you want to track it
                $entity->set('geminiOperationName', $operation['name']);
            }
        } else {
            $this->log->error('Failed to index article to Gemini: ' . $articleId);
        }
    }

    /**
     * Remove article from Gemini File Search Store when deleted.
     */
    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        // If the article had a document in Gemini, we would delete it here
        // However, for simplicity, we'll just log it
        // In a production system, you'd want to store the Gemini document name
        // in the entity and delete it here
        
        $this->log->info('Article removed from EspoCRM: ' . $entity->getId() . 
                         '. Note: Document may still exist in Gemini File Search Store.');
    }
}

