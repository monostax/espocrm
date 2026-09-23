<?php

namespace Espo\Modules\Chatwoot\Reports;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Tools\Report\GridType\Result;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use PDO;
use stdClass;

/**
 * One episode per recorded participant name, under the episode's strict ACL.
 * Source arrays preserve names containing commas. Never split display strings.
 * Totals sum participation; drill-down lists the distinct underlying episodes.
 */
abstract class AbstractEpisodeParticipation implements GridReport
{
    private const ENTITY_TYPE = 'ChatwootConversationEpisode';
    private const COLUMN = 'COUNT:id';
    private const ACCOUNT_GROUP = 'chatwootAccount';

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private Language $language,
    ) {}

    abstract protected function namesField(): string;
    abstract protected function groupField(): string;

    public function run(?WhereItem $where, ?User $user): Result
    {
        $params = SearchParams::create();
        if ($where) {
            $params = $params->withWhere($where);
        }
        $data = [];
        $accountTotals = [];
        $participantTotals = [];
        $participantNames = [];
        $total = 0;

        foreach ($this->episodeRows($params, $user) as $row) {
            foreach ($row['names'] as $name) {
                $key = hash('sha256', $name);
                $account = $row['accountId'];
                $data[$account][$key][self::COLUMN] = ($data[$account][$key][self::COLUMN] ?? 0) + 1;
                $accountTotals[$account][self::COLUMN] = ($accountTotals[$account][self::COLUMN] ?? 0) + 1;
                $participantTotals[$key] = ($participantTotals[$key] ?? 0) + 1;
                $participantNames[$key] = $name;
                $total++;
            }
        }

        $accounts = array_keys($accountTotals);
        $accountNames = $this->accountNames($accounts, $user);
        usort($accounts, fn ($a, $b) => strnatcasecmp($accountNames[$a], $accountNames[$b]));
        $participants = array_keys($participantNames);
        usort($participants, fn ($a, $b) => ($participantTotals[$b] <=> $participantTotals[$a])
            ?: strcmp($participantNames[$a], $participantNames[$b]));

        $result = new Result(
            entityType: self::ENTITY_TYPE,
            groupByList: [self::ACCOUNT_GROUP, $this->groupField()],
            columnList: [self::COLUMN],
            numericColumnList: [self::COLUMN],
            summaryColumnList: [self::COLUMN],
            aggregatedColumnList: [self::COLUMN],
            sums: (object) [self::COLUMN => $total],
            groupValueMap: [self::ACCOUNT_GROUP => $accountNames, $this->groupField() => $participantNames],
            columnNameMap: [self::COLUMN => $this->language->translateLabel('episodeParticipations', 'columnLabels', self::ENTITY_TYPE)],
            columnTypeMap: [self::COLUMN => 'int'],
            grouping: [$accounts, $participants],
            reportData: $this->objectTree($data),
        );
        $result->setGroup1Sums($this->objectTree($accountTotals));
        $result->setGroup1NonSummaryColumnList([]);
        $result->setGroup2NonSummaryColumnList([]);

        return $result;
    }

    public function runSubReport(SearchParams $searchParams, SubReportParams $subReportParams, ?User $user): ListResult
    {
        $index = $subReportParams->getGroupIndex();
        if (!in_array($index, [0, 1], true)) {
            throw new BadRequest('Invalid participation group index.');
        }
        $value = (string) $subReportParams->getGroupValue();
        $other = $subReportParams->hasGroupValue2() ? (string) $subReportParams->getGroupValue2() : null;
        [$account, $participant] = $index === 0 ? [$value, $other] : [$other, $value];

        $ids = [];
        // Evaluate membership before pagination. Runtime filters, including the
        // start-date cohort, are applied to both the membership and list queries.
        foreach ($this->episodeRows($searchParams, $user) as $row) {
            if ($account !== null && $row['accountId'] !== $account) {
                continue;
            }
            foreach ($row['names'] as $name) {
                if ($participant === null || hash('sha256', $name) === $participant) {
                    $ids[] = $row['id'];
                    break;
                }
            }
        }

        $query = $this->query($searchParams, $user)->where(['id' => $ids])->distinct();
        if (!$searchParams->getOrderBy()) {
            $query->order('startedAt', 'DESC')->order('id', 'ASC');
        }
        $repository = $this->entityManager->getRDBRepository(self::ENTITY_TYPE);
        $collection = $repository->clone($query->build())->find();
        $count = $repository->clone($query->build())->count();

        return new ListResult($collection, $count);
    }

    /** @return iterable<array{id: string, accountId: string, names: list<string>}> */
    private function episodeRows(SearchParams $params, ?User $user): iterable
    {
        $params = $params->withOffset(null)->withMaxSize(null)->withOrderBy(null)->withOrder(null)
            ->withSelect(['id', 'chatwootAccountId', $this->namesField()]);
        $query = $this->query($params, $user)->distinct()->order([]);
        $statement = $this->entityManager->getQueryExecutor()->execute($query->build());

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $names = json_decode($row[$this->namesField()] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
            yield [
                'id' => (string) $row['id'],
                'accountId' => (string) $row['chatwootAccountId'],
                'names' => array_values(array_unique(array_filter($names ?? [], fn ($name) => is_string($name) && trim($name) !== ''))),
            ];
        }
    }

    private function query(SearchParams $params, ?User $user): SelectBuilder
    {
        $builder = $this->selectBuilderFactory->create()->from(self::ENTITY_TYPE)
            ->withStrictAccessControl()->withSearchParams($params);
        if ($user) {
            $builder->forUser($user);
        }

        return $builder->buildQueryBuilder();
    }

    /** @param list<string> $ids @return array<string, string> */
    private function accountNames(array $ids, ?User $user): array
    {
        if (!$ids) {
            return [];
        }
        $names = array_fill_keys($ids, $this->language->translateLabel('restrictedAccount', 'labels', self::ENTITY_TYPE));
        $builder = $this->selectBuilderFactory->create()->from('ChatwootAccount')->withStrictAccessControl();
        if ($user) {
            $builder->forUser($user);
        }
        try {
            $query = $builder->buildQueryBuilder()->select(['id', 'name'])->where(['id' => $ids]);
            $statement = $this->entityManager->getQueryExecutor()->execute($query->build());
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $names[$row['id']] = (string) $row['name'];
            }
        } catch (Forbidden) {
            // Episode permission need not imply permission to read its account.
        }

        return $names;
    }

    private function objectTree(array $value): stdClass
    {
        return (object) array_map(fn ($item) => is_array($item) ? $this->objectTree($item) : $item, $value);
    }
}
