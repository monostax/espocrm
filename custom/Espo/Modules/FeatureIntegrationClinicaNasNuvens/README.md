# FeatureIntegrationClinicaNasNuvens

## Faturamento (HTML)

- Fonte oficial: `GET https://app.clinicanasnuvens.com.br/lancamentos/detalhesConta?codigoDetalhe={faturamentoId}`
- O contrato de extração é baseado em seletores CSS explícitos definidos no escopo da feature.
- Esses seletores são a fonte de verdade da hidratação e devem ser tratados como contrato de integração.

## Credencial Web e Sessão

- Faturamento usa exclusivamente credencial web (`clinicaNasNuvens-web`).
- A leitura depende de cookie de sessão (`sessionCookies`) salvo no `config` da credencial.
- Se a resposta HTML indicar sessão inválida/autenticação/challenge:
    1. Executa o health-check form-auth existente (reautenticação centralizada)
    2. Recarrega cookies da credencial
    3. Reexecuta a requisição uma única vez

## Semântica dos IDs

- `faturamentoId`: âncora de request/local, vem do `codigoDetalhe`.
- `agendamentoId`: extraído do HTML (ex.: `#121148375`), normalizado sem `#` e usado para upsert/link de Agendamento.

## Procedimento Tipo (API)

- Endpoint oficial: `GET https://api.clinicanasnuvens.com.br/tipo-procedimento/{id}`
- Path de abertura no app web (botão "Abrir na Clínica Nas Nuvens"): `/procedimento/{id}`
- Campo âncora local: `procedimentoTipoId`
- Validação de criação: além de existir no remoto, o ID retornado pelo payload deve ser exatamente igual ao ID solicitado (comparação estrita de string).

## Precificação por Convênio do Procedimento (WEB)

- Fonte oficial: `GET https://app.clinicanasnuvens.com.br/procedimento/{id}?page=1&ativo=`
- O enriquecimento cria snapshot autoritativo em entidade filha `FeatureIntegrationClinicaNasNuvensProcedimentoConvenio
- Cada linha da tabela `#convenios` vira uma entidade filha com vínculo para `ProcedimentoTipo` e `ConvenioTipo`.
- O `ConvenioTipo` é sempre upsertado via API (`/tipo-convenio/{id}`) antes de persistir a linha filha.
- `convenioName` é persistido a partir de `ConvenioTipo.name` (fonte de verdade), não do texto cru do HTML.
- Campos de preço (`precoPaciente`, `precoConvenio`) preservam `0.00` versus `null` e sempre gravam moeda `BRL`.
- Snapshot autoritativo:
    - linhas presentes no scrape são upsertadas/restauradas,
    - linhas ausentes no scrape são soft-deleted.
- Telemetria de sync estruturada no log: `rowsUpserted`, `rowsSoftDeleted`, `convenioUpserted`, `errors`.

## Emparelhamento de Credenciais (API + WEB)

- O enriquecimento de `ProcedimentoTipo` exige emparelhar credenciais por contexto de time.
- Regra: escolher o primeiro time que tenha **ambas** credenciais acessíveis (API `cnn/clinicaNasNuvens` + WEB `clinicaNasNuvens-web`).
- Se não houver par no mesmo contexto de time:
    - sem credencial API: `syncStatus=error`;
    - API disponível e WEB ausente: hidratação API segue normal e sync de preço é ignorado com warning;
    - API e WEB disponíveis apenas em contextos divergentes: erro explícito para evitar contaminação cross-account.

## Convênio Tipo (API)

- Endpoint oficial: `GET https://api.clinicanasnuvens.com.br/tipo-convenio/{id}`
- Campo âncora local: `convenioTipoId`
- Validação de criação: além de existir no remoto, o ID retornado pelo payload deve ser exatamente igual ao ID solicitado (comparação estrita de string).
- Observação: o path web para botão "Abrir na Clínica Nas Nuvens" ainda não está confirmado para Convênio Tipo.

## Escopo Atual

- Incluído: hidratação de dados principais do faturamento, vínculo com Agendamento e hydrate imediato do Agendamento.
- Incluído: hidratação de Tipos de Procedimento e Tipos de Convênio via API, com persistência híbrida de campos storable.
- Fora de escopo (intencional): persistência de movimentos do faturamento.

