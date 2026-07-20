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
 *
 * Fork of Espo\Tools\EmailTemplate\Processor with tenant custom-fields:
 * - Nest customFields before Htmlizer → {{customFields.address.city}}
 * - Classic → {Contact.customFields.address.city}
 * - Related one-hop → {Contact.account.customFields.x}
 ************************************************************************/

namespace Espo\Modules\Global\Tools\EmailTemplate;

use Espo\Core\Acl\GlobalRestriction;
use Espo\Core\AclManager;
use Espo\Core\Entities\Person;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Htmlizer\Htmlizer;
use Espo\Core\Htmlizer\HtmlizerFactory as HtmlizerFactory;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Templates\Entities\Person as PersonTemplate;
use Espo\Core\Utils\Config;
use Espo\Entities\Attachment;
use Espo\Entities\EmailAddress;
use Espo\Entities\EmailTemplate;
use Espo\Entities\User;
use Espo\Modules\Crm\Entities\Account;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Modules\Crm\Entities\Lead;
use Espo\Modules\Global\Tools\CustomField\TemplateBridge;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\EmailAddress as EmailAddressRepository;
use Espo\Tools\EmailTemplate\Data;
use Espo\Tools\EmailTemplate\EntityMapProvider;
use Espo\Tools\EmailTemplate\Formatter;
use Espo\Tools\EmailTemplate\Params;
use Espo\Tools\EmailTemplate\PlaceholdersProvider;
use Espo\Tools\EmailTemplate\Processor as CoreProcessor;
use Espo\Tools\EmailTemplate\Result;

use Exception;

/**
 * Extends core Processor so DI bindings remain type-compatible with
 * Espo\\Tools\\EmailTemplate\\Service / MassEmail SendingProcessor type-hints.
 *
 * Core methods are private, so customFields rendering is implemented here
 * (not parent::process). parent::__construct still warms the core instance.
 */
class Processor extends CoreProcessor
{
    private const KEY_PARENT = 'Parent';

    public function __construct(
        private Formatter $formatter,
        private EntityManager $entityManager,
        private AclManager $aclManager,
        private ServiceContainer $recordServiceContainer,
        private Config $config,
        private FileStorageManager $fileStorageManager,
        private User $user,
        private HtmlizerFactory $htmlizerFactory,
        private PlaceholdersProvider $placeholdersProvider,
        private EntityMapProvider $entityMapProvider,
        private TemplateBridge $templateBridge,
    ) {
        parent::__construct(
            $formatter,
            $entityManager,
            $aclManager,
            $recordServiceContainer,
            $config,
            $fileStorageManager,
            $user,
            $htmlizerFactory,
            $placeholdersProvider,
            $entityMapProvider,
        );
    }

    public function process(EmailTemplate $template, Params $params, Data $data): Result
    {
        $user = $data->getUser() ?? $this->user;

        [$entityHash, $data] = $this->prepare($data, $user, $params);

        $subject = $template->getSubject() ?? '';
        $body = $template->getBody() ?? '';

            try {
            $expanded = [];

            foreach ($entityHash as $entity) {
                if (!$entity instanceof Entity) {
                    continue;
                }

                $oid = spl_object_id($entity);

                if (isset($expanded[$oid])) {
                    continue;
                }

                $expanded[$oid] = true;
                $this->templateBridge->expandInPlace($entity);
                $this->expandRelatedCustomFields($entity, $user, !$params->applyAcl(), $expanded);
            }

            $parent = $entityHash[self::KEY_PARENT] ?? null;

            if ($parent && !$this->config->get('emailTemplateHtmlizerDisabled')) {
                $handlebarsInSubject = str_contains($subject, '{{') && str_contains($subject, '}}');
                $handlebarsInBody = str_contains($body, '{{') && str_contains($body, '}}');

                if ($handlebarsInSubject || $handlebarsInBody) {
                    $htmlizer = $this->createHtmlizer($params, $user);

                    if ($handlebarsInSubject) {
                        $subject = $htmlizer->render($parent, $subject);
                    }

                    if ($handlebarsInBody) {
                        $body = $htmlizer->render($parent, $body, null, false, true);
                    }
                }
            }

            foreach ($entityHash as $type => $entity) {
                $subject = $this->processText(
                    type: $type,
                    entity: $entity,
                    text: $subject,
                    user: $user,
                    skipAcl: !$params->applyAcl(),
                    isHtml: $template->isHtml(),
                );
            }

            foreach ($entityHash as $type => $entity) {
                $body = $this->processText(
                    type: $type,
                    entity: $entity,
                    text: $body,
                    user: $user,
                    skipAcl: !$params->applyAcl(),
                    isHtml: $template->isHtml(),
                );
            }

            // Classic custom-field leaves: {Contact.customFields.address.city}
            foreach ($entityHash as $type => $entity) {
                if (!$entity instanceof Entity) {
                    continue;
                }

                $subject = $this->templateBridge->applyClassic($subject, $entity, (string) $type);
                $body = $this->templateBridge->applyClassic($body, $entity, (string) $type);
            }
        } finally {
            $this->templateBridge->restoreExpanded();
        }

        $subject = $this->processPlaceholders($subject, $data);
        $body = $this->processPlaceholders($body, $data);

        $attachmentList = $params->copyAttachments() ?
            $this->copyAttachments($template) : [];

        return new Result(
            subject: $subject,
            body: $body,
            isHtml: $template->isHtml(),
            attachmentList: $attachmentList,
        );
    }

    private function processPlaceholders(string $text, Data $data): string
    {
        foreach ($this->placeholdersProvider->get() as [$key, $placeholder]) {
            $value = $placeholder->get($data);

            $text = str_replace('{' . $key . '}', $value, $text);
        }

        return $text;
    }

    private function processText(
        string $type,
        Entity $entity,
        string $text,
        User $user,
        bool $skipLinks = false,
        ?string $prefixLink = null,
        bool $skipAcl = false,
        bool $isHtml = true
    ): string {

        $attributeList = $entity->getAttributeList();

        $forbiddenAttributeList = [];

        if (!$skipAcl) {
            $forbiddenAttributeList = array_merge(
                $this->aclManager->getScopeForbiddenAttributeList($user, $entity->getEntityType()),
                $this->aclManager->getScopeRestrictedAttributeList(
                    $entity->getEntityType(),
                    [
                        GlobalRestriction::TYPE_FORBIDDEN,
                        GlobalRestriction::TYPE_INTERNAL,
                        GlobalRestriction::TYPE_ONLY_ADMIN,
                    ]
                )
            );
        }

        foreach ($attributeList as $attribute) {
            if (in_array($attribute, $forbiddenAttributeList)) {
                continue;
            }

            if (is_object($entity->get($attribute))) {
                continue;
            }

            if (!$entity->getAttributeType($attribute)) {
                continue;
            }

            $value = $this->formatter->formatAttributeValue($entity, $attribute, !$isHtml);

            if (is_null($value)) {
                continue;
            }

            $variableName = $attribute;

            if (!is_null($prefixLink)) {
                $variableName = "$prefixLink.$attribute";
            }

            $text = str_replace("{{$type}.$variableName}", $value, $text);
        }

        if (!$skipLinks && $entity->hasId()) {
            $text = $this->processLinks(
                type: $type,
                entity: $entity,
                text: $text,
                user: $user,
                skipAcl: $skipAcl,
                isHtml: $isHtml,
            );
        }

        return $text;
    }

    private function processLinks(
        string $type,
        Entity $entity,
        string $text,
        User $user,
        bool $skipAcl,
        bool $isHtml,
    ): string {

        $entityDefs = $this->entityManager->getDefs()->getEntity($entity->getEntityType());

        $forbiddenLinkList = $skipAcl ?
            $this->aclManager->getScopeRestrictedLinkList(
                $entity->getEntityType(),
                [
                    GlobalRestriction::TYPE_FORBIDDEN,
                    GlobalRestriction::TYPE_INTERNAL,
                    GlobalRestriction::TYPE_ONLY_ADMIN,
                ]
            ) :
            [];

        foreach ($entity->getRelationList() as $relation) {
            if (in_array($relation, $forbiddenLinkList)) {
                continue;
            }

            if (
                !in_array($entity->getRelationType($relation), [
                    Entity::BELONGS_TO,
                    Entity::BELONGS_TO_PARENT,
                    Entity::HAS_ONE,
                ])
            ) {
                continue;
            }

            if (
                !$skipAcl &&
                $entityDefs->hasField($relation) &&
                !$this->aclManager->checkField($user, $entity->getEntityType(), $relation)
            ) {
                continue;
            }

            $relatedEntity = $this->entityManager
                ->getRelation($entity, $relation)
                ->findOne();

            if (!$relatedEntity) {
                continue;
            }

            if (!$skipAcl) {
                try {
                    $hasAccess = $this->aclManager->checkEntityRead($user, $relatedEntity);
                } catch (Exception) {
                    continue;
                }

                if (!$hasAccess) {
                    continue;
                }
            }

            // Nest related bag for Handlebars link paths (e.g. {{account.customFields.x}}).
            $this->templateBridge->expandInPlace($relatedEntity);

            $text = $this->processText(
                type: $type,
                entity: $relatedEntity,
                text: $text,
                user: $user,
                skipLinks: true,
                prefixLink: $relation,
                skipAcl: $skipAcl,
                isHtml: $isHtml,
            );

            // Classic: {Contact.account.customFields.address.city}
            $text = $this->templateBridge->applyClassic(
                $text,
                $relatedEntity,
                $type . '.' . $relation
            );
        }

        return $text;
    }

    /**
     * Expand customFields on one-hop belongsTo / hasOne / belongsToParent targets
     * so Htmlizer can resolve {{account.customFields.x}} before classic replace.
     *
     * @param array<int, true> $expanded
     */
    private function expandRelatedCustomFields(
        Entity $entity,
        User $user,
        bool $skipAcl,
        array &$expanded,
    ): void {
        if (!$entity->hasId()) {
            return;
        }

        $entityDefs = $this->entityManager->getDefs()->getEntity($entity->getEntityType());

        $forbiddenLinkList = $skipAcl ?
            $this->aclManager->getScopeRestrictedLinkList(
                $entity->getEntityType(),
                [
                    GlobalRestriction::TYPE_FORBIDDEN,
                    GlobalRestriction::TYPE_INTERNAL,
                    GlobalRestriction::TYPE_ONLY_ADMIN,
                ]
            ) :
            [];

        foreach ($entity->getRelationList() as $relation) {
            if (in_array($relation, $forbiddenLinkList)) {
                continue;
            }

            if (
                !in_array($entity->getRelationType($relation), [
                    Entity::BELONGS_TO,
                    Entity::BELONGS_TO_PARENT,
                    Entity::HAS_ONE,
                ])
            ) {
                continue;
            }

            if (
                !$skipAcl &&
                $entityDefs->hasField($relation) &&
                !$this->aclManager->checkField($user, $entity->getEntityType(), $relation)
            ) {
                continue;
            }

            $relatedEntity = $this->entityManager
                ->getRelation($entity, $relation)
                ->findOne();

            if (!$relatedEntity) {
                continue;
            }

            if (!$skipAcl) {
                try {
                    $hasAccess = $this->aclManager->checkEntityRead($user, $relatedEntity);
                } catch (Exception) {
                    continue;
                }

                if (!$hasAccess) {
                    continue;
                }
            }

            $oid = spl_object_id($relatedEntity);

            if (isset($expanded[$oid])) {
                continue;
            }

            $expanded[$oid] = true;
            $this->templateBridge->expandInPlace($relatedEntity);
        }
    }

    /**
     * @return Attachment[]
     */
    private function copyAttachments(EmailTemplate $template): array
    {
        $copiedAttachments = [];

        /** @var iterable<Attachment> $attachments */
        $attachments = $this->entityManager
            ->getRelation($template, 'attachments')
            ->find();

        foreach ($attachments as $attachment) {
            $clone = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getNew();

            $data = $attachment->getValueMap();

            unset($data->parentType);
            unset($data->parentId);
            unset($data->id);

            $clone->set($data);
            $clone->setSourceId($attachment->getSourceId());
            $clone->setStorage($attachment->getStorage());

            if (!$this->fileStorageManager->exists($attachment)) {
                continue;
            }

            $this->entityManager->saveEntity($clone);

            $copiedAttachments[] = $clone;
        }

        return $copiedAttachments;
    }

    private function createHtmlizer(Params $params, User $user): Htmlizer
    {
        if (!$params->applyAcl()) {
            return $this->htmlizerFactory->createNoAcl();
        }

        return $this->htmlizerFactory->createForUser($user);
    }

    private function getEmailAddressRepository(): EmailAddressRepository
    {
        /** @var EmailAddressRepository */
        return $this->entityManager->getRepository(EmailAddress::ENTITY_TYPE);
    }

    /**
     * @return array{array<string, Entity>, Data}
     */
    private function prepare(Data $data, User $user, Params $params): array
    {
        $entityHash = $data->getEntityHash();

        if (!isset($entityHash[User::ENTITY_TYPE])) {
            $entityHash[User::ENTITY_TYPE] = $user;
        }

        $foundByAddressEntity = null;

        if ($data->getEmailAddress()) {
            $foundByAddressEntity = $this->getEmailAddressRepository()->getEntityByAddress(
                $data->getEmailAddress(),
                null,
                [
                    Contact::ENTITY_TYPE,
                    Lead::ENTITY_TYPE,
                    Account::ENTITY_TYPE,
                    User::ENTITY_TYPE,
                ]
            );

            if (
                $foundByAddressEntity &&
                $params->applyAcl() &&
                !$this->aclManager->checkEntityRead($this->user, $foundByAddressEntity)
            ) {
                $foundByAddressEntity = null;
            }
        }

        if ($foundByAddressEntity) {
            if ($foundByAddressEntity instanceof Person) {
                $entityHash[PersonTemplate::TEMPLATE_TYPE] = $foundByAddressEntity;
            }

            if (!isset($entityHash[$foundByAddressEntity->getEntityType()])) {
                $entityHash[$foundByAddressEntity->getEntityType()] = $foundByAddressEntity;
            }
        }

        if (
            !$data->getParent() &&
            $data->getParentId() &&
            $data->getParentType()
        ) {
            $parent = $this->entityManager->getEntityById($data->getParentType(), $data->getParentId());

            if ($parent) {
                $service = $this->recordServiceContainer->get($data->getParentType());

                $service->loadAdditionalFields($parent);

                if (
                    $params->applyAcl() &&
                    !$this->aclManager->checkEntityRead($this->user, $parent)
                ) {
                    $parent = null;
                }

                $data = $data->withParent($parent);
            }
        }

        if ($data->getParent()) {
            $parent = $data->getParent();

            $entityHash[$parent->getEntityType()] = $parent;
            $entityHash[self::KEY_PARENT] = $parent;

            if (
                !isset($entityHash[PersonTemplate::TEMPLATE_TYPE]) &&
                $parent instanceof Person
            ) {
                $entityHash[PersonTemplate::TEMPLATE_TYPE] = $parent;
            }
        }

        if ($data->getParent()) {
            $entityHash = array_merge(
                $entityHash,
                $this->entityMapProvider->get($data->getParent(), $user, $params->applyAcl())
            );

            $entityHash[$data->getParent()->getEntityType()] = $data->getParent();
        }

        if ($data->getRelatedId() && $data->getRelatedType()) {
            $related = $this->entityManager->getEntityById($data->getRelatedType(), $data->getRelatedId());

            if (
                $related &&
                $params->applyAcl() &&
                !$this->aclManager->checkEntityRead($this->user, $related)
            ) {
                $related = null;
            }

            if ($related) {
                $entityHash[$related->getEntityType()] = $related;
            }
        }

        return [$entityHash, $data];
    }
}
