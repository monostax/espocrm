# Google Gemini Module Configuration

## KnowledgeBaseArticle File Search Integration

This module automatically indexes KnowledgeBaseArticle records to Google Gemini File Search Store when they are published.

### Configuration Steps

1. **Create a File Search Store** (if you don't have one)

```bash
curl -X POST "https://generativelanguage.googleapis.com/v1beta/fileSearchStores?key=${GEMINI_API_KEY}" \
    -H "Content-Type: application/json" \
    -d '{ "displayName": "EspoCRM Knowledge Base" }'
```

Save the returned `name` (e.g., `fileSearchStores/abc123xyz`).

2. **Configure EspoCRM**

Add the following to your EspoCRM configuration file (`data/config.php`):

```php
'googleGeminiApiKey' => 'your-gemini-api-key-here',
'googleGeminiFileSearchStoreName' => 'fileSearchStores/your-store-id-here',
```

Or use the EspoCRM Administration panel:
- Go to Administration > Settings
- Add custom settings via System > Custom Config

3. **Clear Cache**

```bash
php command.php clear-cache
```

### How It Works

When a KnowledgeBaseArticle is:
- **Created or Updated** with status "Published" → Automatically indexed to Gemini File Search Store
- **Deleted** → Logged (manual cleanup may be required)

The following data is indexed:
- Article name (as title)
- Article description
- Article body (plain text)

Metadata stored with each document:
- `article_id`: The EspoCRM record ID
- `article_name`: The article name
- `entity_type`: Always "KnowledgeBaseArticle"
- `language`: Article language (if set)

### Using File Search in Gemini

Once indexed, you can query your knowledge base using Gemini:

```php
$response = $client->models->generateContent([
    'model' => 'gemini-2.5-flash',
    'contents' => 'What is the refund policy?',
    'config' => [
        'tools' => [
            [
                'fileSearch' => [
                    'fileSearchStoreNames' => ['fileSearchStores/your-store-id']
                ]
            ]
        ]
    ]
]);
```

### Filtering by Metadata

You can filter searches by article properties:

```php
'fileSearch' => [
    'fileSearchStoreNames' => ['fileSearchStores/your-store-id'],
    'metadataFilter' => 'language="en_US"'
]
```

### Troubleshooting

Check the EspoCRM logs (`data/logs/`) for indexing errors:

```bash
grep "Gemini" data/logs/*.log
```

Common issues:
- **API key not configured**: Add `googleGeminiApiKey` to config
- **Store name not configured**: Add `googleGeminiFileSearchStoreName` to config
- **API rate limits**: Gemini has rate limits on indexing operations
- **Content too large**: Maximum file size per document is 100 MB

### Cost Considerations

From the Gemini File Search documentation:
- **Indexing**: $0.15 per 1M tokens (charged once during indexing)
- **Storage**: Free
- **Query embeddings**: Free
- **Retrieved tokens**: Charged as regular context tokens

Typical KnowledgeBaseArticle costs < $0.01 per article to index.

