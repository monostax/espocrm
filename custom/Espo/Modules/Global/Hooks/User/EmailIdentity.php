<?php

namespace Espo\Modules\Global\Hooks\User;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** The primary email is the login identity, for both API and ORM writes. */
class EmailIdentity implements SaveHook
{
    public static int $order = -10;

    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $this->process($entity);
    }

    public function process(Entity $entity): void
    {
        if (!$entity instanceof User || $entity->isApi() || $entity->isSystem()) {
            return;
        }

        $email = $entity->get('emailAddress');
        $data = $entity->get('emailAddressData');

        // The email field saver gives changed emailAddressData precedence over
        // the scalar. Match it, including a primary address that is not first.
        if (is_array($data) && $entity->isAttributeChanged('emailAddressData')) {
            $data = array_map(fn ($row) => (object) (array) $row, $data);
            $primary = null;
            foreach ($data as $row) {
                if (!empty($row->primary)) {
                    if ($primary !== null) {
                        throw new BadRequest('Only one primary email address is allowed.');
                    }
                    $primary = $row;
                }
            }
            $primary ??= $data[0] ?? null;
            $email = $primary->emailAddress ?? null;
            if ($primary) {
                $primary->primary = true;
                $primary->emailAddress = is_string($email) ? strtolower(trim($email)) : $email;
            }
            $entity->set('emailAddressData', $data);
        }

        // Legacy/bootstrap users without email can still have their password or
        // active flag maintained. Creation and identity edits require an email.
        if (!$email && !$entity->isNew() &&
            !$entity->isAttributeChanged('emailAddress') &&
            !$entity->isAttributeChanged('emailAddressData') &&
            !$entity->isAttributeChanged('userName') &&
            !$entity->isAttributeChanged('type')) {
            return;
        }

        $email = is_string($email) ? strtolower(trim($email)) : '';
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequest('A valid primary email address is required for the user login.');
        }

        $entity->set('emailAddress', $email);
        $entity->set('userName', $email);

        if (!$entity->isNew() && !$entity->isAttributeChanged('userName') &&
            !$entity->isAttributeChanged('emailAddress')) {
            return;
        }

        $where = ['OR' => [['userName' => $email], ['emailAddress' => $email]]];
        if ($entity->hasId()) {
            $where['id!='] = $entity->getId();
        }

        foreach ($this->entityManager->getRDBRepository('User')->where($where)->find() as $other) {
            // Email queries also match secondary addresses. Only primary emails
            // and actual usernames reserve a login, including inactive users.
            if (strtolower(trim((string) $other->get('userName'))) === $email ||
                (!$other->isApi() && !$other->isSystem() &&
                    strtolower(trim((string) $other->get('emailAddress'))) === $email)) {
                throw new Conflict('userNameExists');
            }
        }
    }
}
