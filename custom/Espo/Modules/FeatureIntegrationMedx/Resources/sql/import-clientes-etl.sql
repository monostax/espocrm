-- =============================================================================
-- MEDX Clientes JSON -> EspoCRM ETL Script (DuckDB)
-- =============================================================================
-- Parameterized via DuckDB SET VARIABLE:
--   jsonInputPath  - absolute path to the pre-fetched JSON file
--   credentialId   - Web credential ID (varchar(17))
--   settingsId     - Settings profile ID (varchar(17))
--   teamId         - Team ID (varchar(17))
--
-- Pipeline:
--   1. PHP fetches MEDX GetContatosGrid via cURL (handles auth + pagination)
--   2. PHP writes raw JSON response to a temp file
--   3. DuckDB reads the local JSON, transforms + generates deterministic IDs
--   4. DuckDB outputs per-entity CSVs
--   5. PHP reads output CSVs and batch-upserts into MySQL
--
-- ID Generation: All entity IDs are DETERMINISTIC via
--   espo_id(seed) = substr(md5(seed), 1, 17)
-- where seed = 'mc::{credentialId}::{clienteId}'.
-- Same source data always produces the same EspoCRM IDs, making the
-- import fully idempotent.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Helper macro: generate deterministic EspoCRM-compatible 17-char hex IDs
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO espo_id(seed) AS (
    substr(md5(seed), 1, 17)
);

-- ---------------------------------------------------------------------------
-- Helper macro: current timestamp string
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO now_str() AS (
    strftime(now(), '%Y-%m-%d %H:%M:%S')
);

-- ---------------------------------------------------------------------------
-- Helper macro: normalize MEDX faux-bool ("true"/"false"/null) to 0/1/NULL
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO medx_bool(val) AS (
    CASE
        WHEN val IS NULL THEN NULL
        WHEN CAST(val AS VARCHAR) = '' THEN NULL
        WHEN lower(CAST(val AS VARCHAR)) = 'null' THEN NULL
        WHEN lower(CAST(val AS VARCHAR)) = 'true' THEN 1
        WHEN lower(CAST(val AS VARCHAR)) = 'false' THEN 0
        WHEN CAST(val AS VARCHAR) = '1' THEN 1
        WHEN CAST(val AS VARCHAR) = '0' THEN 0
        ELSE NULL
    END
);

-- ---------------------------------------------------------------------------
-- Helper macro: normalize string (trim, treat 'null' literal as NULL)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO medx_str(val) AS (
    CASE
        WHEN val IS NULL THEN NULL
        WHEN trim(CAST(val AS VARCHAR)) = '' THEN NULL
        WHEN lower(trim(CAST(val AS VARCHAR))) = 'null' THEN NULL
        ELSE trim(CAST(val AS VARCHAR))
    END
);

-- ---------------------------------------------------------------------------
-- Helper macro: normalize int (treat 0 as NULL for FK-style int fields)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO medx_int(val) AS (
    CASE
        WHEN val IS NULL THEN NULL
        WHEN CAST(val AS VARCHAR) = '' THEN NULL
        WHEN lower(CAST(val AS VARCHAR)) = 'null' THEN NULL
        WHEN CAST(val AS INTEGER) = 0 THEN NULL
        ELSE CAST(val AS INTEGER)
    END
);

-- ---------------------------------------------------------------------------
-- Helper macro: normalize date (strip trailing time portion T...)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE MACRO medx_date(val) AS (
    CASE
        WHEN val IS NULL THEN NULL
        WHEN trim(CAST(val AS VARCHAR)) = '' THEN NULL
        WHEN lower(trim(CAST(val AS VARCHAR))) = 'null' THEN NULL
        ELSE
            CASE
                WHEN position('T' IN CAST(val AS VARCHAR)) > 0
                THEN substr(CAST(val AS VARCHAR), 1, position('T' IN CAST(val AS VARCHAR)) - 1)
                ELSE CAST(val AS VARCHAR)
            END
    END
);

-- =============================================================================
-- 1. Load JSON source
-- =============================================================================
-- The MEDX GetContatosGrid endpoint returns a flat JSON array of row objects.
-- The grid returns a SUBSET of fields; the full detail comes from
-- GetContatosFichaById (not used here). We use read_json with union_by_name
-- to gracefully handle missing/extra columns across rows.
CREATE OR REPLACE TABLE src_clientes AS
    SELECT * FROM read_json(
        getvariable('jsonInputPath'),
        format = 'array',
        union_by_name = true,
        maximum_object_size = 33554432,
        ignore_errors = true,
        columns = {
            Id_do_Cliente: 'VARCHAR',
            Nome: 'VARCHAR',
            Id_da_Assinatura: 'VARCHAR',
            CPF_CGC: 'VARCHAR',
            Nascimento: 'VARCHAR',
            Email: 'VARCHAR',
            Celular: 'VARCHAR',
            Telefone_Residencial: 'VARCHAR',
            Telefone_Residencial_1: 'VARCHAR',
            Sexo: 'VARCHAR',
            Nome_Social: 'VARCHAR',
            Estado_Civil: 'VARCHAR',
            Profissao: 'VARCHAR',
            Endereco_Residencial: 'VARCHAR',
            Bairro_Residencial: 'VARCHAR',
            Cidade_Residencial: 'VARCHAR',
            Estado_Residencial: 'VARCHAR',
            Cep_Residencial: 'VARCHAR',
            Id_do_Convenio: 'VARCHAR',
            IddoConvenio: 'VARCHAR',
            Convenio: 'VARCHAR',
            Numero_da_Matricula: 'VARCHAR',
            NumeroCNS: 'VARCHAR',
            Observacoes: 'VARCHAR',
            Referencias: 'VARCHAR',
            Tags: 'VARCHAR',
            VIP: 'VARCHAR',
            Mala_Direta: 'VARCHAR',
            Pendente: 'VARCHAR',
            Exclui_Mkt: 'VARCHAR',
            Indicado_por: 'VARCHAR',
            Como_conheceu: 'VARCHAR',
            Escolaridade: 'VARCHAR',
            Religiao: 'VARCHAR'
        }
    );

-- Source count for logging
SELECT 'src_clientes count' AS table_name, count(*) AS row_count FROM src_clientes;

-- =============================================================================
-- 2. Transform into FeatureIntegrationMedxCliente shape
-- =============================================================================
-- Filters out rows with NULL/blank/literal-"null" clienteId.
-- Uses deterministic IDs: espo_id('mc::{credentialId}::{clienteId}')
-- which matches the unique index (clienteId, credentialId, deleted).
--
-- All columns are read as VARCHAR to avoid type errors from missing/null
-- fields that the grid endpoint may not return.
CREATE OR REPLACE TABLE out_clientes AS
    SELECT
        espo_id('mc::' || getvariable('credentialId') || '::' || CAST(src.Id_do_Cliente AS VARCHAR)) AS id,
        medx_str(src.Nome) AS name,
        0 AS deleted,
        CAST(src.Id_do_Cliente AS VARCHAR) AS cliente_id,
        'pending' AS sync_status,
        medx_int(src.Id_da_Assinatura) AS assinatura_id,
        medx_str(src.CPF_CGC) AS cpf_cgc,
        medx_date(src.Nascimento) AS data_nascimento,
        medx_str(
            CASE
                WHEN lower(trim(CAST(COALESCE(src.Email, '') AS VARCHAR))) = 'null' THEN NULL
                ELSE src.Email
            END
        ) AS email_address,
        medx_str(src.Celular) AS celular,
        medx_str(src.Telefone_Residencial) AS telefone_residencial,
        medx_str(src.Telefone_Residencial_1) AS telefone_residencial1,
        medx_str(src.Sexo) AS sexo,
        medx_str(src.Nome_Social) AS nome_social,
        medx_str(src.Estado_Civil) AS estado_civil,
        medx_str(src.Profissao) AS profissao,
        medx_str(src.Endereco_Residencial) AS endereco_residencial,
        medx_str(src.Bairro_Residencial) AS bairro_residencial,
        medx_str(src.Cidade_Residencial) AS cidade_residencial,
        medx_str(src.Estado_Residencial) AS estado_residencial,
        medx_str(src.Cep_Residencial) AS cep_residencial,
        medx_int(COALESCE(src.Id_do_Convenio, src.IddoConvenio)) AS id_do_convenio,
        medx_str(src.Convenio) AS convenio,
        medx_str(src.Numero_da_Matricula) AS numero_matricula,
        medx_str(src.NumeroCNS) AS numero_cns,
        medx_str(src.Observacoes) AS observacoes,
        medx_str(src.Referencias) AS referencias,
        medx_str(src.Tags) AS tags,
        medx_bool(src.VIP) AS vip,
        medx_bool(src.Mala_Direta) AS mala_direta,
        medx_bool(src.Pendente) AS pendente,
        medx_int(src.Exclui_Mkt) AS exclui_mkt,
        medx_int(src.Indicado_por) AS indicado_por,
        medx_str(src.Como_conheceu) AS como_conheceu,
        medx_str(src.Escolaridade) AS escolaridade,
        medx_str(src.Religiao) AS religiao,
        now_str() AS created_at,
        now_str() AS modified_at,
        NULL AS contact_id,
        getvariable('credentialId') AS credential_id,
        NULL AS created_by_id,
        NULL AS modified_by_id,
        getvariable('settingsId') AS settings_id
    FROM src_clientes src
    WHERE src.Id_do_Cliente IS NOT NULL
      AND CAST(src.Id_do_Cliente AS VARCHAR) != ''
      AND lower(CAST(src.Id_do_Cliente AS VARCHAR)) != 'null';

-- =============================================================================
-- 3. EntityTeam rows (one per entity per team)
-- =============================================================================
CREATE OR REPLACE TABLE out_entity_team AS
    SELECT
        id AS entity_id,
        getvariable('teamId') AS team_id,
        'FeatureIntegrationMedxCliente' AS entity_type,
        0 AS deleted
    FROM out_clientes;

-- =============================================================================
-- 4. Export all output tables to CSV
-- =============================================================================
-- DuckDB v1.2.2 does not support expressions in COPY TO path.
-- PHP replaces __CSV_OUTPUT_PATH__ with the absolute output path before execution.
COPY out_clientes TO '__CSV_OUTPUT_PATH__/clientes.csv' (HEADER, DELIMITER ',');
COPY out_entity_team TO '__CSV_OUTPUT_PATH__/entity_team.csv' (HEADER, DELIMITER ',');

-- Summary stats for logging
SELECT
    (SELECT count(*) FROM out_clientes) AS clientes_count,
    (SELECT count(*) FROM out_entity_team) AS entity_team_count;
