# IncomingWebhooks Module

## Overview

The IncomingWebhooks module provides a comprehensive system for receiving, storing, and processing webhooks from external systems. It includes automatic payload parsing, idempotency handling, header sanitization, and error tracking.

## Features

-   **Webhook Reception**: Public endpoints for receiving webhooks with or without API key authentication
-   **Automatic Parsing**: Intelligently extracts event types, webhook IDs, and signatures from various payload formats
-   **Idempotency**: Prevents duplicate webhook processing using webhook IDs
-   **Security**: Sanitizes sensitive headers (Authorization, API keys, cookies) before storage
-   **IP Tracking**: Records source IP addresses for security and debugging
-   **Status Tracking**: Monitors webhook processing status (Pending, Processed, Failed)
-   **Error Handling**: Captures and stores error messages for failed webhooks
-   **Retry Tracking**: Counts retry attempts for failed webhooks

## Module Structure

### Controllers

-   **IncomingWebhook**: Standard CRUD controller for managing webhook records
-   **WebhookReceiver**: Handles incoming webhook POST requests

### Services

-   **WebhookReceiver**: Core service for processing webhook payloads, with customizable event handlers

### Entity: IncomingWebhook

#### Fields

-   `name`: Auto-generated webhook name based on event type
-   `event`: Event type extracted from payload
-   `payload`: Complete JSON payload received
-   `headers`: HTTP headers (with sensitive data redacted)
-   `status`: Processing status (Pending/Processed/Failed)
-   `processedAt`: Timestamp when webhook was processed
-   `errorMessage`: Error details if processing failed
-   `webhookId`: Unique identifier for idempotency
-   `signature`: Digital signature or verification token
-   `retryCount`: Number of retry attempts
-   `sourceIp`: Source IP address of the request

## API Endpoints

### Webhook Reception Endpoints

```
POST /api/v1/webhook/receive
POST /api/v1/webhook/receive/:apiKey
```

Both endpoints accept JSON payloads and return:

**Success Response:**

```json
{
    "success": true,
    "id": "webhook-record-id",
    "message": "Webhook received and stored successfully"
}
```

**Error Response:**

```json
{
    "success": false,
    "error": "error details",
    "message": "Failed to process webhook"
}
```

## Webhook Processing

### Automatic Event Detection

The system automatically detects event types from common field names:

-   `type`
-   `event`
-   `event_type`
-   `action`
-   `trigger`

### Automatic Webhook ID Detection

For idempotency, the system looks for webhook IDs in:

-   `id`
-   `webhook_id`
-   `event_id`
-   `request_id`
-   `delivery_id`

### Signature Detection

Supports common webhook signature headers:

-   `X-Signature`
-   `X-Hub-Signature`
-   `X-Hub-Signature-256`
-   `X-Webhook-Signature`
-   `Stripe-Signature`
-   `X-GitHub-Event`

## Customization

### Adding Custom Processing Logic

Edit `Services/WebhookReceiver.php` and modify the `processGenericWebhook()` method:

```php
private function processGenericWebhook(Entity $webhook, object $payload): void
{
    $event = $webhook->get('event');

    switch ($event) {
        case 'user.created':
            // Your custom logic for user creation
            $this->handleUserCreated($payload);
            break;
        case 'order.completed':
            // Your custom logic for order completion
            $this->handleOrderCompleted($payload);
            break;
        default:
            // Default handling
            break;
    }
}
```

### Adding Async Processing

To integrate with EspoCRM's job system, modify the `queueWebhookProcessing()` method:

```php
private function queueWebhookProcessing(Entity $webhook): void
{
    $this->getServiceFactory()
        ->create('Job')
        ->schedule('ProcessWebhook', [
            'webhookId' => $webhook->getId()
        ]);
}
```

## Access Control

By default, the IncomingWebhook entity is:

-   **Read-only** for all users
-   **No create/edit/delete** permissions
-   Webhooks can only be created via the public API endpoints

## Admin Panel

The module adds an "Incoming Webhooks" menu item to the admin panel with:

-   Icon: `ti ti-webhook`
-   List view of all received webhooks
-   Detailed view showing payload, headers, and processing status
-   Filtering by status, event type, source IP, and dates

## Security Considerations

1. **Header Sanitization**: Sensitive headers are automatically redacted before storage
2. **IP Tracking**: Source IPs are recorded for security auditing
3. **No Auth Required**: Webhook endpoints bypass authentication (standard for webhooks)
4. **API Key Support**: Optional API key parameter for additional verification

## Migration Notes

This module was migrated from `Espo\Custom` to `Espo\Modules\IncomingWebhooks`. All namespaces have been updated accordingly.

## Version

-   **Version**: 1.0.0
-   **Module Order**: 15

## Dependencies

-   EspoCRM Core
-   Standard EspoCRM ORM
-   EspoCRM Services layer

## Support

For issues or enhancements, please contact the development team.

