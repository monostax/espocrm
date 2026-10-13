<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing\Asaas;

use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Billing\OfferTerms;
use Espo\Modules\Global\Tools\BrazilianIdentifier;
use InvalidArgumentException;

/** Exact immutable provider request. Build only from an authorized payer and stored CRM terms. */
final readonly class Command
{
    private function __construct(public string $kind, public array $body) {}

    public static function customer(#[\SensitiveParameter] array $profile, string $reference): self
    {
        self::reference($reference);
        $keys = ['name', 'cpfCnpj', 'email', 'notificationDisabled'];
        if (array_diff(array_keys($profile), $keys) || array_diff($keys, array_keys($profile)) ||
            !is_string($profile['name']) || trim($profile['name']) !== $profile['name'] || $profile['name'] === '' ||
            strlen($profile['name']) > 160 || preg_match('/[\x00-\x1f\x7f]/', $profile['name']) ||
            !is_string($profile['cpfCnpj']) || !preg_match('/^(?:[0-9]{11}|[0-9]{14})$/D', $profile['cpfCnpj']) ||
            !(BrazilianIdentifier::cpf($profile['cpfCnpj']) ?? BrazilianIdentifier::cnpj($profile['cpfCnpj'])) ||
            !is_bool($profile['notificationDisabled']) || ($profile['email'] !== null &&
                (!is_string($profile['email']) || strlen($profile['email']) > 254 || !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)))) {
            throw new InvalidArgumentException('Valid authorized payer name, CPF/CNPJ and notification choice required.');
        }
        $body = ['name' => $profile['name'], 'cpfCnpj' => $profile['cpfCnpj'],
            'notificationDisabled' => $profile['notificationDisabled'], 'externalReference' => $reference];
        if ($profile['email'] !== null) $body['email'] = strtolower($profile['email']);
        return new self('customer', $body);
    }

    public static function charge(OfferTerms $terms, string $customerId, string $reference, string $billingType, string $dueDate): self
    {
        self::externalId('customer', $customerId); self::reference($reference);
        GrantInput::timestamp($dueDate . ' 00:00:00');
        if ($terms->currency !== 'BRL' || !in_array($billingType, ['PIX', 'BOLETO', 'CREDIT_CARD', 'UNDEFINED'], true)) {
            throw new InvalidArgumentException('Asaas requires BRL and a supported payment method.');
        }
        $body = ['customer' => $customerId, 'billingType' => $billingType, 'value' => $terms->price,
            'externalReference' => $reference, 'description' => 'CRM credits ' . $terms->offerId . ' / ' . $terms->version,
            // No account-level late fees/discounts may silently alter the accepted price.
            'discount' => ['value' => 0, 'dueDateLimitDays' => 0, 'type' => 'FIXED'],
            'interest' => ['value' => 0], 'fine' => ['value' => 0, 'type' => 'FIXED']];
        if ($terms->operation === 'subscription') {
            $body['cycle'] = 'MONTHLY'; $body['nextDueDate'] = $dueDate;
            return new self('subscription', $body);
        }
        $body['dueDate'] = $dueDate;
        return new self('payment', $body);
    }

    public static function restore(string $kind, array $body): self
    {
        if (!in_array($kind, ['customer', 'payment', 'subscription'], true) || !is_string($body['externalReference'] ?? null)) {
            throw new InvalidArgumentException('Invalid stored Asaas command.');
        }
        self::reference($body['externalReference']);
        // Persistence verifies the complete command hash before this internal restoration.
        return new self($kind, $body);
    }

    public function path(): string { return '/' . match ($this->kind) { 'customer' => 'customers', 'payment' => 'payments', 'subscription' => 'subscriptions' }; }

    public function json(): string
    {
        $fields = [];
        foreach ($this->body as $key => $value) {
            if ($key === 'value' && (!is_string($value) || !preg_match('/^[0-9]{1,10}\.[0-9]{2}$/D', $value))) throw new InvalidArgumentException('Exact charge amount required.');
            $fields[] = json_encode($key, JSON_THROW_ON_ERROR) . ':' . ($key === 'value' ? $value : json_encode($value, JSON_THROW_ON_ERROR));
        }
        return '{' . implode(',', $fields) . '}';
    }

    public static function externalId(string $kind, string $id): void
    {
        $prefix = match ($kind) { 'customer' => 'cus_', 'payment' => 'pay_', 'subscription' => 'sub_', default => throw new InvalidArgumentException('Invalid Asaas object kind.') };
        if (!preg_match('/^' . $prefix . '[a-zA-Z0-9_-]{1,100}$/D', $id)) throw new InvalidArgumentException('Invalid Asaas object reference.');
    }

    private static function reference(string $reference): void
    {
        if (!preg_match('/^crm-credit-[a-f0-9]{17}$/D', $reference)) throw new InvalidArgumentException('Server creation reference required.');
    }
}
