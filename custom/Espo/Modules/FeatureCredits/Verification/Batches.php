<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use Espo\Core\Exceptions\{Conflict, Forbidden};
use Espo\Modules\FeatureCredits\Accounting\{Clock, GrantInput, RecordId};
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;
use stdClass;

/** One confirmation persists the whole selection; the browser never admits individual checks. */
final class Batches
{
    public function __construct(private EntityManager $em, private Access $access, private Clock $clock) {}

    public function create(stdClass $input): array
    {
        $tenant = $input->tenantId ?? null;
        $contact = $input->contactId ?? null;
        $key = $input->requestKey ?? null;
        if (!is_string($tenant) || !is_string($contact) || !is_string($key) || !preg_match('/^[a-z0-9-]{1,64}$/D', $key) ||
            !is_array($input->items ?? null) || !$input->items || count($input->items) > 250 ||
            array_diff(array_keys((array) $input), ['tenantId','contactId','requestKey','items'])) {
            throw new InvalidArgumentException('Invalid bulk verification request.');
        }
        $items = array_map(self::item(...), $input->items);
        $kinds = array_unique(array_column($items, 'kind'));
        $family = in_array(Kind::EMAIL, $kinds, true) ? 'email' : 'phone';
        if ($family === 'email' && count($kinds) !== 1) throw new InvalidArgumentException('Mixed channel families.');
        if (!$this->access->isHumanActor()) throw new Forbidden();
        foreach ($kinds as $kind) $this->access->contact($tenant, $contact, $kind);
        $actor = $this->access->actorId();
        $commands = json_encode($items, JSON_THROW_ON_ERROR);
        $pdo = $this->em->getPDO();
        return $this->em->getTransactionManager()->run(function () use ($pdo,$actor,$key,$commands,$tenant,$contact,$family) {
            $q = $pdo->prepare('SELECT id FROM contact WHERE id=? AND tenant_id=? AND deleted=FALSE FOR UPDATE');
            $q->execute([$contact,$tenant]);
            if (!$q->fetchColumn()) throw new Forbidden();
            $q = $pdo->prepare('SELECT * FROM verification_batch WHERE tenant_id=? AND actor_id=? AND request_key=?');
            $q->execute([$tenant,$actor,$key]);
            if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($row['contact_id'] !== $contact || json_decode($row['commands'], true) !== json_decode($commands, true)) {
                    throw new Conflict('Bulk confirmation changed.');
                }
                return self::view($row);
            }
            $q = $pdo->prepare("SELECT id FROM verification_batch WHERE tenant_id=? AND contact_id=? AND actor_id=? AND family=? AND state='running' AND deleted=FALSE");
            $q->execute([$tenant,$contact,$actor,$family]);
            if ($q->fetchColumn()) throw new Conflict('A verification batch is already running.');
            $now = $this->clock->now();
            $id = RecordId::generate();
            $pdo->prepare("INSERT INTO verification_batch (id,tenant_id,contact_id,actor_id,request_key,family,commands,outcomes,state,next_at,created_at)
                VALUES (?,?,?,?,?,?,?,'[]','running',?,?)")->execute([$id,$tenant,$contact,$actor,$key,$family,$commands,$now,$now]);
            return ['id'=>$id,'state'=>'running','items'=>self::progress(json_decode($commands,true),[])];
        });
    }

    /** Return only the original user's latest batch on this Contact, without provider data or raw values. */
    public function current(array $params): ?array
    {
        $tenant = $params['tenantId'] ?? null; $contact = $params['contactId'] ?? null; $family = $params['family'] ?? null;
        if (!is_string($tenant) || !is_string($contact) || !in_array($family,['phone','email'],true)) throw new InvalidArgumentException();
        $this->access->contact($tenant,$contact,$family === 'email' ? Kind::EMAIL : Kind::WHATSAPP);
        $q = $this->em->getPDO()->prepare('SELECT * FROM verification_batch WHERE tenant_id=? AND contact_id=? AND actor_id=? AND family=? AND deleted=FALSE ORDER BY created_at DESC,id DESC LIMIT 1');
        $q->execute([$tenant,$contact,$this->access->actorId(),$family]);
        if (!$row = $q->fetch(PDO::FETCH_ASSOC)) return null;
        foreach (array_unique(array_column(json_decode($row['commands'], true), 'kind')) as $kind) $this->access->contact($tenant,$contact,$kind);
        return self::view($row);
    }

    private static function item(mixed $raw): array
    {
        if (!$raw instanceof stdClass || array_diff(array_keys((array)$raw), ['kind','source','contextRevision','maxChargeCredits']) ||
            !is_string($kind = $raw->kind ?? null) || !in_array($raw->maxChargeCredits ?? null,['0.0000',Kind::price($kind)],true) ||
            !is_string($rev = $raw->contextRevision ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$rev) ||
            !(($raw->source ?? null) instanceof stdClass)) throw new InvalidArgumentException('Invalid verification terms.');
        $source = (array)$raw->source;
        switch ($source['kind'] ?? null) {
            case 'purchased':
                if (array_diff(array_keys($source),['kind','receiptId','index']) || !is_string($source['receiptId'] ?? null) ||
                    !is_int($source['index'] ?? null) || $source['index'] < 0 || $source['index'] > 100) throw new InvalidArgumentException();
                GrantInput::identity($source['receiptId']);
                break;
            case 'channel':
                if (array_diff(array_keys($source),['kind','id']) || !is_string($source['id'] ?? null)) throw new InvalidArgumentException();
                GrantInput::identity($source['id']);
                break;
            default:
                if ($source !== ['kind' => $kind === Kind::EMAIL ? 'contact_email' : 'contact_phone']) throw new InvalidArgumentException();
        }
        ksort($source);
        return ['kind'=>$kind,'source'=>$source,'contextRevision'=>$rev,'maxChargeCredits'=>$raw->maxChargeCredits];
    }

    private static function view(array $row): array
    {
        return ['id'=>$row['id'],'state'=>$row['state'],'items'=>self::progress(json_decode($row['commands'],true),json_decode($row['outcomes'],true))];
    }

    private static function progress(array $commands, array $outcomes): array
    {
        return array_map(static fn ($i,$c) => ['kind'=>$c['kind'],'contextRevision'=>$c['contextRevision']] + ($outcomes[$i] ?? []),
            array_keys($commands),$commands);
    }
}
