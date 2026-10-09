<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Markdown\Markdown;
use Espo\Entities\User;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Recipient
{
    public function __construct(private EntityManager $em, private Access $access) {}

    /** Markdown is the saved projection. Quotes and code never select an agent. */
    public function resolve(User $user, Entity $session, string $post): Entity
    {
        if (strlen($post) > 2000000) throw new BadRequest('Post is too large.');
        $selected = (string) $session->get('aiAgentMembershipId');
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . Markdown::transform($post) . '</body></html>', LIBXML_NONET);
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//a[not(ancestor::blockquote) and not(ancestor::pre) and not(ancestor::code)]') as $link) {
                assert($link instanceof \DOMElement);
                $url = $link->getAttribute('href');
                $reference = References::fromUrl($url);
                $membership = null;
                if (($reference['entityType'] ?? null) === 'ChatwootAccountUserMembership') {
                    $membership = $this->em->getEntityById('ChatwootAccountUserMembership', $reference['recordId']);
                } elseif (preg_match('~^mention://user/(\d+)/~', $url, $match)) {
                    $membership = $this->em->getRDBRepository('ChatwootAccountUserMembership')->join('chatwootUser')->where([
                        'chatwootAccountId' => $session->get('chatwootAccountId'),
                        'chatwootUser.chatwootUserId' => (int) $match[1], 'isAI' => true,
                    ])->findOne();
                }
                if (!$membership?->get('isAI')) continue;
                // Explicit invalid AI references fail closed rather than silently rerouting.
                $this->access->agent($user, $session, $membership->getId());
                $selected = $membership->getId();
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $this->access->agent($user, $session, $selected);
    }
}
