<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Entities;

use Espo\Core\ORM\Entity;

/**
 * @method ?string getName()
 * @method ?string getPageId()
 * @method ?string getPageAccessToken()       Encrypted ciphertext. Decrypt via Crypt.
 * @method ?bool   getIsActive()
 * @method ?bool   getSubscribedToLeadgen()
 * @method ?string getOAuthAccountId()
 */
class MetaFacebookPage extends Entity
{
    public const ENTITY_TYPE = 'MetaFacebookPage';
}
