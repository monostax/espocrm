<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;
use stdClass;

final class ApplicationInput
{
    public readonly array $data;

    public function __construct(stdClass $input)
    {
        if (array_diff(array_keys(get_object_vars($input)),['tenantId','purchaseId','createIfUnmatched','contactData','contactId']) ||
            !is_string($input->tenantId ?? null) || !is_string($input->purchaseId ?? null) ||
            (property_exists($input,'contactId') && !is_string($input->contactId)) ||
            !is_bool($input->createIfUnmatched ?? false) || !(($input->contactData ?? new stdClass()) instanceof stdClass)) {
            throw new InvalidArgumentException('Explicit purchased candidate selection required.');
        }
        GrantInput::identity($input->tenantId); GrantInput::identity($input->purchaseId);
        $fields = get_object_vars($input->contactData ?? new stdClass());
        if (isset($input->contactId)) {
            GrantInput::identity($input->contactId);
            if (($input->createIfUnmatched ?? false) || array_diff(array_keys($fields),['dateOfBirth'])) {
                throw new InvalidArgumentException('Existing Contact updates only accept a date of birth.');
            }
        }
        if (count($fields) > 30) throw new InvalidArgumentException('Too many Contact fields.');
        foreach ($fields as $field => $value) {
            if ($field === 'teamsIds' && is_array($value) && array_is_list($value) && count($value) <= 20) {
                foreach ($value as $id) { if (!is_string($id)) throw new InvalidArgumentException('Invalid team.'); GrantInput::identity($id); }
                continue;
            }
            if (!preg_match('/\A[a-z][a-zA-Z0-9]{0,63}\z/',$field) ||
                (!is_string($value) && !is_bool($value) && !is_int($value)) || (is_string($value) && mb_strlen($value) > 512)) {
                throw new InvalidArgumentException('Only scalar Contact form fields are supported.');
            }
            if ($field === 'dateOfBirth') {
                $date = is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/',$value) ?
                    \DateTimeImmutable::createFromFormat('!Y-m-d',$value) : false;
                if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('A valid date of birth is required.');
            }
        }
        ksort($fields);
        $this->data = ['tenantId' => $input->tenantId,'purchaseId' => $input->purchaseId,
            'createIfUnmatched' => $input->createIfUnmatched ?? false,'contactData' => $fields,
            ...(isset($input->contactId) ? ['contactId' => $input->contactId] : [])];
    }
}
