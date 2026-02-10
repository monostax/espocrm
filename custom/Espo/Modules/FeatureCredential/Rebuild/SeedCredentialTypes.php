<?php

namespace Espo\Modules\FeatureCredential\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed/update system credential types.
 * Runs automatically during system rebuild.
 */
class SeedCredentialTypes implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureCredential Module: Starting to seed/update credential types...');

        // Load credential type definitions from data file
        $credentialTypes = $this->getCredentialTypeDefinitions();

        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($credentialTypes as $typeConfig) {
            $result = $this->seedCredentialType($typeConfig);
            
            switch ($result) {
                case 'created':
                    $createdCount++;
                    break;
                case 'updated':
                    $updatedCount++;
                    break;
                default:
                    $skippedCount++;
                    break;
            }
        }

        $this->log->info("FeatureCredential Module: Credential types seeding completed. Created: {$createdCount}, Updated: {$updatedCount}, Skipped: {$skippedCount}");
    }

    private function seedCredentialType(array $config): string
    {
        $code = $config['code'];
        
        // Check if credential type already exists
        $existing = $this->entityManager
            ->getRepository('CredentialType')
            ->where(['code' => $code])
            ->findOne();

        if ($existing) {
            // Update if system type (allow updating schema/uiConfig)
            if ($existing->get('isSystem')) {
                $existing->set([
                    'name' => $config['name'],
                    'category' => $config['category'],
                    'description' => $config['description'] ?? null,
                    'schema' => $config['schema'],
                    'uiConfig' => $config['uiConfig'] ?? null,
                    'tokenFieldMapping' => $config['tokenFieldMapping'] ?? null,
                    'healthCheckConfig' => $config['healthCheckConfig'] ?? null,
                    'encryptionFields' => $config['encryptionFields'] ?? '[]',
                    'requiresRotation' => $config['requiresRotation'] ?? false,
                    'rotationDays' => $config['rotationDays'] ?? 90,
                ]);
                
                $this->entityManager->saveEntity($existing);
                $this->log->info("FeatureCredential Module: Updated credential type '{$code}'");
                return 'updated';
            }
            
            $this->log->debug("FeatureCredential Module: Skipped credential type '{$code}' (not a system type)");
            return 'skipped';
        }

        // Create new credential type
        $credentialType = $this->entityManager->getEntity('CredentialType');
        $credentialType->set([
            'name' => $config['name'],
            'code' => $config['code'],
            'category' => $config['category'],
            'description' => $config['description'] ?? null,
            'schema' => $config['schema'],
            'uiConfig' => $config['uiConfig'] ?? null,
            'tokenFieldMapping' => $config['tokenFieldMapping'] ?? null,
            'healthCheckConfig' => $config['healthCheckConfig'] ?? null,
            'encryptionFields' => $config['encryptionFields'] ?? '[]',
            'requiresRotation' => $config['requiresRotation'] ?? false,
            'rotationDays' => $config['rotationDays'] ?? 90,
            'isSystem' => $config['isSystem'] ?? false,
        ]);

        $this->entityManager->saveEntity($credentialType);
        $this->log->info("FeatureCredential Module: Created credential type '{$code}'");
        
        return 'created';
    }

    private function getCredentialTypeDefinitions(): array
    {
        return [
            [
                'name' => 'Username / Password',
                'code' => 'basicAuth',
                'category' => 'basicAuth',
                'description' => 'Basic username and password authentication for services that support HTTP Basic Auth',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'username' => ['type' => 'string', 'title' => 'Username'],
                        'password' => ['type' => 'string', 'title' => 'Password'],
                        'domain' => ['type' => 'string', 'title' => 'Domain (optional)']
                    ],
                    'required' => ['username', 'password']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'username', 'type' => 'text', 'label' => 'Username'],
                        ['name' => 'password', 'type' => 'password', 'label' => 'Password'],
                        ['name' => 'domain', 'type' => 'text', 'label' => 'Domain']
                    ]
                ]),
                'encryptionFields' => json_encode(['password']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true
            ],
            [
                'name' => 'OAuth 2.0',
                'code' => 'oauth2',
                'category' => 'oauth2',
                'description' => 'OAuth 2.0 credentials with access token, refresh token, and client credentials. Link to an OAuth Provider and OAuth Account for automatic token refresh.',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'accessToken' => ['type' => 'string', 'title' => 'Access Token', 'source' => 'oauth'],
                        'refreshToken' => ['type' => 'string', 'title' => 'Refresh Token', 'source' => 'oauth'],
                        'clientId' => ['type' => 'string', 'title' => 'Client ID'],
                        'clientSecret' => ['type' => 'string', 'title' => 'Client Secret'],
                        'tokenEndpoint' => ['type' => 'string', 'title' => 'Token Endpoint'],
                        'scope' => ['type' => 'string', 'title' => 'Scope'],
                        'expiresAt' => ['type' => 'string', 'title' => 'Token Expires At', 'format' => 'date-time', 'source' => 'oauth']
                    ],
                    'required' => ['accessToken']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'clientId', 'type' => 'text', 'label' => 'Client ID'],
                        ['name' => 'clientSecret', 'type' => 'password', 'label' => 'Client Secret'],
                        ['name' => 'accessToken', 'type' => 'textarea', 'label' => 'Access Token'],
                        ['name' => 'refreshToken', 'type' => 'textarea', 'label' => 'Refresh Token'],
                        ['name' => 'tokenEndpoint', 'type' => 'text', 'label' => 'Token Endpoint'],
                        ['name' => 'scope', 'type' => 'text', 'label' => 'Scope']
                    ]
                ]),
                'tokenFieldMapping' => json_encode([
                    'accessToken' => 'access_token',
                    'refreshToken' => 'refresh_token',
                    'expiresAt' => 'expires_at',
                ]),
                'encryptionFields' => json_encode(['accessToken', 'refreshToken', 'clientSecret']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true
            ],
            [
                'name' => 'API Key',
                'code' => 'apiKey',
                'category' => 'apiKey',
                'description' => 'Simple API key authentication with optional key ID and custom header',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'keyId' => ['type' => 'string', 'title' => 'Key ID'],
                        'keySecret' => ['type' => 'string', 'title' => 'Key Secret'],
                        'headerName' => ['type' => 'string', 'title' => 'Header Name', 'default' => 'X-API-Key'],
                        'prefix' => ['type' => 'string', 'title' => 'Token Prefix', 'default' => '']
                    ],
                    'required' => ['keySecret']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'keyId', 'type' => 'text', 'label' => 'Key ID'],
                        ['name' => 'keySecret', 'type' => 'password', 'label' => 'Key Secret'],
                        ['name' => 'headerName', 'type' => 'text', 'label' => 'Header Name', 'default' => 'X-API-Key'],
                        ['name' => 'prefix', 'type' => 'text', 'label' => 'Token Prefix']
                    ]
                ]),
                'encryptionFields' => json_encode(['keySecret']),
                'requiresRotation' => false,
                'isSystem' => true
            ],
            [
                'name' => 'Key-Value Pairs',
                'code' => 'keyValue',
                'category' => 'keyValue',
                'description' => 'Generic key-value credential storage for custom authentication needs',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'pairs' => [
                            'type' => 'array',
                            'title' => 'Key-Value Pairs',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'key' => ['type' => 'string', 'title' => 'Key'],
                                    'value' => ['type' => 'string', 'title' => 'Value'],
                                    'isEncrypted' => ['type' => 'boolean', 'title' => 'Encrypt Value', 'default' => false]
                                ]
                            ]
                        ]
                    ]
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'pairs', 'type' => 'array', 'label' => 'Key-Value Pairs']
                    ]
                ]),
                'encryptionFields' => json_encode([]),
                'requiresRotation' => false,
                'isSystem' => true
            ],
            [
                'name' => 'AWS Credentials',
                'code' => 'awsCredentials',
                'category' => 'awsCredentials',
                'description' => 'AWS Access Key and Secret Key for AWS API authentication',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'accessKeyId' => ['type' => 'string', 'title' => 'Access Key ID'],
                        'secretAccessKey' => ['type' => 'string', 'title' => 'Secret Access Key'],
                        'sessionToken' => ['type' => 'string', 'title' => 'Session Token (temporary credentials)'],
                        'region' => ['type' => 'string', 'title' => 'AWS Region'],
                        'roleArn' => ['type' => 'string', 'title' => 'Role ARN (for AssumeRole)'],
                        'externalId' => ['type' => 'string', 'title' => 'External ID']
                    ],
                    'required' => ['accessKeyId', 'secretAccessKey']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'accessKeyId', 'type' => 'text', 'label' => 'Access Key ID'],
                        ['name' => 'secretAccessKey', 'type' => 'password', 'label' => 'Secret Access Key'],
                        ['name' => 'sessionToken', 'type' => 'textarea', 'label' => 'Session Token'],
                        ['name' => 'region', 'type' => 'text', 'label' => 'Region'],
                        ['name' => 'roleArn', 'type' => 'text', 'label' => 'Role ARN'],
                        ['name' => 'externalId', 'type' => 'text', 'label' => 'External ID']
                    ]
                ]),
                'encryptionFields' => json_encode(['secretAccessKey', 'sessionToken']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true
            ],
            [
                'name' => 'GCP Service Account',
                'code' => 'gcpCredentials',
                'category' => 'gcpCredentials',
                'description' => 'Google Cloud Platform service account credentials',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'serviceAccountEmail' => ['type' => 'string', 'title' => 'Service Account Email'],
                        'privateKey' => ['type' => 'string', 'title' => 'Private Key'],
                        'projectId' => ['type' => 'string', 'title' => 'Project ID'],
                        'tokenUri' => ['type' => 'string', 'title' => 'Token URI']
                    ],
                    'required' => ['serviceAccountEmail', 'privateKey']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'serviceAccountEmail', 'type' => 'text', 'label' => 'Service Account Email'],
                        ['name' => 'privateKey', 'type' => 'textarea', 'label' => 'Private Key'],
                        ['name' => 'projectId', 'type' => 'text', 'label' => 'Project ID'],
                        ['name' => 'tokenUri', 'type' => 'text', 'label' => 'Token URI']
                    ]
                ]),
                'encryptionFields' => json_encode(['privateKey']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true
            ],
            [
                'name' => 'Azure Service Principal',
                'code' => 'azureCredentials',
                'category' => 'azureCredentials',
                'description' => 'Azure Active Directory service principal credentials',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'tenantId' => ['type' => 'string', 'title' => 'Tenant ID'],
                        'clientId' => ['type' => 'string', 'title' => 'Client ID (Application ID)'],
                        'clientSecret' => ['type' => 'string', 'title' => 'Client Secret'],
                        'subscriptionId' => ['type' => 'string', 'title' => 'Subscription ID']
                    ],
                    'required' => ['tenantId', 'clientId', 'clientSecret']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'tenantId', 'type' => 'text', 'label' => 'Tenant ID'],
                        ['name' => 'clientId', 'type' => 'text', 'label' => 'Client ID'],
                        ['name' => 'clientSecret', 'type' => 'password', 'label' => 'Client Secret'],
                        ['name' => 'subscriptionId', 'type' => 'text', 'label' => 'Subscription ID']
                    ]
                ]),
                'encryptionFields' => json_encode(['clientSecret']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true
            ],
            [
                'name' => 'X.509 Certificate',
                'code' => 'certificate',
                'category' => 'certificateBased',
                'description' => 'X.509 client certificate with private key for mutual TLS authentication',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'certificatePem' => ['type' => 'string', 'title' => 'Certificate (PEM)'],
                        'privateKeyPem' => ['type' => 'string', 'title' => 'Private Key (PEM)'],
                        'passphrase' => ['type' => 'string', 'title' => 'Private Key Passphrase'],
                        'caCertificate' => ['type' => 'string', 'title' => 'CA Certificate']
                    ],
                    'required' => ['certificatePem', 'privateKeyPem']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'certificatePem', 'type' => 'textarea', 'label' => 'Certificate (PEM)'],
                        ['name' => 'privateKeyPem', 'type' => 'textarea', 'label' => 'Private Key (PEM)'],
                        ['name' => 'passphrase', 'type' => 'password', 'label' => 'Passphrase'],
                        ['name' => 'caCertificate', 'type' => 'textarea', 'label' => 'CA Certificate']
                    ]
                ]),
                'encryptionFields' => json_encode(['privateKeyPem', 'passphrase']),
                'requiresRotation' => true,
                'rotationDays' => 365,
                'isSystem' => true
            ],
            [
                'name' => 'SSH Private Key',
                'code' => 'sshKey',
                'category' => 'sshKey',
                'description' => 'SSH private key for server authentication (RSA, ED25519, etc.)',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'privateKey' => ['type' => 'string', 'title' => 'Private Key'],
                        'publicKey' => ['type' => 'string', 'title' => 'Public Key'],
                        'passphrase' => ['type' => 'string', 'title' => 'Passphrase'],
                        'keyType' => ['type' => 'string', 'title' => 'Key Type', 'enum' => ['rsa', 'ed25519', 'ecdsa', 'dsa']]
                    ],
                    'required' => ['privateKey']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'privateKey', 'type' => 'textarea', 'label' => 'Private Key'],
                        ['name' => 'publicKey', 'type' => 'textarea', 'label' => 'Public Key'],
                        ['name' => 'passphrase', 'type' => 'password', 'label' => 'Passphrase'],
                        ['name' => 'keyType', 'type' => 'enum', 'label' => 'Key Type', 'options' => ['rsa' => 'RSA', 'ed25519' => 'ED25519', 'ecdsa' => 'ECDSA', 'dsa' => 'DSA']]
                    ]
                ]),
                'encryptionFields' => json_encode(['privateKey', 'passphrase']),
                'requiresRotation' => true,
                'rotationDays' => 365,
                'isSystem' => true
            ],
            [
                'name' => 'Database Connection',
                'code' => 'database',
                'category' => 'database',
                'description' => 'Database connection credentials (MySQL, PostgreSQL, MongoDB, etc.)',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'host' => ['type' => 'string', 'title' => 'Host'],
                        'port' => ['type' => 'integer', 'title' => 'Port'],
                        'database' => ['type' => 'string', 'title' => 'Database Name'],
                        'username' => ['type' => 'string', 'title' => 'Username'],
                        'password' => ['type' => 'string', 'title' => 'Password'],
                        'connectionString' => ['type' => 'string', 'title' => 'Connection String (alternative)'],
                        'sslMode' => ['type' => 'string', 'title' => 'SSL Mode']
                    ],
                    'required' => ['host', 'username', 'password']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'host', 'type' => 'text', 'label' => 'Host'],
                        ['name' => 'port', 'type' => 'int', 'label' => 'Port'],
                        ['name' => 'database', 'type' => 'text', 'label' => 'Database'],
                        ['name' => 'username', 'type' => 'text', 'label' => 'Username'],
                        ['name' => 'password', 'type' => 'password', 'label' => 'Password'],
                        ['name' => 'connectionString', 'type' => 'textarea', 'label' => 'Connection String'],
                        ['name' => 'sslMode', 'type' => 'text', 'label' => 'SSL Mode']
                    ]
                ]),
                'encryptionFields' => json_encode(['password']),
                'requiresRotation' => true,
                'rotationDays' => 180,
                'isSystem' => true
            ],
            [
                'name' => 'JWT Token',
                'code' => 'jwt',
                'category' => 'jwt',
                'description' => 'JSON Web Token for API authentication',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'token' => ['type' => 'string', 'title' => 'JWT Token'],
                        'algorithm' => ['type' => 'string', 'title' => 'Algorithm', 'enum' => ['RS256', 'RS384', 'RS512', 'HS256', 'HS384', 'HS512', 'ES256', 'ES384', 'ES512']],
                        'publicKey' => ['type' => 'string', 'title' => 'Public Key / Secret'],
                        'issuer' => ['type' => 'string', 'title' => 'Issuer (iss)'],
                        'audience' => ['type' => 'string', 'title' => 'Audience (aud)'],
                        'expiresAt' => ['type' => 'string', 'title' => 'Expiration', 'format' => 'date-time']
                    ],
                    'required' => ['token']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'token', 'type' => 'textarea', 'label' => 'JWT Token'],
                        ['name' => 'algorithm', 'type' => 'enum', 'label' => 'Algorithm', 'options' => ['RS256' => 'RS256', 'RS384' => 'RS384', 'RS512' => 'RS512', 'HS256' => 'HS256', 'HS384' => 'HS384', 'HS512' => 'HS512']],
                        ['name' => 'publicKey', 'type' => 'textarea', 'label' => 'Public Key / Secret'],
                        ['name' => 'issuer', 'type' => 'text', 'label' => 'Issuer'],
                        ['name' => 'audience', 'type' => 'text', 'label' => 'Audience']
                    ]
                ]),
                'encryptionFields' => json_encode(['token', 'publicKey']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true
            ],
            [
                'name' => 'WhatsApp Cloud API',
                'code' => 'whatsappCloudApi',
                'category' => 'oauth2',
                'description' => 'WhatsApp Cloud API credential linking a Meta OAuth Account to a specific WhatsApp Business Account. The access token is auto-managed via the linked OAuth Account. Phone number selection is done at the integration level.',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'accessToken' => ['type' => 'string', 'title' => 'Access Token', 'source' => 'oauth'],
                        'businessAccountId' => ['type' => 'string', 'title' => 'Business Account ID'],
                        'webhookVerifyToken' => ['type' => 'string', 'title' => 'Webhook Verify Token'],
                        'apiVersion' => ['type' => 'string', 'title' => 'API Version', 'default' => 'v21.0'],
                    ],
                    'required' => ['businessAccountId'],
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'businessAccountId', 'type' => 'text', 'label' => 'Business Account ID'],
                        ['name' => 'webhookVerifyToken', 'type' => 'password', 'label' => 'Webhook Verify Token'],
                        ['name' => 'apiVersion', 'type' => 'text', 'label' => 'API Version', 'default' => 'v21.0'],
                    ],
                ]),
                'tokenFieldMapping' => json_encode([
                    'accessToken' => 'access_token',
                ]),
                'encryptionFields' => json_encode(['webhookVerifyToken']),
                'requiresRotation' => false,
                'rotationDays' => 90,
                'isSystem' => true,
            ],
            [
                'name' => 'Custom',
                'code' => 'custom',
                'category' => 'custom',
                'description' => 'Custom credential type with flexible configuration',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'object', 'title' => 'Custom Data']
                    ],
                    'additionalProperties' => true
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'data', 'type' => 'json', 'label' => 'Configuration (JSON)']
                    ]
                ]),
                'encryptionFields' => json_encode([]),
                'requiresRotation' => false,
                'isSystem' => true
            ],
            [
                'name' => 'Web Session (Form Auth)',
                'code' => 'formAuth',
                'category' => 'custom',
                'description' => 'Form-based authentication for web services with session management. Supports automatic re-authentication and session cookie storage.',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'username' => ['type' => 'string', 'title' => 'Username/Email'],
                        'password' => ['type' => 'string', 'title' => 'Password'],
                        'loginUrl' => ['type' => 'string', 'title' => 'Login URL'],
                        'usernameField' => ['type' => 'string', 'title' => 'Username Field Name', 'default' => 'login'],
                        'passwordField' => ['type' => 'string', 'title' => 'Password Field Name', 'default' => 'senha'],
                        'additionalFields' => ['type' => 'object', 'title' => 'Additional Form Fields', 'additionalProperties' => true],
                        'testUrl' => ['type' => 'string', 'title' => 'Test URL for Health Check', 'default' => 'https://www.simplesagenda.com.br/agendamento.php'],
                        'sessionCookies' => ['type' => 'string', 'title' => 'Session Cookies (auto-managed)']
                    ],
                    'required' => ['username', 'password', 'loginUrl']
                ]),
                'uiConfig' => json_encode([
                    'fields' => [
                        ['name' => 'loginUrl', 'type' => 'text', 'label' => 'Login URL'],
                        ['name' => 'username', 'type' => 'text', 'label' => 'Username/Email'],
                        ['name' => 'password', 'type' => 'password', 'label' => 'Password'],
                        ['name' => 'usernameField', 'type' => 'text', 'label' => 'Username Field', 'default' => 'login'],
                        ['name' => 'passwordField', 'type' => 'text', 'label' => 'Password Field', 'default' => 'senha'],
                        ['name' => 'testUrl', 'type' => 'text', 'label' => 'Test URL', 'default' => 'https://www.simplesagenda.com.br/agendamento.php'],
                        ['name' => 'additionalFields', 'type' => 'json', 'label' => 'Additional Fields (JSON)'],
                        ['name' => 'sessionCookies', 'type' => 'textarea', 'label' => 'Session Cookies (auto-managed)']
                    ]
                ]),
                'encryptionFields' => json_encode(['password', 'sessionCookies']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true
            ]
        ];
    }
}
