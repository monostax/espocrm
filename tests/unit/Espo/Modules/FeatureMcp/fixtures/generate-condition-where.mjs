// Expected where items for ConditionWhereTest, produced by the Chatwoot browser code itself so the PHP port is
// checked against the real thing. Regenerate after changing either side:
//   node generate-condition-where.mjs <path to components/chatwoot/source> > condition-where.json
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.argv[2];
const api = join(root, 'app/javascript/dashboard/api/crm');
const { createCrmWhereBuilder, buildOpportunityWhere } = await import(join(api, 'opportunityFilters.js'));

// contact.js and account.js import the HTTP client; read only their field maps and link-multiple lists.
const fieldMap = (file, name) => {
  const text = readFileSync(join(api, file), 'utf8');
  const map = text.match(new RegExp(`export const ${name} = (\\{[\\s\\S]*?\\n\\});`))[1];
  const ENRICHMENT_RESULTS = [];
  return eval(`(${map})`);
};
const multiple = (file, builder) => {
  const text = readFileSync(join(api, file), 'utf8');
  const list = text.match(new RegExp(`${builder} = createCrmWhereBuilder\\([A-Z_]+, (\\[[^\\]]*\\])`));
  return list ? eval(list[1]) : [];
};

const builders = {
  Contact: createCrmWhereBuilder(fieldMap('contact.js', 'CONTACT_FILTER_FIELDS'), multiple('contact.js', 'buildContactWhere')),
  Account: createCrmWhereBuilder(fieldMap('account.js', 'ACCOUNT_FILTER_FIELDS'), multiple('account.js', 'buildAccountWhere')),
  Opportunity: buildOpportunityWhere,
  Initiative: createCrmWhereBuilder(fieldMap('initiative.js', 'INITIATIVE_FILTER_FIELDS'), multiple('initiative.js', 'buildInitiativeWhere')),
};

const row = (attributeKey, filterOperator, values) => ({ attributeKey, filterOperator, values, queryOperator: 'and' });
const cases = {
  Contact: [
    [row('source', 'equal_to', [{ id: 'Partner', name: 'Parceiro' }])],
    [row('source', 'not_equal_to', [{ id: 'Partner', name: 'Parceiro' }, { id: 'Web Site', name: 'Site' }])],
    [row('account', 'equal_to', { id: 'acc1', name: 'Acme' })],
    [row('account', 'not_equal_to', { id: 'acc1', name: 'Acme' })],
    [row('tags', 'equal_to', { id: 'tag1', name: 'vip' })],
    [row('tags', 'not_equal_to', { id: 'tag1', name: 'vip' })],
    [row('tags', 'is_present', null), row('teams', 'is_not_present', null)],
    [row('name', 'is_present', null), row('description', 'is_not_present', null)],
    [row('account', 'is_present', null), row('createdBy', 'is_not_present', null)],
    [row('name', 'contains', 'silva'), row('cpf', 'does_not_contain', '000')],
    [row('hasLinkedin', 'equal_to', { id: true, name: 'Sim' })],
    [row('hasLinkedin', 'not_equal_to', { id: true, name: 'Sim' })],
    [row('createdAt', 'equal_to', '2026-11-01'), row('createdAt', 'is_greater_than', '2026-10-01')],
    [row('modifiedAt', 'is_less_than', '2026-11-20'), row('createdAt', 'not_equal_to', '2026-11-01')],
    [row('emailEnrichedAt', 'days_before', 30)],
    [row('emailEnrichmentResult', 'equal_to', [{ id: 'found', name: 'Encontrado' }])],
    [row('recordQuery', 'query', 'account.industry ~ "saas"')],
    [row('unknownField', 'equal_to', 'x'), row('name', 'equal_to', null), row('name', 'equal_to', '')],
  ],
  Account: [
    [row('type', 'equal_to', [{ id: 'Prospect', name: 'Prospecto' }]), row('recordQuery', 'query', 'journeyRecords.any(status="Completed")')],
    [row('industry', 'equal_to', 'SaaS'), row('industry', 'contains', 'tech')],
    [row('teams', 'equal_to', { id: 'team1', name: 'Vendas' })],
    [row('sociosEnrichedAt', 'is_greater_than', '2026-11-01'), row('sociosEnrichmentResult', 'not_equal_to', [{ id: 'none', name: 'Nada' }])],
  ],
  Opportunity: [
    [row('funnel', 'equal_to', { id: 'f1', name: 'Vendas' }), row('contacts', 'equal_to', { id: 'c1', name: 'Ana' })],
    [row('amountConverted', 'is_greater_than', 1000), row('probability', 'is_less_than', 50)],
    [row('closeDate', 'is_greater_than', '2026-12-01'), row('closeDate', 'equal_to', '2026-12-24')],
    [row('followupStatus', 'equal_to', [{ id: 'active', name: 'Ativo' }]), row('followupCount', 'equal_to', 2)],
    [row('chatwootInbox', 'equal_to', [12, 15])],
    [row('streamUpdatedAt', 'days_before', 7), row('description', 'is_present', null)],
  ],
  Initiative: [
    [row('initiativeType', 'equal_to', { id: 'it1', name: 'Onboarding' }), row('name', 'contains', 'Q4')],
    [row('assignedUser', 'is_not_present', null), row('createdAt', 'is_less_than', '2026-11-01')],
  ],
};

const timeZone = 'America/Sao_Paulo';
const out = [];
for (const [entityType, list] of Object.entries(cases)) {
  for (const conditions of list) {
    out.push({ entityType, conditions, where: builders[entityType](conditions, { timeZone }) });
  }
}
process.stdout.write(`${JSON.stringify(out, null, 2)}\n`);
