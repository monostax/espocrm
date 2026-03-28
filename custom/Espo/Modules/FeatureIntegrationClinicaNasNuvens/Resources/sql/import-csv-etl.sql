-- =============================================================================
-- CNN CSV → EspoCRM ETL Script (DuckDB)
-- =============================================================================
-- Parameterized via DuckDB SET VARIABLE:
--   csvInputPath   – path to CSV input directory (e.g. data/cnn-exports/{profileId}/latest)
--   csvOutputPath  – path for output CSVs (e.g. data/tmp/cnn-import-{profileId})
--   dateFrom       – filter start date (YYYY-MM-DD)
--   dateTo         – filter end date (YYYY-MM-DD)
--   credentialId   – API credential ID (varchar(17))
--   webCredentialId – Web credential ID (varchar(17))
--   settingsId     – Settings profile ID (varchar(17))
--   teamId         – Team ID (varchar(17))
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Helper macro: generate EspoCRM-compatible 17-char hex IDs
-- Format: 8 hex from epoch_sec + 5 hex from microseconds + 4 random hex
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO espo_id() AS (
    lpad(to_hex(epoch(now())::BIGINT), 8, '0')
    || lpad(to_hex((epoch_us(now()) % 1000000)::BIGINT), 5, '0')
    || substr(md5(random()::TEXT || gen_random_uuid()::TEXT), 1, 4)
);

-- ---------------------------------------------------------------------------
-- Helper macro: normalize CamelCase status to snake_case
-- Mirrors PHP: iconv → strtolower → preg_replace('/[^a-z0-9]+/', '_') → trim('_')
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO normalize_status(val) AS (
    trim(
        regexp_replace(
            lower(
                regexp_replace(val, '([a-z])([A-Z])', '\1_\2', 'g')
            ),
            '[^a-z0-9]+', '_', 'g'
        ),
        '_'
    )
);

-- ---------------------------------------------------------------------------
-- Helper macro: map situacaofaturacao → statusFaturamento enum
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO map_status_faturamento(val) AS (
    CASE upper(trim(COALESCE(val, '')))
        WHEN 'FATURADO'                     THEN 'FATURADO'
        WHEN 'FATURADO_PELO_ORCAMENTO'      THEN 'FATURADO'
        WHEN 'FATURADO_PELO_PLANO_TRATAMENTO' THEN 'FATURADO'
        WHEN 'NAO_FATURAR'                  THEN 'CANCELADO'
        WHEN 'NAO_COBRAR'                   THEN 'CANCELADO'
        WHEN 'PENDENTE_FATURACAO'           THEN 'SEM_REGISTRO'
        ELSE 'SEM_REGISTRO'
    END
);

-- ---------------------------------------------------------------------------
-- Helper macro: current timestamp string
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO now_str() AS (
    strftime(now(), '%Y-%m-%d %H:%M:%S')
);

-- =============================================================================
-- 1. Load CSV source tables
-- =============================================================================

CREATE OR REPLACE TABLE src_agenda AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/AGENDA.csv',
        auto_detect=true, ignore_errors=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_paciente AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/PACIENTE.csv',
        auto_detect=true, ignore_errors=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_pessoa AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/PESSOA.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_contato AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/CONTATO.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_endereco AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/ENDERECO.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_cidade AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/CIDADE.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_uf AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/UF.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_agenda_executor AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/AGENDA_EXECUTOR.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_tipo_consulta AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/TIPO_CONSULTA.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_tipo_convenio AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/TIPO_CONVENIO.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_convenio AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/CONVENIO.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_tipo_procedimento AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/TIPO_PROCEDIMENTO.csv',
        auto_detect=true, header=true, all_varchar=false, strict_mode=false
    );

CREATE OR REPLACE TABLE src_tipo_procedimento_convenio AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/TIPO_PROCEDIMENTO_CONVENIO.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_local_agenda AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/LOCAL_AGENDA.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_paciente_convenio AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/PACIENTE_CONVENIO.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_faturamento AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/FATURAMENTO.csv',
        auto_detect=true, ignore_errors=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_agenda_procedimento AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/AGENDA_PROCEDIMENTO.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_procedimento AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/PROCEDIMENTO.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_profissional_saude AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/PROFISSIONAL_SAUDE.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_profissional_saude_especialidade AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/PROFISSIONAL_SAUDE_ESPECIALIDADE.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_especialidade AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/ESPECIALIDADE.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_profissional_saude_clinica AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/PROFISSIONAL_SAUDE_CLINICA.csv',
        auto_detect=true, header=true, all_varchar=false
    );

CREATE OR REPLACE TABLE src_dados_usuario AS
    SELECT * FROM read_csv(
        getvariable('csvInputPath') || '/DADOS_USUARIO.csv',
        auto_detect=true, header=true, all_varchar=false
    );

-- =============================================================================
-- 2. Filter AGENDA by date range
-- =============================================================================
CREATE OR REPLACE TABLE filtered_agenda AS
    SELECT *
    FROM src_agenda
    WHERE CAST(dataConsulta AS DATE) >= CAST(getvariable('dateFrom') AS DATE)
      AND CAST(dataConsulta AS DATE) <= CAST(getvariable('dateTo') AS DATE);

-- =============================================================================
-- 3. ConsultaTipo (leaf entity, no FK dependencies)
-- =============================================================================
CREATE OR REPLACE TABLE out_consulta_tipo AS
    SELECT
        espo_id() AS id,
        CAST(tc.nome AS VARCHAR) AS name,
        0 AS deleted,
        CAST(tc.codigo AS VARCHAR) AS consulta_tipo_id,
        'synced' AS sync_status,
        CASE WHEN CAST(tc.ativo AS BOOLEAN) THEN 1 ELSE 0 END AS ativo,
        CASE WHEN CAST(tc.reconsulta AS BOOLEAN) THEN 1 ELSE 0 END AS reconsulta,
        now_str() AS created_at,
        now_str() AS modified_at,
        getvariable('credentialId') AS credential_id,
        NULL AS created_by_id,
        NULL AS modified_by_id,
        getvariable('settingsId') AS settings_id
    FROM src_tipo_consulta tc;

-- Lookup: consulta_tipo CNN codigo → EspoCRM id
CREATE OR REPLACE TABLE lookup_consulta_tipo AS
    SELECT consulta_tipo_id AS remote_id, id AS espo_id
    FROM out_consulta_tipo;

-- =============================================================================
-- 4. ConvenioTipo (leaf entity)
-- =============================================================================
CREATE OR REPLACE TABLE out_convenio_tipo AS
    SELECT
        espo_id() AS id,
        CAST(tc.nome AS VARCHAR) AS name,
        0 AS deleted,
        CAST(tc.codigo AS VARCHAR) AS convenio_tipo_id,
        'synced' AS sync_status,
        CASE WHEN CAST(tc.ativo AS BOOLEAN) THEN 1 ELSE 0 END AS ativo,
        CASE WHEN CAST(COALESCE(tc.conveniodebeneficios, false) AS BOOLEAN) THEN 1 ELSE 0 END AS beneficio,
        CASE WHEN CAST(COALESCE(cv.particular, false) AS BOOLEAN) THEN 1 ELSE 0 END AS particular,
        now_str() AS created_at,
        now_str() AS modified_at,
        getvariable('credentialId') AS credential_id,
        NULL AS created_by_id,
        NULL AS modified_by_id,
        getvariable('settingsId') AS settings_id
    FROM src_tipo_convenio tc
    LEFT JOIN src_convenio cv ON CAST(tc.codconvenio AS VARCHAR) = CAST(cv.codigo AS VARCHAR);

-- Lookup: convenio_tipo CNN codigo → EspoCRM id
CREATE OR REPLACE TABLE lookup_convenio_tipo AS
    SELECT convenio_tipo_id AS remote_id, id AS espo_id
    FROM out_convenio_tipo;

-- =============================================================================
-- 5. ProcedimentoTipo (leaf entity)
-- =============================================================================
CREATE OR REPLACE TABLE out_procedimento_tipo AS
    SELECT
        espo_id() AS id,
        CAST(tp.nome AS VARCHAR) AS name,
        0 AS deleted,
        CAST(tp.codtipoprocedimento AS VARCHAR) AS procedimento_tipo_id,
        'synced' AS sync_status,
        CASE WHEN CAST(tp.ativo AS BOOLEAN) THEN 1 ELSE 0 END AS ativo,
        NULL AS especialidades,
        now_str() AS created_at,
        now_str() AS modified_at,
        getvariable('credentialId') AS credential_id,
        NULL AS created_by_id,
        NULL AS modified_by_id,
        getvariable('settingsId') AS settings_id
    FROM src_tipo_procedimento tp;

-- Lookup: procedimento_tipo CNN codtipoprocedimento → EspoCRM id
CREATE OR REPLACE TABLE lookup_procedimento_tipo AS
    SELECT procedimento_tipo_id AS remote_id, id AS espo_id
    FROM out_procedimento_tipo;

-- =============================================================================
-- 6. ProcedimentoConvenio (FKs to ProcedimentoTipo + ConvenioTipo)
-- =============================================================================
CREATE OR REPLACE TABLE out_procedimento_convenio AS
    WITH deduped AS (
        SELECT
            tpc.*,
            ROW_NUMBER() OVER (
                PARTITION BY
                    CAST(tpc.codtipoprocedimento AS VARCHAR),
                    CAST(tpc.codtipoconvenio AS VARCHAR)
                ORDER BY tpc.codigo
            ) AS rn
        FROM src_tipo_procedimento_convenio tpc
    )
    SELECT
        espo_id() AS id,
        CAST(COALESCE(tc.nome, '') AS VARCHAR) || ' - ' || CAST(COALESCE(tp.nome, '') AS VARCHAR) AS name,
        0 AS deleted,
        CAST(d.codigo AS VARCHAR) AS codigo_tipo_procedimento_convenio,
        CASE WHEN CAST(d.ativo AS BOOLEAN) THEN 1 ELSE 0 END AS is_active,
        CAST(tc.nome AS VARCHAR) AS convenio_name,
        CAST(d.valor AS DOUBLE) AS preco_paciente,
        CAST(d.valorconvenio AS DOUBLE) AS preco_convenio,
        now_str() AS created_at,
        now_str() AS modified_at,
        'BRL' AS preco_paciente_currency,
        'BRL' AS preco_convenio_currency,
        lpt.espo_id AS procedimento_tipo_id,
        lct.espo_id AS convenio_tipo_id,
        NULL AS created_by_id,
        NULL AS modified_by_id
    FROM deduped d
    LEFT JOIN src_tipo_convenio tc ON CAST(d.codtipoconvenio AS VARCHAR) = CAST(tc.codigo AS VARCHAR)
    LEFT JOIN src_tipo_procedimento tp ON CAST(d.codtipoprocedimento AS VARCHAR) = CAST(tp.codtipoprocedimento AS VARCHAR)
    LEFT JOIN lookup_procedimento_tipo lpt ON CAST(d.codtipoprocedimento AS VARCHAR) = lpt.remote_id
    LEFT JOIN lookup_convenio_tipo lct ON CAST(d.codtipoconvenio AS VARCHAR) = lct.remote_id
    WHERE d.rn = 1
      AND lpt.espo_id IS NOT NULL
      AND lct.espo_id IS NOT NULL;

-- =============================================================================
-- 7. Profissional (leaf relative to Agendamento)
-- =============================================================================
-- Build profissional_saude data via corrected join chain:
-- AGENDA_EXECUTOR.codpessoa → DADOS_USUARIO.codpessoa_fk → DADOS_USUARIO.codusuario → PROFISSIONAL_SAUDE.coddadosusuario

CREATE OR REPLACE TABLE prof_saude_data AS
    SELECT
        ae.codigo AS executor_codigo,
        ae.codpessoa AS executor_codpessoa,
        ps.codprofissional,
        ps.registro,
        du.codcbo
    FROM src_agenda_executor ae
    LEFT JOIN src_dados_usuario du ON CAST(ae.codpessoa AS VARCHAR) = CAST(du.codpessoa_fk AS VARCHAR)
    LEFT JOIN src_profissional_saude ps ON CAST(du.codusuario AS VARCHAR) = CAST(ps.coddadosusuario AS VARCHAR);

-- Build especialidades JSON per profissional_saude.codprofissional
CREATE OR REPLACE TABLE prof_especialidades AS
    SELECT
        pse.codprofissional,
        '[' || string_agg(
            '{"id":"' || CAST(e.codigo AS VARCHAR) || '","nome":"' || REPLACE(COALESCE(CAST(e.nome AS VARCHAR), ''), '"', '\\"') || '"}',
            ','
        ) || ']' AS especialidades_json,
        string_agg(DISTINCT CAST(e.nome AS VARCHAR), ', ') AS especialidades_texto
    FROM src_profissional_saude_especialidade pse
    JOIN src_especialidade e ON CAST(pse.codespecialidade AS VARCHAR) = CAST(e.codigo AS VARCHAR)
    GROUP BY pse.codprofissional;

-- Build clinicas JSON per profissional_saude.codprofissional
CREATE OR REPLACE TABLE prof_clinicas AS
    SELECT
        psc.codprofissionalsaude,
        '[' || string_agg(
            '{"codigo":"' || CAST(psc.codigo AS VARCHAR) || '","codclinica":"' || CAST(psc.codclinica AS VARCHAR) || '"}',
            ','
        ) || ']' AS clinicas_json
    FROM src_profissional_saude_clinica psc
    GROUP BY psc.codprofissionalsaude;

CREATE OR REPLACE TABLE out_profissional AS
    SELECT
        espo_id() AS id,
        CAST(p.nomecompleto AS VARCHAR) AS name,
        0 AS deleted,
        CAST(ae.codigo AS VARCHAR) AS profissional_id,
        CAST(ae.codpessoa AS VARCHAR) AS id_pessoa,
        'synced' AS sync_status,
        CASE WHEN CAST(ae.ativo AS BOOLEAN) THEN 1 ELSE 0 END AS ativo,
        CAST(ae.tipo AS VARCHAR) AS tipo_executor,
        CAST(p.cpfOuCnpj AS VARCHAR) AS cpfcnpj,
        CASE WHEN upper(CAST(ae.tipo AS VARCHAR)) = 'PROFISSIONAL' THEN 1 ELSE 0 END AS profissional,
        CAST(psd.codprofissional AS VARCHAR) AS profissional_codigo,
        CAST(psd.codcbo AS VARCHAR) AS cbo,
        CAST(psd.registro AS VARCHAR) AS registro_profissional,
        pe.especialidades_json AS especialidades,
        pe.especialidades_texto AS especialidades_texto,
        pc.clinicas_json AS clinicas,
        now_str() AS created_at,
        now_str() AS modified_at,
        getvariable('credentialId') AS credential_id,
        NULL AS created_by_id,
        NULL AS modified_by_id,
        getvariable('settingsId') AS settings_id
    FROM src_agenda_executor ae
    LEFT JOIN src_pessoa p ON CAST(ae.codpessoa AS VARCHAR) = CAST(p.codigo AS VARCHAR)
    LEFT JOIN prof_saude_data psd ON CAST(ae.codigo AS VARCHAR) = CAST(psd.executor_codigo AS VARCHAR)
    LEFT JOIN prof_especialidades pe ON CAST(psd.codprofissional AS VARCHAR) = CAST(pe.codprofissional AS VARCHAR)
    LEFT JOIN prof_clinicas pc ON CAST(psd.codprofissional AS VARCHAR) = CAST(pc.codprofissionalsaude AS VARCHAR);

-- Lookup: profissional CNN codigo → EspoCRM id
CREATE OR REPLACE TABLE lookup_profissional AS
    SELECT profissional_id AS remote_id, id AS espo_id
    FROM out_profissional;

-- Lookup: profissional CNN codpessoa → EspoCRM id (for agenda join)
CREATE OR REPLACE TABLE lookup_profissional_by_codpessoa AS
    SELECT id_pessoa AS codpessoa, id AS espo_id, profissional_id AS remote_id
    FROM out_profissional;

-- =============================================================================
-- 8. Paciente (filtered to those referenced by in-range agendamentos)
-- =============================================================================

-- Collect distinct paciente codpessoa values from filtered agendas
CREATE OR REPLACE TABLE needed_pacientes AS
    SELECT DISTINCT CAST(fa.paciente_codpessoa AS VARCHAR) AS codpessoa
    FROM filtered_agenda fa
    WHERE fa.paciente_codpessoa IS NOT NULL;

-- For paciente convenio data, pick the first active convenio per paciente
CREATE OR REPLACE TABLE paciente_convenio_ranked AS
    SELECT
        pc.*,
        tc.nome AS tipo_convenio_nome,
        ROW_NUMBER() OVER (
            PARTITION BY CAST(pc.codpaciente AS VARCHAR)
            ORDER BY pc.codigo DESC
        ) AS rn
    FROM src_paciente_convenio pc
    LEFT JOIN src_tipo_convenio tc ON CAST(pc.codtipoconvenio AS VARCHAR) = CAST(tc.codigo AS VARCHAR);

CREATE OR REPLACE TABLE out_paciente AS
    SELECT
        espo_id() AS id,
        CAST(ps.nomecompleto AS VARCHAR) AS name,
        0 AS deleted,
        CAST(pac.codpessoa AS VARCHAR) AS paciente_id,
        'synced' AS sync_status,
        now_str() AS created_at,
        now_str() AS modified_at,
        NULL AS contact_id,
        getvariable('credentialId') AS credential_id,
        NULL AS created_by_id,
        NULL AS modified_by_id,
        CAST(ps.sexo AS VARCHAR) AS sexo,
        NULL AS payload_hash,
        CASE WHEN CAST(pac.ativo AS BOOLEAN) THEN 1 ELSE 0 END AS ativo,
        CAST(ps.cpfOuCnpj AS VARCHAR) AS cpfcnpj,
        CASE
            WHEN ps.dataNascimento IS NOT NULL
            THEN strftime(CAST(ps.dataNascimento AS DATE), '%Y-%m-%d')
            ELSE NULL
        END AS data_nascimento,
        CAST(COALESCE(NULLIF(ct.telefoneComercial, ''), ct.telefoneResidencial) AS VARCHAR) AS telefone,
        CAST(ct.telefoneCelular AS VARCHAR) AS celular,
        NULL AS nome_mae,
        NULL AS nome_pai,
        CAST(ps.estadocivil AS VARCHAR) AS estado_civil,
        CAST(ps.profissao AS VARCHAR) AS profissao,
        CAST(ed.rua AS VARCHAR) AS endereco,
        CAST(ed.numero AS VARCHAR) AS numero,
        CAST(ed.complemento AS VARCHAR) AS complemento,
        CAST(ed.bairro AS VARCHAR) AS bairro,
        CAST(ci.nome AS VARCHAR) AS cidade,
        CAST(uf.sigla AS VARCHAR) AS estado,
        CAST(ed.cep AS VARCHAR) AS cep,
        NULL AS observacao,
        CAST(pcr.tipo_convenio_nome AS VARCHAR) AS convenio,
        CAST(pcr.carteirinha AS VARCHAR) AS numero_convenio,
        CASE
            WHEN pcr.validade IS NOT NULL
            THEN strftime(CAST(pcr.validade AS DATE), '%Y-%m-%d')
            ELSE NULL
        END AS validade_convenio,
        getvariable('settingsId') AS settings_id
    FROM needed_pacientes np
    JOIN src_paciente pac ON np.codpessoa = CAST(pac.codpessoa AS VARCHAR)
    -- PESSOA join via codpessoa_fk (NOT codpessoa; codpessoa_fk is the actual FK to PESSOA.codigo)
    LEFT JOIN src_pessoa ps ON CAST(pac.codpessoa_fk AS VARCHAR) = CAST(ps.codigo AS VARCHAR)
    LEFT JOIN src_contato ct ON CAST(ps.codcontato AS VARCHAR) = CAST(ct.codigo AS VARCHAR)
    LEFT JOIN src_endereco ed ON CAST(ps.codendereco AS VARCHAR) = CAST(ed.codigo AS VARCHAR)
    LEFT JOIN src_cidade ci ON CAST(ed.codcidade AS VARCHAR) = CAST(ci.codigo AS VARCHAR)
    LEFT JOIN src_uf uf ON CAST(ci.codEstado AS VARCHAR) = CAST(uf.codigo AS VARCHAR)
    LEFT JOIN paciente_convenio_ranked pcr ON CAST(pac.codpessoa AS VARCHAR) = CAST(pcr.codpaciente AS VARCHAR) AND pcr.rn = 1;

-- Lookup: paciente CNN codpessoa → EspoCRM id
CREATE OR REPLACE TABLE lookup_paciente AS
    SELECT paciente_id AS remote_id, id AS espo_id
    FROM out_paciente;

-- =============================================================================
-- 9. Agendamento (filtered by date range, FKs to Paciente, Profissional, etc.)
-- =============================================================================

-- Resolve first procedimento's especialidade per agenda
CREATE OR REPLACE TABLE agenda_first_especialidade AS
    SELECT
        ap.codagenda,
        pr.codespecialidade,
        esp.nome AS especialidade_nome,
        ROW_NUMBER() OVER (PARTITION BY CAST(ap.codagenda AS VARCHAR) ORDER BY ap.codigo) AS rn
    FROM src_agenda_procedimento ap
    JOIN src_procedimento pr ON CAST(ap.codprocedimento AS VARCHAR) = CAST(pr.codigo AS VARCHAR)
    LEFT JOIN src_especialidade esp ON CAST(pr.codespecialidade AS VARCHAR) = CAST(esp.codigo AS VARCHAR)
    WHERE pr.codespecialidade IS NOT NULL;

CREATE OR REPLACE TABLE out_agendamento AS
    SELECT
        espo_id() AS id,
        CAST(COALESCE(pac_pessoa.nomecompleto, '') AS VARCHAR) AS name,
        0 AS deleted,
        CAST(fa.codigo AS VARCHAR) AS agendamento_id,
        'synced' AS sync_status,
        CAST(fa.paciente_codpessoa AS VARCHAR) AS id_paciente,
        lprof.remote_id AS id_profissional,
        CAST(fa.convenio_codigo AS VARCHAR) AS id_convenio,
        CAST(afe.codespecialidade AS VARCHAR) AS id_especialidade,
        CAST(la.localAtendimento AS VARCHAR) AS id_unidade,
        CAST(fa.codlocalagenda AS VARCHAR) AS id_sala,
        -- data: dataConsulta as datetime
        strftime(CAST(fa.dataConsulta AS TIMESTAMP), '%Y-%m-%d %H:%M:%S') AS data,
        -- hora_inicio: dataConsulta date + horarioInicial time (DATE + TIME → TIMESTAMP)
        CASE
            WHEN fa.horarioInicial IS NOT NULL
            THEN strftime(
                CAST(fa.dataConsulta AS DATE) + CAST(fa.horarioInicial AS TIME),
                '%Y-%m-%d %H:%M:%S'
            )
            ELSE NULL
        END AS hora_inicio,
        -- hora_fim: dataConsulta date + horarioFinal time (DATE + TIME → TIMESTAMP)
        CASE
            WHEN fa.horarioFinal IS NOT NULL
            THEN strftime(
                CAST(fa.dataConsulta AS DATE) + CAST(fa.horarioFinal AS TIME),
                '%Y-%m-%d %H:%M:%S'
            )
            ELSE NULL
        END AS hora_fim,
        normalize_status(CAST(fa.statusConsulta AS VARCHAR)) AS status,
        NULL AS tipo_atendimento,
        CAST(prof_pessoa.nomecompleto AS VARCHAR) AS profissional,
        CAST(tc_nome.nome AS VARCHAR) AS convenio,
        CAST(afe.especialidade_nome AS VARCHAR) AS especialidade,
        CAST(la.nome AS VARCHAR) AS sala,
        NULL AS unidade,
        CAST(fa.observacao AS VARCHAR) AS observacao,
        NULL AS procedimentos,
        now_str() AS created_at,
        now_str() AS modified_at,
        lpac.espo_id AS paciente_id,
        getvariable('credentialId') AS credential_id,
        NULL AS created_by_id,
        NULL AS modified_by_id,
        NULL AS valor,
        NULL AS valor_currency,
        map_status_faturamento(CAST(fa.situacaofaturacao AS VARCHAR)) AS status_faturamento,
        CAST(fa.codpessoaexecucao AS VARCHAR) AS id_pessoa_executor,
        lprof.espo_id AS profissional_anchor_id,
        CAST(pc.codtipoconvenio AS VARCHAR) AS id_tipo_convenio,
        lconv.espo_id AS convenio_tipo_anchor_id,
        NULL AS valor_procedimentos,
        NULL AS valor_procedimentos_currency,
        NULL AS valor_faturamentos,
        NULL AS valor_faturamentos_currency,
        NULL AS valor_financeiro,
        NULL AS valor_financeiro_currency,
        CAST(fa.codtipoconsulta AS VARCHAR) AS id_tipo_consulta,
        lcons.espo_id AS consulta_tipo_anchor_id,
        getvariable('settingsId') AS settings_id
    FROM filtered_agenda fa
    -- Patient person name: AGENDA.paciente_codpessoa → PACIENTE.codpessoa → PACIENTE.codpessoa_fk → PESSOA.codigo
    LEFT JOIN src_paciente pac_link ON CAST(fa.paciente_codpessoa AS VARCHAR) = CAST(pac_link.codpessoa AS VARCHAR)
    LEFT JOIN src_pessoa pac_pessoa ON CAST(pac_link.codpessoa_fk AS VARCHAR) = CAST(pac_pessoa.codigo AS VARCHAR)
    -- Profissional lookup via codpessoaexecucao → AGENDA_EXECUTOR.codpessoa
    LEFT JOIN lookup_profissional_by_codpessoa lprof ON CAST(fa.codpessoaexecucao AS VARCHAR) = lprof.codpessoa
    -- Profissional person name
    LEFT JOIN src_pessoa prof_pessoa ON CAST(fa.codpessoaexecucao AS VARCHAR) = CAST(prof_pessoa.codigo AS VARCHAR)
    -- Paciente lookup
    LEFT JOIN lookup_paciente lpac ON CAST(fa.paciente_codpessoa AS VARCHAR) = lpac.remote_id
    -- ConvenioTipo via 3-hop: AGENDA.convenio_codigo → PACIENTE_CONVENIO.codigo → codtipoconvenio → TIPO_CONVENIO.codigo
    LEFT JOIN src_paciente_convenio pc ON CAST(fa.convenio_codigo AS VARCHAR) = CAST(pc.codigo AS VARCHAR)
    LEFT JOIN src_tipo_convenio tc_nome ON CAST(pc.codtipoconvenio AS VARCHAR) = CAST(tc_nome.codigo AS VARCHAR)
    LEFT JOIN lookup_convenio_tipo lconv ON CAST(pc.codtipoconvenio AS VARCHAR) = lconv.remote_id
    -- ConsultaTipo lookup
    LEFT JOIN lookup_consulta_tipo lcons ON CAST(fa.codtipoconsulta AS VARCHAR) = lcons.remote_id
    -- Local agenda (sala)
    LEFT JOIN src_local_agenda la ON CAST(fa.codlocalagenda AS VARCHAR) = CAST(la.codigo AS VARCHAR)
    -- First especialidade from procedimentos
    LEFT JOIN agenda_first_especialidade afe ON CAST(fa.codigo AS VARCHAR) = CAST(afe.codagenda AS VARCHAR) AND afe.rn = 1;

-- Lookup: agendamento CNN codigo → EspoCRM id
CREATE OR REPLACE TABLE lookup_agendamento AS
    SELECT agendamento_id AS remote_id, id AS espo_id
    FROM out_agendamento;

-- =============================================================================
-- 10. AgendamentoProcedimento (FKs to Agendamento + ProcedimentoTipo)
-- =============================================================================
CREATE OR REPLACE TABLE out_agendamento_procedimento AS
    WITH base AS (
        SELECT
            ap.codigo AS ap_codigo,
            ap.codagenda,
            pr.codtipoprocedimento,
            CAST(COALESCE(pr.quantidade, 1) AS INTEGER) AS quantidade,
            CAST(tp.nome AS VARCHAR) AS procedimento_nome,
            CAST(pr.valorunitariopaciente AS DOUBLE) AS preco_paciente,
            CAST(pr.valorunitarioconvenio AS DOUBLE) AS preco_convenio,
            lagd.espo_id AS agendamento_espo_id,
            lpt.espo_id AS procedimento_tipo_espo_id,
            ROW_NUMBER() OVER (
                PARTITION BY lagd.espo_id, lpt.espo_id
                ORDER BY ap.codigo
            ) AS rn
        FROM src_agenda_procedimento ap
        JOIN src_procedimento pr ON CAST(ap.codprocedimento AS VARCHAR) = CAST(pr.codigo AS VARCHAR)
        LEFT JOIN src_tipo_procedimento tp ON CAST(pr.codtipoprocedimento AS VARCHAR) = CAST(tp.codtipoprocedimento AS VARCHAR)
        JOIN lookup_agendamento lagd ON CAST(ap.codagenda AS VARCHAR) = lagd.remote_id
        LEFT JOIN lookup_procedimento_tipo lpt ON CAST(pr.codtipoprocedimento AS VARCHAR) = lpt.remote_id
    )
    SELECT
        espo_id() AS id,
        COALESCE(b.procedimento_nome, '') AS name,
        0 AS deleted,
        b.quantidade,
        b.procedimento_nome,
        now_str() AS created_at,
        now_str() AS modified_at,
        b.agendamento_espo_id AS agendamento_id,
        b.procedimento_tipo_espo_id AS procedimento_tipo_id,
        NULL AS created_by_id,
        NULL AS modified_by_id,
        b.preco_paciente,
        b.preco_convenio,
        CAST(b.preco_paciente * b.quantidade AS DOUBLE) AS valor_total,
        'BRL' AS preco_paciente_currency,
        'BRL' AS preco_convenio_currency,
        'BRL' AS valor_total_currency
    FROM base b
    WHERE b.rn = 1
      AND b.agendamento_espo_id IS NOT NULL
      AND b.procedimento_tipo_espo_id IS NOT NULL;

-- =============================================================================
-- 11. Faturamento (FKs to Agendamento + Paciente + Profissional)
-- =============================================================================
CREATE OR REPLACE TABLE out_faturamento AS
    SELECT
        espo_id() AS id,
        CAST(f.codigo AS VARCHAR) AS name,
        0 AS deleted,
        CAST(f.codigo AS VARCHAR) AS faturamento_id,
        'synced' AS sync_status,
        CAST(f.numeroDocumento AS VARCHAR) AS documento,
        CASE
            WHEN f.dataFaturamento IS NOT NULL
            THEN strftime(CAST(f.dataFaturamento AS DATE), '%Y-%m-%d')
            ELSE NULL
        END AS data_faturamento,
        CAST(prof_p.nomecompleto AS VARCHAR) AS profissional_nome,
        NULL AS conta,
        CAST(f.valor AS DOUBLE) AS valor,
        CAST(f.parcela AS VARCHAR) AS parcela,
        CASE
            WHEN f.dataVencimento IS NOT NULL
            THEN strftime(CAST(f.dataVencimento AS DATE), '%Y-%m-%d')
            ELSE NULL
        END AS data_vencimento,
        CAST(f.descricao AS VARCHAR) AS description,
        now_str() AS created_at,
        now_str() AS modified_at,
        'BRL' AS valor_currency,
        lagd.espo_id AS agendamento_id,
        getvariable('webCredentialId') AS credential_id,
        NULL AS created_by_id,
        NULL AS modified_by_id,
        lpac.espo_id AS paciente_id,
        lprof.espo_id AS profissional_anchor_id,
        getvariable('settingsId') AS settings_id
    FROM src_faturamento f
    -- Only import faturamentos linked to in-range agendamentos
    JOIN lookup_agendamento lagd ON CAST(f.cod_agenda AS VARCHAR) = lagd.remote_id
    -- Resolve paciente: faturamento.codpaciente → PACIENTE.codpessoa
    LEFT JOIN lookup_paciente lpac ON CAST(f.codpaciente AS VARCHAR) = lpac.remote_id
    -- Resolve profissional: faturamento.codProfissional is codpessoa of executor
    LEFT JOIN lookup_profissional_by_codpessoa lprof ON CAST(f.codProfissional AS VARCHAR) = lprof.codpessoa
    -- Profissional person name for display
    LEFT JOIN src_pessoa prof_p ON CAST(f.codProfissional AS VARCHAR) = CAST(prof_p.codigo AS VARCHAR);

-- =============================================================================
-- 12. EntityTeam rows (one per entity per team)
-- =============================================================================
CREATE OR REPLACE TABLE out_entity_team AS
    -- Agendamento
    SELECT id AS entity_id, getvariable('teamId') AS team_id, 'FeatureIntegrationClinicaNasNuvensAgendamento' AS entity_type, 0 AS deleted FROM out_agendamento
    UNION ALL
    -- Paciente
    SELECT id, getvariable('teamId'), 'FeatureIntegrationClinicaNasNuvensPaciente', 0 FROM out_paciente
    UNION ALL
    -- Profissional
    SELECT id, getvariable('teamId'), 'FeatureIntegrationClinicaNasNuvensProfissional', 0 FROM out_profissional
    UNION ALL
    -- Faturamento
    SELECT id, getvariable('teamId'), 'FeatureIntegrationClinicaNasNuvensFaturamento', 0 FROM out_faturamento
    UNION ALL
    -- ConsultaTipo
    SELECT id, getvariable('teamId'), 'FeatureIntegrationClinicaNasNuvensConsultaTipo', 0 FROM out_consulta_tipo
    UNION ALL
    -- ConvenioTipo
    SELECT id, getvariable('teamId'), 'FeatureIntegrationClinicaNasNuvensConvenioTipo', 0 FROM out_convenio_tipo
    UNION ALL
    -- ProcedimentoTipo
    SELECT id, getvariable('teamId'), 'FeatureIntegrationClinicaNasNuvensProcedimentoTipo', 0 FROM out_procedimento_tipo;

-- =============================================================================
-- 13. Export all output tables to CSV
-- =============================================================================

-- DuckDB v1.2.2 does not support expressions in COPY TO path.
-- PHP replaces __CSV_OUTPUT_PATH__ with the absolute output path before execution.
COPY out_consulta_tipo TO '__CSV_OUTPUT_PATH__/consulta_tipo.csv' (HEADER, DELIMITER ',');
COPY out_convenio_tipo TO '__CSV_OUTPUT_PATH__/convenio_tipo.csv' (HEADER, DELIMITER ',');
COPY out_procedimento_tipo TO '__CSV_OUTPUT_PATH__/procedimento_tipo.csv' (HEADER, DELIMITER ',');
COPY out_procedimento_convenio TO '__CSV_OUTPUT_PATH__/procedimento_convenio.csv' (HEADER, DELIMITER ',');
COPY out_profissional TO '__CSV_OUTPUT_PATH__/profissional.csv' (HEADER, DELIMITER ',');
COPY out_paciente TO '__CSV_OUTPUT_PATH__/paciente.csv' (HEADER, DELIMITER ',');
COPY out_agendamento TO '__CSV_OUTPUT_PATH__/agendamento.csv' (HEADER, DELIMITER ',');
COPY out_agendamento_procedimento TO '__CSV_OUTPUT_PATH__/agendamento_procedimento.csv' (HEADER, DELIMITER ',');
COPY out_faturamento TO '__CSV_OUTPUT_PATH__/faturamento.csv' (HEADER, DELIMITER ',');
COPY out_entity_team TO '__CSV_OUTPUT_PATH__/entity_team.csv' (HEADER, DELIMITER ',');

-- Summary stats for logging
SELECT
    (SELECT count(*) FROM out_consulta_tipo) AS consulta_tipo_count,
    (SELECT count(*) FROM out_convenio_tipo) AS convenio_tipo_count,
    (SELECT count(*) FROM out_procedimento_tipo) AS procedimento_tipo_count,
    (SELECT count(*) FROM out_procedimento_convenio) AS procedimento_convenio_count,
    (SELECT count(*) FROM out_profissional) AS profissional_count,
    (SELECT count(*) FROM out_paciente) AS paciente_count,
    (SELECT count(*) FROM out_agendamento) AS agendamento_count,
    (SELECT count(*) FROM out_agendamento_procedimento) AS agendamento_procedimento_count,
    (SELECT count(*) FROM out_faturamento) AS faturamento_count,
    (SELECT count(*) FROM out_entity_team) AS entity_team_count;
