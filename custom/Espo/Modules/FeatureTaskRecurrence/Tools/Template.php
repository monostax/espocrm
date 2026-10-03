<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\FieldUtil;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;

class Template
{
    private const REUSABLE = ['name', 'description', 'priority', 'assignedUser', 'teams', 'collaborators', 'tags', 'parent', 'reminders', 'attachments', 'chatwootConversations', 'dateStart', 'dateStartDate', 'dateEnd', 'dateEndDate'];

    public function __construct(private Metadata $metadata, private FieldUtil $fieldUtil) {}

    public function attributes(): array
    {
        $attributes = [];
        foreach ($this->metadata->get(['entityDefs', 'Task', 'fields']) ?? [] as $name => $defs) {
            if (!in_array($name, self::REUSABLE, true) && !($defs['isCustom'] ?? false)) continue;
            // Owned files cannot be reused by assigning their IDs to another Task.
            if (in_array($defs['type'] ?? '', ['file', 'image', 'attachmentMultiple'], true) && !($defs['recurrenceReusable'] ?? false)) continue;
            // Custom relationships need an explicit ownership contract, not scalar copying.
            if (($defs['isCustom'] ?? false) && in_array($defs['type'] ?? '', ['link', 'linkMultiple', 'linkParent'], true)) continue;
            if (str_starts_with($name, 'recurrence') || ($defs['readOnly'] ?? false) ||
                ($defs['recurrenceCopyDisabled'] ?? false) || ($defs['unique'] ?? false) ||
                (($defs['duplicateIgnore'] ?? false) && $name !== 'reminders') ||
                (($defs['notStorable'] ?? false) && $name !== 'reminders')) continue;
            foreach ($this->fieldUtil->getAttributeList('Task', $name) as $attribute) {
                if ($attribute !== $name && (str_ends_with($attribute, 'Name') || str_ends_with($attribute, 'Names') || str_ends_with($attribute, 'Columns'))) continue;
                $attributes[] = $attribute;
            }
        }
        return array_values(array_unique($attributes));
    }

    public function snapshot(Entity $task): object
    {
        $data = new \stdClass();
        foreach ($this->attributes() as $attribute) $data->$attribute = $task->get($attribute);
        return $data;
    }

    public function patch(object $input): object
    {
        return (object) array_intersect_key((array) $input, array_flip($this->attributes()));
    }

    /** Wall-calendar offsets, including mixed optional-date fields, never UTC-second durations. */
    public function dates(object $template, string $deadline, object $definition): object
    {
        $zone = new DateTimeZone($definition->timezone);
        if (empty($template->dateEnd) && empty($template->dateEndDate)) {
            return (object) ['dateEnd' => $definition->dateOnly ? null : $deadline, 'dateEndDate' => $definition->dateOnly ? $deadline : null,
                'dateStart' => $template->dateStart ?? null, 'dateStartDate' => $template->dateStartDate ?? null];
        }
        $old = !empty($template->dateEndDate)
            ? new DateTimeImmutable($template->dateEndDate, $zone)
            : new DateTimeImmutable($template->dateEnd, new DateTimeZone('UTC'));
        $old = $old->setTimezone($zone);
        $next = $definition->dateOnly ? new DateTimeImmutable($deadline, $zone)
            : (new DateTimeImmutable($deadline, new DateTimeZone('UTC')))->setTimezone($zone);
        $data = (object) ['dateEnd' => $definition->dateOnly ? null : $deadline, 'dateEndDate' => $definition->dateOnly ? $deadline : null,
            'dateStart' => null, 'dateStartDate' => null];
        $start = !empty($template->dateStartDate) ? new DateTimeImmutable($template->dateStartDate, $zone)
            : (!empty($template->dateStart) ? (new DateTimeImmutable($template->dateStart, new DateTimeZone('UTC')))->setTimezone($zone) : null);
        if (!$start) return $data;
        // Compare civil times in a fixed offset to avoid DST altering the saved duration.
        $civil = new DateTimeZone('UTC');
        $oldCivil = new DateTimeImmutable($old->format('Y-m-d H:i:s'), $civil);
        $startCivil = new DateTimeImmutable($start->format('Y-m-d H:i:s'), $civil);
        $nextCivil = new DateTimeImmutable($next->format('Y-m-d H:i:s'), $civil);
        $shifted = $nextCivil->sub($startCivil->diff($oldCivil));
        if (!empty($template->dateStartDate)) $data->dateStartDate = $shifted->format('Y-m-d');
        else $data->dateStart = (new DateTimeImmutable($shifted->format('Y-m-d H:i:s'), $zone))->setTimezone($civil)->format('Y-m-d H:i:s');
        return $data;
    }
}
