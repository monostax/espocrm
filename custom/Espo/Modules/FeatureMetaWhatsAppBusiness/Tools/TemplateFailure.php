<?php

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Tools;

/** Safe diagnostics only: never carry an upstream response, token or template body. */
class TemplateFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly int $httpStatus = 502,
        public readonly ?int $metaCode = null,
        public readonly ?int $metaSubcode = null,
    ) {
        parent::__construct($message);
    }
}
