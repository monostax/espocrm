# EspoCRM · Arquitetura de Informação
## Modelo Proposto v3 - Desatualizado

---

## Índice

1. [Visão Geral e Camadas](#1-visão-geral-e-camadas)
2. [Entidades de Suporte](#2-entidades-de-suporte)
   - 2.1 [Unidade](#21-unidade)
   - 2.2 [Profissional](#22-profissional)
   - 2.3 [Convenio](#23-convenio)
   - 2.4 [ConvenioRegra](#24-convenioregra)
3. [Catálogo — Camada 1](#3-catálogo--camada-1)
   - 3.1 [ProcedimentoBase](#31-base-entity-procedimentobase)
   - 3.2 [ProcedimentoConsulta](#32-procedimentoconsulta)
   - 3.3 [ProcedimentoInjetavel](#33-procedimentoinjetavel)
   - 3.4 [ProcedimentoImplante](#34-procedimentoimplante)
   - 3.5 [ProcedimentoEstetico](#35-procedimentoestetico)
   - 3.6 [ProcedimentoAtividadeFisica](#36-procedimentoatividadefisica)
   - 3.7 [TabelaDePrecos](#37-tabeladeprecos)
   - 3.8 [Programa](#38-programa)
4. [Estoque — Camada 1.5](#4-estoque--camada-15)
   - 4.1 [Insumo](#41-insumo)
   - 4.2 [InsumoLote](#42-insumolote)
   - 4.3 [MovimentacaoEstoque](#43-movimentacaoestoque)
5. [Clínico — Camada 1.5](#5-clínico--camada-15)
   - 5.1 [Prescricao](#51-prescricao)
   - 5.2 [PrescricaoItem](#52-prescricaoitem)
   - 5.3 [Anamnese](#53-anamnese)
   - 5.4 [Prontuario](#54-prontuario)
   - 5.5 [Documento](#55-documento)
6. [Instâncias do Paciente — Camada 2](#6-instâncias-do-paciente--camada-2)
   - 6.1 [Jornada](#61-jornada)
   - 6.2 [Sessao](#62-sessao)
7. [Operacional — Camada 3](#7-operacional--camada-3)
   - 7.1 [Agendamento](#71-agendamento)
   - 7.2 [Atendimento](#72-atendimento)
   - 7.3 [ProcedimentoRealizado](#73-procedimentorealizado)
8. [Financeiro — Camada 4](#8-financeiro--camada-4)
   - 8.1 [Orcamento](#81-orcamento)
   - 8.2 [OrcamentoItem](#82-orcamentoitem)
   - 8.3 [LancamentoFinanceiro](#83-lancamentofinanceiro)
9. [Relações Polimórficas e linkParent](#9-relações-polimórficas-e-linkparent)
10. [Mapa de Relacionamentos](#10-mapa-de-relacionamentos)
11. [Fluxos de Ciclo de Vida](#11-fluxos-de-ciclo-de-vida)
12. [Regras de Negócio por Entidade](#12-regras-de-negócio-por-entidade)
13. [Multi-unidade](#13-multi-unidade)
14. [Flexibilidade vs. Modelo Anterior](#14-flexibilidade-vs-modelo-anterior)
15. [Roadmap de Implementação](#15-roadmap-de-implementação)

---

## 1. Visão Geral e Camadas

```
┌──────────────────────────────────────────────────────────────────┐
│  SUPORTE (transversal a todas as camadas)                         │
│  Unidade · Profissional · Convenio · ConvenioRegra               │
├──────────────────────────────────────────────────────────────────┤
│  CAMADA 1 · CATÁLOGO                                              │
│  ProcedimentoBase (especializações) · TabelaDePrecos · Programa  │
├──────────────────────────────────────────────────────────────────┤
│  CAMADA 1.5 · ESTOQUE E CLÍNICO                                  │
│  Insumo · InsumoLote · MovimentacaoEstoque                       │
│  Prescricao · Anamnese · Prontuario · Documento                  │
├──────────────────────────────────────────────────────────────────┤
│  CAMADA 2 · INSTÂNCIAS DO PACIENTE                               │
│  Jornada · Sessao                                                 │
├──────────────────────────────────────────────────────────────────┤
│  CAMADA 3 · OPERACIONAL                                          │
│  Agendamento · Atendimento · ProcedimentoRealizado               │
├──────────────────────────────────────────────────────────────────┤
│  CAMADA 4 · FINANCEIRO                                           │
│  LancamentoFinanceiro                                             │
└──────────────────────────────────────────────────────────────────┘
```

### Princípios do modelo

- **Base Entity + especializações** — cada categoria de procedimento é uma entidade própria com campos, validações e formulários exclusivos. Sem enum + JSON.
- **`linkParent` para polimorfismo** — entidades que precisam apontar para "qualquer tipo de procedimento" usam `linkParent` nativo do EspoCRM.
- **Multi-unidade por escopo** — cada registro relevante carrega `unidadeId`, permitindo relatórios e permissões por unidade.
- **Histórico imutável** — preços, dosagens e valores cobrados são sempre desnormalizados no momento do evento. Nada é recalculado retroativamente.
- **Clínico separado do operacional** — `Prontuario` e `Anamnese` existem independentemente do ciclo de agendamento/atendimento.

---

## 2. Entidades de Suporte

Entidades transversais referenciadas em praticamente todas as camadas.

### 2.1 `Unidade`

Representa cada unidade física da clínica (Matriz, filiais).

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `nome` | varchar(255) | ✅ | Ex: Matriz Belém, Unidade Ananindeua |
| `cnpj` | varchar(20) | ✅ | CNPJ da unidade |
| `endereco` | text | ✅ | Endereço completo |
| `telefone` | varchar(20) | — | Contato principal |
| `email` | varchar(255) | — | Email da unidade |
| `responsavelId` | link | — | FK para `Profissional` responsável |
| `ativa` | bool | ✅ | Flag de operação |

**Uso:** toda entidade operacional carrega `unidadeId` — `Agendamento`, `Atendimento`, `Jornada`, `LancamentoFinanceiro`, `InsumoLote`. Isso permite filtros, relatórios e permissões por unidade sem necessidade de estruturas paralelas.

---

### 2.2 `Profissional`

Entidade rica que substitui o uso de `Users` diretamente nas relações clínicas.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `userId` | link | ✅ | FK para `Users` do EspoCRM (acesso ao sistema) |
| `nome` | varchar(255) | ✅ | Nome completo |
| `nomeCurto` | varchar(100) | — | Como aparece na agenda (ex: Dr Bruno) |
| `crm` | varchar(30) | — | Número do CRM (obrigatório para médicos) |
| `crmUf` | varchar(2) | — | UF do CRM |
| `especialidades` | multiEnum | — | Emagrecimento \| Dor \| Hormonal \| Estética \| Outro |
| `tipo` | enum | ✅ | Médico \| Enfermeiro \| Fisioterapeuta \| Personal \| Outro |
| `unidades` | linkMultiple | ✅ | Em quais unidades atua |
| `corAgenda` | varchar(7) | — | Cor hex para exibição na agenda |
| `duracaoPadraoConsultaMin` | int | — | Duração padrão para bloqueio de agenda |
| `ativo` | bool | ✅ | — |

**Regras:**
- Campos `crm` e `crmUf` obrigatórios quando `tipo = Médico`
- Agendamentos só podem ser criados para profissionais com `unidadeId` em comum com o agendamento

---

### 2.3 `Convenio`

Define planos e convênios aceitos pela clínica.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `nome` | varchar(255) | ✅ | Ex: IBM Família/Funcionário, Unimed |
| `tipo` | enum | ✅ | Corporativo \| Plano de Saúde \| Particular Especial |
| `cnpj` | varchar(20) | — | CNPJ da operadora |
| `contatoComercial` | varchar(255) | — | Nome do responsável no convênio |
| `emailContatoId` | link | — | Email para envio de faturas |
| `diaFechamento` | int | — | Dia do mês para fechamento de fatura |
| `prazoPagementoDias` | int | — | Prazo após fechamento |
| `ativo` | bool | ✅ | — |

---

### 2.4 `ConvenioRegra`

Define quais procedimentos são cobertos por cada convênio e sob quais condições — evita hardcode de lógica de cobrança.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `convenioId` | link | ✅ | FK para `Convenio` |
| `procedimentoType` | varchar | ✅ | `linkParent` type |
| `procedimentoId` | link | ✅ | `linkParent` id |
| `cobertura` | enum | ✅ | Total \| Parcial \| Não Coberto |
| `percentualCobertura` | decimal | — | Se parcial: % coberto pelo convênio |
| `valorFixo` | currency | — | Se cobertura fixa em vez de percentual |
| `limiteAnual` | int | — | Máximo de sessões cobertas por ano |
| `requerAutorizacao` | bool | ✅ | Exige autorização prévia do convênio |
| `vigenciaInicio` | date | ✅ | — |
| `vigenciaFim` | date | — | — |

---

## 3. Catálogo — Camada 1

### 3.1 Base Entity: `ProcedimentoBase`

Contrato de campos compartilhados. No EspoCRM, funciona como âncora dos `linkParent` — o `entityList` do `linkParent` lista todas as especializações.

| Campo | Tipo | Descrição |
|---|---|---|
| `nome` | varchar(255) | Nome do procedimento |
| `unidadeId` | link | Unidade que oferece este procedimento |
| `ativo` | bool | Visível no catálogo |
| `observacao` | text | Notas gerais |
| `criadoEm` | datetime | Audit |
| `modificadoEm` | datetime | Audit |

---

### 3.2 `ProcedimentoConsulta`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `nome` | varchar(255) | ✅ | Ex: Consulta do Emagrecimento |
| `especialidade` | enum | ✅ | Emagrecimento \| Dor \| Hormonal \| Geral \| IBM |
| `duracaoPadraoMin` | int | ✅ | Duração para bloqueio de agenda |
| `requerAnamnese` | bool | — | Exige anamnese antes da consulta |
| `requerCRM` | bool | ✅ | Profissional deve ter CRM |
| `ehRetorno` | bool | — | É uma consulta de retorno |
| `intervaloRetornoDias` | int | — | Prazo sugerido para retorno |
| `unidadeId` | link | ✅ | — |
| `ativo` | bool | ✅ | — |

**Regras:**
- Bloqueia agendamento se `requerCRM = true` e profissional sem CRM
- Se `ehRetorno = true`, valida consulta inicial prévia na `Jornada`
- Cria tarefa de retorno após realização quando `intervaloRetornoDias` preenchido

---

### 3.3 `ProcedimentoInjetavel`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `nome` | varchar(255) | ✅ | Ex: Tirzepatida, Hybrius, Supra |
| `viaAdministracao` | enum | ✅ | EV \| IM \| SC \| Oral |
| `dosagemPadrao` | decimal | — | Dose padrão sugerida |
| `unidadeDosagem` | enum | ✅ | mg \| ml \| UI \| mcg |
| `dosagemMinima` | decimal | — | Mínimo permitido |
| `dosagemMaxima` | decimal | — | Máximo permitido |
| `requerPrescricao` | bool | ✅ | Exige prescrição ativa |
| `insumoId` | link | — | FK para `Insumo` (se controla estoque) |
| `controlaEstoque` | bool | ✅ | Consome `InsumoLote` ao realizar |
| `tempoAplicacaoMin` | int | — | Duração média |
| `protocolo` | text | — | Descrição clínica do protocolo |
| `unidadeId` | link | ✅ | — |
| `ativo` | bool | ✅ | — |

**Regras:**
- Bloqueia agendamento sem `Prescricao` ativa se `requerPrescricao = true`
- Valida `dosagemAplicada` da `Sessao` dentro de `[dosagemMinima, dosagemMaxima]`
- Ao realizar, cria `MovimentacaoEstoque` de saída se `controlaEstoque = true`

---

### 3.4 `ProcedimentoImplante`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `nome` | varchar(255) | ✅ | Ex: Implante - Testosterona |
| `substanciaAtiva` | varchar(255) | ✅ | Testosterona, Estradiol, Oxandrolona... |
| `forma` | enum | ✅ | Silástico \| Pellet \| Outro |
| `dosagemMg` | decimal | ✅ | Dosagem padrão em mg |
| `validadeEstimadaDias` | int | ✅ | Prazo até renovação |
| `requerSalaCirurgica` | bool | ✅ | — |
| `requerCRM` | bool | ✅ | — |
| `insumoId` | link | — | FK para `Insumo` se controla lote |
| `protocoloAplicacao` | text | — | — |
| `unidadeId` | link | ✅ | — |
| `ativo` | bool | ✅ | — |

**Regras:**
- Ao realizar, cria tarefa de renovação: `dataRealizacao + validadeEstimadaDias`
- Registra no `Prontuario` do paciente: substância, dosagem, lote e data
- Bloqueia se profissional sem CRM

---

### 3.5 `ProcedimentoEstetico`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `nome` | varchar(255) | ✅ | Ex: Drenagem, Eletrofit, Shape Space, Ventosa |
| `subtipo` | enum | ✅ | Corporal \| Facial \| Capilar \| Outro |
| `duracaoMin` | int | ✅ | — |
| `equipamentoNecessario` | varchar(255) | — | Sala ou equipamento |
| `requerAvaliacaoPrevia` | bool | — | Exige consulta prévia |
| `maximoSessoesSemana` | int | — | Limite de frequência |
| `unidadeId` | link | ✅ | — |
| `ativo` | bool | ✅ | — |

**Regras:**
- Confirma automaticamente 24h antes via workflow
- Alerta se `maximoSessoesSemana` excedido ao agendar
- Se `requerAvaliacaoPrevia = true`, valida consulta prévia na `Jornada`

---

### 3.6 `ProcedimentoAtividadeFisica`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `nome` | varchar(255) | ✅ | Ex: Academia, Pilates, Personal |
| `modalidade` | enum | ✅ | Academia \| Pilates \| Personal \| Funcional \| Outro |
| `duracaoMin` | int | ✅ | — |
| `requerAvaliacaoFisica` | bool | — | — |
| `nivelIntensidade` | enum | — | Leve \| Moderado \| Intenso |
| `unidadeId` | link | ✅ | — |
| `ativo` | bool | ✅ | — |

**Regras:**
- Não permite `LancamentoFinanceiro` avulso — só via `Jornada`
- Bloqueia agendamento sem avaliação física se `requerAvaliacaoFisica = true`

---

### 3.7 `TabelaDePrecos`

Pivô M:N entre `Profissional` × qualquer especialização de procedimento. Usa `linkParent`.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `profissionalId` | link | ✅ | FK para `Profissional` |
| `procedimentoType` | varchar | ✅ | `linkParent` type |
| `procedimentoId` | link | ✅ | `linkParent` id |
| `preco` | currency | ✅ | Valor cobrado |
| `vigenciaInicio` | date | ✅ | — |
| `vigenciaFim` | date | — | Nulo = vigente |
| `modalidade` | enum | ✅ | Presencial \| Online \| Domiciliar |
| `convenioId` | link | — | Se preço específico para convênio |
| `unidadeId` | link | ✅ | Preço pode variar por unidade |
| `ativo` | bool | ✅ | — |

**Regras:**
- Ao criar nova vigência, expira automaticamente a anterior para a mesma combinação `(profissional, procedimento, modalidade, unidade, convênio)`
- Histórico nunca deletado — apenas expirado

---

### 3.8 `Programa`

Template de pacote de serviços.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `nome` | varchar(255) | ✅ | Ex: Plano Desmame com Retorno |
| `descricao` | text | — | Descrição comercial |
| `precoTotal` | currency | ✅ | Preço do pacote |
| `validadeDias` | int | ✅ | Prazo de consumo após aquisição |
| `unidadeId` | link | ✅ | — |
| `ativo` | bool | ✅ | — |

**`ProgramaItem`** — entidade filha:

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `programaId` | link | ✅ | FK para `Programa` |
| `procedimentoType` | varchar | ✅ | `linkParent` type |
| `procedimentoId` | link | ✅ | `linkParent` id |
| `quantidade` | int | ✅ | Sessões incluídas |
| `ordem` | int | — | Sequência sugerida |
| `observacao` | varchar | — | Instrução específica |

---

## 4. Estoque — Camada 1.5

### 4.1 `Insumo`

Cadastro master do insumo — o que é o produto, não o que tem em estoque.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `nome` | varchar(255) | ✅ | Ex: Tirzepatida, Ácido Lipoico, Hybrius |
| `descricao` | text | — | — |
| `tipo` | enum | ✅ | Medicamento \| Cosmético \| Material \| Outro |
| `unidadeMedida` | enum | ✅ | mg \| ml \| UI \| unidade \| caixa |
| `estoqueMinimo` | decimal | — | Nível para alerta de reposição |
| `fornecedorPrincipal` | varchar(255) | — | Nome do fornecedor |
| `requerReceituario` | bool | — | Necessita receituário especial |
| `ativo` | bool | ✅ | — |

---

### 4.2 `InsumoLote`

Cada entrada de estoque com rastreabilidade por lote.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `insumoId` | link | ✅ | FK para `Insumo` |
| `unidadeId` | link | ✅ | Em qual unidade está o lote |
| `numeroLote` | varchar(100) | ✅ | Código do lote do fabricante |
| `dataFabricacao` | date | — | — |
| `dataValidade` | date | ✅ | Vencimento do lote |
| `quantidadeEntrada` | decimal | ✅ | Quantidade ao entrar |
| `quantidadeAtual` | decimal | ✅ | Saldo atual (atualizado a cada movimentação) |
| `fornecedor` | varchar(255) | — | Fornecedor desta compra |
| `notaFiscal` | varchar(100) | — | NF de entrada |
| `precoUnitarioCusto` | currency | — | Custo unitário para fins gerenciais |
| `status` | enum | ✅ | Disponível \| Vencido \| Esgotado \| Bloqueado |

**Regras:**
- Alerta automático quando `dataValidade` < 30 dias
- Alerta quando `quantidadeAtual` < `Insumo.estoqueMinimo`
- Status atualizado automaticamente para `Vencido` quando `dataValidade` < hoje

---

### 4.3 `MovimentacaoEstoque`

Registro imutável de toda entrada ou saída de insumo.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `insumoLoteId` | link | ✅ | FK para `InsumoLote` |
| `unidadeId` | link | ✅ | — |
| `tipo` | enum | ✅ | Entrada \| Saída \| Ajuste \| Descarte |
| `quantidade` | decimal | ✅ | — |
| `origemType` | varchar | — | `linkParent` type: `ProcedimentoRealizado` \| `Compra` \| `Ajuste` |
| `origemId` | link | — | `linkParent` id |
| `profissionalId` | link | — | Quem realizou a movimentação |
| `dataHora` | datetime | ✅ | — |
| `observacao` | text | — | Motivo do ajuste ou descarte |

**Regra:** `InsumoLote.quantidadeAtual` é sempre calculado como soma das movimentações — nunca editado diretamente.

---

## 5. Clínico — Camada 1.5

### 5.1 `Prescricao`

Receita médica estruturada, vinculada ao paciente e ao médico prescritor.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `pacienteId` | link | ✅ | — |
| `medicoId` | link | ✅ | FK para `Profissional` com CRM |
| `unidadeId` | link | ✅ | — |
| `dataEmissao` | date | ✅ | — |
| `dataValidade` | date | ✅ | Até quando é válida |
| `status` | enum | ✅ | Ativa \| Expirada \| Cancelada |
| `tipo` | enum | ✅ | Simples \| Especial \| Controle Especial |
| `observacoes` | text | — | Orientações gerais da prescrição |
| `documentoId` | link | — | FK para `Documento` (arquivo da receita digitalizada) |

**Relações:**
- `hasMany` → `PrescricaoItem`

---

### 5.2 `PrescricaoItem`

Cada item prescrito dentro de uma receita.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `prescricaoId` | link | ✅ | — |
| `insumoId` | link | — | FK para `Insumo` (quando aplicável) |
| `procedimentoType` | varchar | — | `linkParent`: aponta para o procedimento prescrito |
| `procedimentoId` | link | — | `linkParent` id |
| `dosagem` | varchar(100) | ✅ | Ex: 5mg, 2x por semana |
| `quantidade` | int | ✅ | Quantidade prescrita |
| `instrucoes` | text | — | Como administrar |

---

### 5.3 `Anamnese`

Questionário clínico inicial do paciente. Estruturado para permitir evolução ao longo do tempo.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `pacienteId` | link | ✅ | — |
| `profissionalId` | link | ✅ | Quem coletou |
| `unidadeId` | link | ✅ | — |
| `dataColeta` | date | ✅ | — |
| `versao` | int | ✅ | Controle de versão (1, 2, 3...) |
| `queixaPrincipal` | text | — | Motivo do atendimento |
| `historicoDoenças` | text | — | Doenças pré-existentes |
| `medicamentosEmUso` | text | — | Lista atual |
| `alergias` | text | — | Alergias conhecidas |
| `historicoFamiliar` | text | — | Relevante para o tratamento |
| `habitosAlimentares` | text | — | — |
| `nivelAtividadeFisica` | enum | — | Sedentário \| Pouco ativo \| Ativo \| Muito ativo |
| `objetivos` | text | — | O que o paciente busca |
| `observacoes` | text | — | Notas gerais |
| `jornadaId` | link | — | Jornada que originou esta anamnese |

**Regra:** Cada nova versão cria novo registro — o histórico de anamneses nunca é sobrescrito.

---

### 5.4 `Prontuario`

Registro clínico evolutivo do paciente. Cada `Atendimento` pode gerar uma entrada no prontuário, mas entradas também podem ser criadas manualmente.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `pacienteId` | link | ✅ | — |
| `profissionalId` | link | ✅ | Quem registrou |
| `unidadeId` | link | ✅ | — |
| `dataHora` | datetime | ✅ | — |
| `tipo` | enum | ✅ | Consulta \| Procedimento \| Evolução \| Exame \| Implante \| Observação |
| `titulo` | varchar(255) | ✅ | Resumo da entrada |
| `conteudo` | text | ✅ | Descrição clínica detalhada |
| `atendimentoId` | link | — | Atendimento de origem (se houver) |
| `jornadaId` | link | — | Jornada de contexto |
| `documentos` | linkMultiple | — | FKs para `Documento` |
| `confidencial` | bool | — | Visível apenas para médicos |

---

### 5.5 `Documento`

Repositório de arquivos vinculados a pacientes — exames, fotos, receitas digitalizadas, laudos, antes/depois.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `pacienteId` | link | ✅ | — |
| `nome` | varchar(255) | ✅ | Nome descritivo do arquivo |
| `tipo` | enum | ✅ | Exame \| Foto \| Receita \| Laudo \| Contrato \| Outro |
| `subtipo` | varchar(100) | — | Ex: Antes/Depois, USG, Hemograma |
| `arquivo` | attachment | ✅ | O arquivo em si (EspoCRM Attachments) |
| `dataDocumento` | date | — | Data do documento (não do upload) |
| `profissionalId` | link | — | Quem gerou ou recebeu |
| `unidadeId` | link | ✅ | — |
| `origemType` | varchar | — | `linkParent`: `Atendimento` \| `Prescricao` \| `Anamnese` \| `Prontuario` |
| `origemId` | link | — | `linkParent` id |
| `confidencial` | bool | — | Acesso restrito |
| `observacao` | text | — | — |

**Uso no contexto da paciente Rana:**
```
Tipo: Foto · Subtipo: Antes/Depois
Tipo: Exame · Subtipo: USG (vinculado ao Atendimento de 02/03/2026)
Tipo: Exame · Subtipo: Bioimpedância
Tipo: Receita · linkParent: Prescricao (tirzepatida)
```

---

## 6. Instâncias do Paciente — Camada 2

### 6.1 `Jornada`

Instância viva de um `Programa` para um paciente específico.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `pacienteId` | link | ✅ | — |
| `programaId` | link | — | Template de origem (pode ser nulo) |
| `profissionalPrincipalId` | link | — | Quem conduz |
| `unidadeId` | link | ✅ | — |
| `convenioId` | link | — | Convênio desta jornada |
| `nome` | varchar(255) | ✅ | Gerado ou personalizado |
| `objetivo` | text | — | Meta clínica |
| `dataInicio` | date | ✅ | — |
| `dataExpiracao` | date | — | `dataInicio + Programa.validadeDias` |
| `status` | enum | ✅ | Em Andamento \| Pausada \| Concluída \| Abandonada |
| `motivoPausa` | text | — | Preenchido quando pausada |
| `evolucaoClinica` | text | — | Anotações gerais do processo |

**Relações:**
- `hasMany` → `Sessao`
- `hasMany` → `LancamentoFinanceiro` (via `linkParent`)
- `hasMany` → `Prontuario`
- `hasMany` → `Anamnese`

**Regras:**
- Ao criar a partir de `Programa`, gera `Sessao` para cada `ProgramaItem`
- Permite `Sessao` avulsa sem `Programa` vinculado
- Sugere `status = Concluída` quando todas `Sessao` = `Realizada`

---

### 6.2 `Sessao`

Crédito individual dentro de uma `Jornada`.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `jornadaId` | link | ✅ | — |
| `procedimentoType` | varchar | ✅ | `linkParent` type |
| `procedimentoId` | link | ✅ | `linkParent` id |
| `sequencia` | int | — | Número dentro da jornada |
| `status` | enum | ✅ | Disponível \| Agendada \| Realizada \| Expirada \| Cancelada |
| `atendimentoId` | link | — | Preenchido quando realizada |
| `dosagemAplicada` | decimal | — | Para injetáveis: sobrescreve padrão |
| `unidadeDosagem` | enum | — | mg \| ml \| UI |
| `insumoloteId` | link | — | Lote específico usado nesta sessão |
| `observacao` | text | — | — |
| `dataExpiracao` | date | — | Pode ser individualizada |
| `unidadeId` | link | ✅ | — |

> `dosagemAplicada` é o campo que torna a curva de desmame da tirzepatida (2,5→5→7,5→3→1,5mg) estruturada e reportável — sem depender de texto livre.

---

## 7. Operacional — Camada 3

### 7.1 `Agendamento`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `pacienteId` | link | ✅ | — |
| `profissionalId` | link | ✅ | — |
| `unidadeId` | link | ✅ | — |
| `procedimentoType` | varchar | ✅ | `linkParent` type |
| `procedimentoId` | link | ✅ | `linkParent` id |
| `sessaoId` | link | — | FK para `Sessao` (se vier de Jornada) |
| `convenioId` | link | — | — |
| `dataHora` | datetime | ✅ | — |
| `duracaoPrevistaMin` | int | — | Herdado do procedimento |
| `status` | enum | ✅ | Agendado \| Confirmado \| Realizado \| Faltou \| Cancelado \| Desmarcado |
| `canalAgendamento` | enum | — | Telefone \| WhatsApp \| App \| Presencial |
| `atendimentoId` | link | — | Preenchido ao realizar |
| `observacao` | text | — | — |

**Regras:**
- Valida conflito de agenda do profissional
- Valida profissional ativo na `unidadeId` informada
- Ao marcar `Realizado`: cria `Atendimento` automaticamente
- Se `sessaoId` preenchido: atualiza `Sessao.status = Agendada`
- Se `ProcedimentoInjetavel.requerPrescricao = true`: valida `Prescricao` ativa do paciente

---

### 7.2 `Atendimento`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `pacienteId` | link | ✅ | — |
| `profissionalId` | link | ✅ | — |
| `unidadeId` | link | ✅ | — |
| `agendamentoId` | link | — | Origem (opcional) |
| `convenioId` | link | — | — |
| `dataHoraInicio` | datetime | ✅ | — |
| `dataHoraFim` | datetime | — | — |
| `duracaoRealMin` | int | — | Calculado automaticamente |
| `anotacoesClinicas` | text | — | Registro do profissional |
| `status` | enum | ✅ | Realizado \| Parcial \| Cancelado |

**Relações:**
- `hasMany` → `ProcedimentoRealizado`
- `hasMany` → `LancamentoFinanceiro` (via `linkParent`)
- `hasMany` → `Prontuario`
- `hasMany` → `Documento`

---

### 7.3 `ProcedimentoRealizado`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `atendimentoId` | link | ✅ | — |
| `procedimentoType` | varchar | ✅ | `linkParent` type |
| `procedimentoId` | link | ✅ | `linkParent` id |
| `tabelaDePrecosId` | link | — | Preço vigente no momento |
| `valorCobrado` | currency | ✅ | Snapshot histórico |
| `quantidade` | int | ✅ | Padrão 1 |
| `dosagemAplicada` | decimal | — | Para injetáveis |
| `insumoloteId` | link | — | Lote consumido |
| `sessaoId` | link | — | Consome `Sessao` da `Jornada` |
| `observacao` | text | — | — |

**Regras:**
- Copia `preco` da `TabelaDePrecos` para `valorCobrado` ao salvar
- Se `sessaoId`: atualiza `Sessao.status = Realizada`
- Se `controlaEstoque = true`: cria `MovimentacaoEstoque` de saída com `insumoloteId`

---

## 8. Financeiro — Camada 4

`Orçamento` e `LancamentoFinanceiro` são entidades distintas com responsabilidades diferentes:

```
Orcamento  ──(aprovado)──→  Jornada
                         ──→  LancamentoFinanceiro
```

`Orçamento` é um pré-compromisso comercial — existe antes de qualquer obrigação financeira ou clínica. Só quando aprovado é que gera `Jornada` e `LancamentoFinanceiro`. Isso evita lançamentos financeiros fantasmas de orçamentos recusados ou expirados, e permite rastrear a taxa de conversão comercial.

---

### 8.1 `Orcamento`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `pacienteId` | link | ✅ | — |
| `profissionalId` | link | — | Quem elaborou |
| `unidadeId` | link | ✅ | — |
| `convenioId` | link | — | — |
| `numero` | varchar(20) | ✅ | Numeração sequencial |
| `versao` | int | ✅ | Controle de revisões (1, 2, 3...) |
| `orcamentoOrigemId` | link | — | FK para versão anterior (se revisão) |
| `status` | enum | ✅ | Rascunho \| Enviado \| Aprovado \| Expirado \| Recusado |
| `dataEmissao` | date | ✅ | — |
| `dataValidade` | date | ✅ | Até quando o preço é garantido |
| `valorTotal` | currency | ✅ | Soma dos itens |
| `valorDesconto` | currency | — | — |
| `valorLiquido` | currency | ✅ | `valorTotal - valorDesconto` |
| `autorizadoPorId` | link | — | Quem autorizou desconto |
| `observacoes` | text | — | Condições comerciais |
| `motivoRecusa` | text | — | Preenchido se Recusado |
| `jornadaId` | link | — | Preenchido quando aprovado e Jornada gerada |
| `lancamentoId` | link | — | Preenchido quando aprovado e lançamento gerado |

**Ciclo de vida:**
```
Rascunho
  └─→ Enviado (encaminhado ao paciente)
        ├─→ Aprovado
        │     └─→ Jornada gerada automaticamente (com Sessoes dos itens)
        │     └─→ LancamentoFinanceiro gerado (status: Pendente)
        ├─→ Recusado (motivoRecusa obrigatório)
        └─→ Expirado (scheduled job quando dataValidade < hoje)
```

**Regras:**
- Ao aprovar: cria `Jornada` com `Sessao` para cada `OrcamentoItem`, depois cria `LancamentoFinanceiro` com `origemType: Orcamento`
- Nova versão de orçamento: cria novo registro com `versao + 1` e `orcamentoOrigemId` apontando para a versão anterior — a anterior é marcada como `Recusado` automaticamente
- Scheduled job expira orçamentos com `dataValidade < hoje` e `status = Enviado`
- Exige `autorizadoPorId` quando `valorDesconto > 0`

---

### 8.2 `OrcamentoItem`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `orcamentoId` | link | ✅ | — |
| `procedimentoType` | varchar | ✅ | `linkParent` type |
| `procedimentoId` | link | ✅ | `linkParent` id |
| `quantidade` | int | ✅ | — |
| `valorUnitario` | currency | ✅ | Snapshot da `TabelaDePrecos` no momento |
| `valorTotal` | currency | ✅ | Calculado: `quantidade × valorUnitario` |
| `desconto` | decimal | — | % de desconto neste item |
| `valorComDesconto` | currency | — | Valor final do item |
| `observacao` | varchar | — | — |

**Como aparece nos dados da Rana:**
```
Orçamento 06/02/2026  R$ 2.700,00  → status: Enviado (não aprovado)
  └─→ Nenhum LancamentoFinanceiro criado ainda

Orçamento 06/03/2026  R$ 6.693,00  → status: Enviado (não aprovado)
  └─→ Nenhum LancamentoFinanceiro criado ainda

# Contraste com orçamentos aprovados:
Orçamento 30/07/2025  R$ 18.950,00 → status: Aprovado
  └─→ jornadaId: [Jornada Plano 2 meses]
  └─→ lancamentoId: [Lançamento R$ 18.950,00 · Pago]
```

---

### 8.3 `LancamentoFinanceiro`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `pacienteId` | link | ✅ | — |
| `unidadeId` | link | ✅ | — |
| `convenioId` | link | — | — |
| `origemType` | varchar | ✅ | `linkParent`: `Atendimento` \| `Jornada` \| `Orcamento` |
| `origemId` | link | ✅ | `linkParent` id |
| `tipo` | enum | ✅ | Receita \| Estorno \| Desconto \| Bônus |
| `valorTotal` | currency | ✅ | — |
| `valorDesconto` | currency | — | — |
| `valorLiquido` | currency | ✅ | `valorTotal - valorDesconto` |
| `percentualConvenio` | decimal | — | % coberto pelo convênio |
| `valorConvenio` | currency | — | Parte paga pelo convênio |
| `valorPaciente` | currency | — | Parte paga pelo paciente |
| `formaPagamento` | enum | ✅ | Pix \| Cartão \| Dinheiro \| Convênio \| Boleto |
| `parcelas` | int | — | — |
| `status` | enum | ✅ | Pendente \| Pago \| Vencido \| Estornado |
| `dataVencimento` | date | ✅ | — |
| `dataPagamento` | date | — | — |
| `autorizadoPorId` | link | — | Quem autorizou o desconto |
| `observacao` | text | — | Ex: "Desconto concedido pelo Dr Bruno" |

**Uso do `linkParent` de origem:**
```
# Pacote pago na aquisição
origemType: "Jornada"
origemId:   [id da Jornada]

# Consulta avulsa
origemType: "Atendimento"
origemId:   [id do Atendimento]

# Aprovação direta de orçamento (procedimento pontual sem Jornada)
origemType: "Orcamento"
origemId:   [id do Orcamento]
```

**Regras:**
- Exige `autorizadoPorId` quando `valorDesconto > 0`
- Quando `convenioId` preenchido, calcula `valorConvenio` e `valorPaciente` via `ConvenioRegra`

---

## 9. Relações Polimórficas e `linkParent`

### O que é `linkParent` no EspoCRM

Mecanismo nativo para relações polimórficas. Armazena dois campos:
- `{campo}Type` — nome da entidade relacionada
- `{campo}Id` — ID do registro

Permite que um campo aponte para entidades diferentes dependendo do contexto.

### Mapa completo de `linkParent` no modelo

| Entidade | Campo `linkParent` | Aponta para |
|---|---|---|
| `TabelaDePrecos` | `procedimento` | Qualquer `Procedimento*` |
| `ConvenioRegra` | `procedimento` | Qualquer `Procedimento*` |
| `ProgramaItem` | `procedimento` | Qualquer `Procedimento*` |
| `PrescricaoItem` | `procedimento` | Qualquer `Procedimento*` |
| `Sessao` | `procedimento` | Qualquer `Procedimento*` |
| `Agendamento` | `procedimento` | Qualquer `Procedimento*` |
| `ProcedimentoRealizado` | `procedimento` | Qualquer `Procedimento*` |
| `MovimentacaoEstoque` | `origem` | `ProcedimentoRealizado` \| `Compra` \| `Ajuste` |
| `LancamentoFinanceiro` | `origem` | `Atendimento` \| `Jornada` \| `Orcamento` |
| `OrcamentoItem` | `procedimento` | Qualquer `Procedimento*` |
| `Documento` | `origem` | `Atendimento` \| `Prescricao` \| `Anamnese` \| `Prontuario` |

### Configuração no `metadata/entityDefs`

```json
// Campo procedimento em Sessao (exemplo aplicável a todas as entidades acima)
"procedimentoId": {
    "type": "foreignId",
    "index": true
},
"procedimentoType": {
    "type": "foreign",
    "notNull": false
},
"procedimento": {
    "type": "linkParent",
    "entityList": [
        "ProcedimentoConsulta",
        "ProcedimentoInjetavel",
        "ProcedimentoImplante",
        "ProcedimentoEstetico",
        "ProcedimentoAtividadeFisica"
    ]
}
```

### Relação inversa nas especializações

```json
// Em ProcedimentoInjetavel/metadata/entityDefs
"sessoes": {
    "type": "hasChildren",
    "entity": "Sessao",
    "foreignKey": "procedimentoId",
    "foreignType": "procedimentoType"
},
"agendamentos": {
    "type": "hasChildren",
    "entity": "Agendamento",
    "foreignKey": "procedimentoId",
    "foreignType": "procedimentoType"
}
```

---

## 10. Mapa de Relacionamentos

```
SUPORTE (transversal)
──────────────────────────────────────────────────────────────────────
  Unidade ←── (unidadeId em todas as entidades operacionais)
  Profissional ←── Agendamento, Atendimento, TabelaDePrecos,
                   Prescricao, Prontuario, Anamnese
  Convenio ←── ConvenioRegra ──→ linkParent procedimento
           ←── Jornada, Atendimento, Agendamento, LancamentoFinanceiro


CATÁLOGO + ESTOQUE + CLÍNICO
──────────────────────────────────────────────────────────────────────
  ProcedimentoConsulta   ──┐
  ProcedimentoInjetavel  ──┤ linkParent ──→ TabelaDePrecos ←── Profissional
  ProcedimentoImplante   ──┤             ──→ ProgramaItem  ←── Programa
  ProcedimentoEstetico   ──┤             ──→ ConvenioRegra ←── Convenio
  ProcedimentoAtivFisica ──┤             ──→ Sessao
                           ┤             ──→ Agendamento
                           ┘             ──→ ProcedimentoRealizado
                                         ──→ PrescricaoItem

  ProcedimentoInjetavel ──→ Insumo ──→ InsumoLote ──→ MovimentacaoEstoque
  ProcedimentoImplante  ──→ Insumo

  Paciente ──→ Prescricao ──→ PrescricaoItem
          ──→ Anamnese
          ──→ Prontuario ──→ Documento
          ──→ Documento


INSTÂNCIAS
──────────────────────────────────────────────────────────────────────
  Paciente ──→ Jornada ──→ Sessao ──→ linkParent procedimento
                  │            └──→ Atendimento (quando realizada)
                  │            └──→ InsumoLote (lote usado)
                  ├──→ Programa
                  ├──→ Anamnese
                  ├──→ Prontuario
                  └──→ LancamentoFinanceiro (linkParent origem)


OPERACIONAL
──────────────────────────────────────────────────────────────────────
  Agendamento ──→ Atendimento ──→ ProcedimentoRealizado
       │               │                 ├──→ linkParent procedimento
       │               │                 ├──→ TabelaDePrecos
       │               │                 └──→ MovimentacaoEstoque
       │               ├──→ Prontuario
       │               ├──→ Documento
       │               └──→ LancamentoFinanceiro (linkParent origem)
       ├──→ Sessao
       └──→ linkParent procedimento


FINANCEIRO
──────────────────────────────────────────────────────────────────────
  Orcamento ──→ OrcamentoItem ──→ linkParent procedimento
       │    ──→ Paciente
       │    ──→ (aprovado) ──→ Jornada
       │                   ──→ LancamentoFinanceiro
       │
  LancamentoFinanceiro
       ├──→ Paciente
       ├──→ Unidade
       ├──→ Convenio
       ├──→ (linkParent origem) Atendimento  — cobrança avulsa
       ├──→ (linkParent origem) Jornada      — pacote antecipado
       └──→ (linkParent origem) Orcamento    — aprovação direta
```

---

## 11. Fluxos de Ciclo de Vida

### Fluxo A — Paciente adquire Programa

```
1. Paciente adquire "Plano Desmame com Retorno"
   └─→ Jornada criada (Em Andamento, unidade, convênio)
         └─→ Sessoes geradas por ProgramaItem
         └─→ Anamnese solicitada (se ProcedimentoConsulta.requerAnamnese)
         └─→ LancamentoFinanceiro (origem: Jornada, status: Pendente)

2. Agendamento de sessão de tirzepatida
   └─→ Valida Prescricao ativa
   └─→ Valida saldo de Sessao disponível
   └─→ Sessao.status = Agendada

3. Paciente comparece
   └─→ Atendimento criado
         └─→ ProcedimentoRealizado
               └─→ dosagemAplicada: 2,5mg
               └─→ valorCobrado: snapshot TabelaDePrecos
               └─→ MovimentacaoEstoque (saída do InsumoLote)
               └─→ Sessao.status = Realizada
         └─→ Prontuario: entrada criada automaticamente

4. Próxima sessão — evolução de dose
   └─→ dosagemAplicada: 5mg (atualizado pelo profissional)

5. Todas as sessões realizadas
   └─→ Sistema sugere Jornada.status = Concluída
```

### Fluxo B — Implante com renovação

```
1. ProcedimentoRealizado (ProcedimentoImplante)
   └─→ Prontuario: substância, dosagem, lote, data
   └─→ Tarefa de renovação: dataRealizacao + validadeEstimadaDias
   └─→ MovimentacaoEstoque (saída do lote do implante)
```

### Fluxo C — Consulta avulsa com convênio

```
1. Agendamento criado (sem Sessao)
   └─→ ConvenioRegra consultada: cobertura parcial 60%

2. Atendimento realizado
   └─→ LancamentoFinanceiro gerado
         └─→ valorConvenio: 60% do valor
         └─→ valorPaciente: 40% restante
         └─→ Prontuario: entrada criada
```

### Fluxo D — Controle de estoque

```
1. InsumoLote cadastrado (entrada de tirzepatida)
   └─→ MovimentacaoEstoque tipo: Entrada

2. ProcedimentoRealizado com injetável
   └─→ MovimentacaoEstoque tipo: Saída
         └─→ InsumoLote.quantidadeAtual decrementado

3. Alerta automático quando:
   └─→ quantidadeAtual < Insumo.estoqueMinimo
   └─→ dataValidade < 30 dias
```

### Fluxo E — Orçamento aprovado vira Jornada

```
1. Recepção elabora Orçamento (status: Rascunho)
   └─→ OrcamentoItem: Tirzepatida × 13, Supra × 10, Consulta × 1
   └─→ valorTotal calculado, desconto autorizado pelo Dr Bruno

2. Orçamento enviado ao paciente (status: Enviado)
   └─→ Nenhum LancamentoFinanceiro criado ainda
   └─→ dataValidade: 15 dias

3a. Paciente aprova
   └─→ Orcamento.status = Aprovado
         └─→ Jornada criada automaticamente com Sessoes dos OrcamentoItems
         └─→ LancamentoFinanceiro gerado (origemType: Orcamento)
         └─→ Orcamento.jornadaId e Orcamento.lancamentoId preenchidos

3b. Paciente pede revisão
   └─→ Nova versão do Orçamento criada (versao: 2)
         └─→ Versão anterior arquivada (status: Recusado)
         └─→ orcamentoOrigemId aponta para versão 1

3c. Paciente não responde
   └─→ Scheduled job expira orçamento quando dataValidade < hoje
```



| Entidade | Regra | Trigger |
|---|---|---|
| `ProcedimentoConsulta` | Bloqueia se profissional sem CRM | Antes de salvar Agendamento |
| `ProcedimentoConsulta` | Alerta de retorno após X dias | Após realizar Atendimento |
| `ProcedimentoConsulta` | Solicita Anamnese se primeira consulta | Ao criar Jornada |
| `ProcedimentoInjetavel` | Bloqueia sem Prescricao ativa | Antes de salvar Agendamento |
| `ProcedimentoInjetavel` | Valida dosagem no intervalo permitido | Antes de salvar Sessao |
| `ProcedimentoInjetavel` | Cria MovimentacaoEstoque de saída | Após salvar ProcedimentoRealizado |
| `ProcedimentoImplante` | Cria tarefa de renovação | Após salvar ProcedimentoRealizado |
| `ProcedimentoImplante` | Registra no Prontuario | Após salvar ProcedimentoRealizado |
| `ProcedimentoImplante` | Bloqueia se profissional sem CRM | Antes de salvar Agendamento |
| `ProcedimentoEstetico` | Confirma automaticamente 24h antes | Scheduled job diário |
| `ProcedimentoEstetico` | Alerta se excede limite semanal | Antes de salvar Agendamento |
| `ProcedimentoAtivFisica` | Bloqueia LancamentoFinanceiro avulso | Antes de salvar Lançamento |
| `InsumoLote` | Alerta de vencimento próximo | Scheduled job diário |
| `InsumoLote` | Alerta de estoque mínimo | Após MovimentacaoEstoque de saída |
| `Prescricao` | Expira automaticamente após dataValidade | Scheduled job diário |
| `Jornada` | Gera Sessoes ao criar de Programa | Após salvar Jornada |
| `Jornada` | Sugere conclusão quando todas Sessoes realizadas | Após atualizar Sessao |
| `Orcamento` | Expira automaticamente quando `dataValidade < hoje` | Scheduled job diário |
| `Orcamento` | Exige autorização para desconto > 0 | Antes de salvar |
| `Orcamento` | Ao aprovar: gera `Jornada` + `LancamentoFinanceiro` | Após atualizar status para Aprovado |
| `Orcamento` | Nova versão arquiva a anterior como Recusado | Antes de salvar nova versão |
| `TabelaDePrecos` | Expira vigência anterior ao criar nova | Antes de salvar |
| `LancamentoFinanceiro` | Exige autorizadoPorId quando desconto > 0 | Antes de salvar |
| `LancamentoFinanceiro` | Calcula split convênio/paciente | Antes de salvar com convenioId |
| `Agendamento` | Valida conflito de agenda | Antes de salvar |
| `Agendamento` | Valida profissional na unidade | Antes de salvar |
| `Agendamento` | Cria Atendimento ao marcar Realizado | Após atualizar status |

---

## 13. Multi-unidade

### Princípio

Cada entidade operacional carrega `unidadeId`. Não há estrutura paralela de dados por unidade — tudo está no mesmo banco, filtrado por `unidadeId`.

### Impacto por entidade

| Entidade | Comportamento multi-unidade |
|---|---|
| `Profissional` | `linkMultiple` para unidades — pode atuar em mais de uma |
| `TabelaDePrecos` | Preço pode variar por unidade para o mesmo profissional |
| `InsumoLote` | Estoque é por unidade — transferência gera duas `MovimentacaoEstoque` |
| `Agendamento` | Filtragem da agenda por unidade |
| `Jornada` | Jornada pertence a uma unidade, mas paciente pode ter jornadas em unidades diferentes |
| `LancamentoFinanceiro` | Receita contabilizada por unidade |
| `Programa` | Programas podem ser da Matriz e replicados para filiais ou específicos por unidade |

### Permissões por unidade no EspoCRM

```
# Roles sugeridos
Admin Matriz         → acesso total a todas as unidades
Gerente Unidade      → acesso à própria unidade + relatórios consolidados (leitura)
Profissional         → acesso apenas aos registros da sua unidade
Recepcionista        → Agendamento + Paciente da sua unidade, sem acesso financeiro
```

### Relatórios consolidados

Com `unidadeId` em todas as entidades, os relatórios nativos do EspoCRM permitem:
- Faturamento por unidade no período
- Comparativo de produção entre unidades
- Estoque por unidade
- Agenda consolidada de todos os profissionais

---

## 14. Flexibilidade vs. Modelo Anterior

| Situação | Modelo anterior | Modelo proposto |
|---|---|---|
| Orçamento como status financeiro | Orçamentos são lançamentos pendentes | `Orcamento` é entidade própria — sem obrigação financeira antes da aprovação |
| Múltiplas versões de orçamento | Não existe | `versao` + `orcamentoOrigemId` — histórico completo de revisões |
| Taxa de conversão orçamento→venda | Impossível rastrear | Query `Orcamento` por status: Aprovado vs Recusado vs Expirado |
| Dosagem da tirzepatida | Texto livre na descrição | `dosagemAplicada` estruturado na `Sessao` |
| Curva de desmame reportável | Impossível | Query em `Sessao.dosagemAplicada` por Jornada |
| Bloquear injetável sem prescrição | Não existe | Regra em `ProcedimentoInjetavel.requerPrescricao` |
| Rastrear lote do insumo aplicado | Não existe | `insumoloteId` em `ProcedimentoRealizado` |
| Alerta de vencimento de lote | Manual | Scheduled job em `InsumoLote.dataValidade` |
| Controle de estoque por unidade | Não existe | `InsumoLote.unidadeId` + `MovimentacaoEstoque` |
| Alerta de renovação de implante | Manual | Workflow por `validadeEstimadaDias` |
| Cobrança split convênio/paciente | Manual | `ConvenioRegra` + cálculo automático |
| Formulário diferente por tipo | Impossível com enum | Entidade própria = layout próprio |
| Workflow diferente por categoria | Impossível com JSON | Cada entidade tem seus próprios triggers |
| Histórico de anamneses | Não existe | Versões em `Anamnese` |
| Prontuário clínico estruturado | Notas avulsas | `Prontuario` com tipo, contexto e documentos |
| Fotos antes/depois vinculadas | Anexo solto | `Documento.subtipo = Antes/Depois` + linkParent |
| Exame vinculado ao atendimento | Não existe | `Documento` com `origemType: Atendimento` |
| Receita médica rastreável | Não existe | `Prescricao` + `PrescricaoItem` com validade |
| Relatório por unidade | Impossível | `unidadeId` em todas as entidades |
| Preço diferente por unidade | Não existe | `TabelaDePrecos.unidadeId` |
| Profissional em múltiplas unidades | Não controlado | `Profissional.unidades` (linkMultiple) |
| Permissões por unidade | Não existe | Roles baseados em `unidadeId` |

---

## 15. Roadmap de Implementação

### Fase 1 · Suporte e Catálogo
- [ ] Criar entidade `Unidade` e associar a registros existentes
- [ ] Criar entidade `Profissional` com linkMultiple para unidades
- [ ] Criar entidades `Convenio` e `ConvenioRegra`
- [ ] Criar especializações: `ProcedimentoConsulta`, `ProcedimentoInjetavel`, `ProcedimentoImplante`, `ProcedimentoEstetico`, `ProcedimentoAtividadeFisica`
- [ ] Configurar `linkParent` em `TabelaDePrecos` e `ProgramaItem`
- [ ] Migrar `Procedimento` atual para as entidades corretas (deduplicar)
- [ ] Popular `TabelaDePrecos` com combinações Profissional × Procedimento × Unidade

### Fase 2 · Estoque
- [ ] Criar `Insumo` e vincular a `ProcedimentoInjetavel` e `ProcedimentoImplante`
- [ ] Criar `InsumoLote` com alertas de vencimento e mínimo
- [ ] Criar `MovimentacaoEstoque`
- [ ] Configurar scheduled job de alertas

### Fase 3 · Clínico
- [ ] Criar `Prescricao` e `PrescricaoItem`
- [ ] Criar `Anamnese` com controle de versão
- [ ] Criar `Prontuario`
- [ ] Criar `Documento` com `linkParent` de origem e categorias
- [ ] Configurar geração automática de entrada no Prontuario após Atendimento

### Fase 4 · Jornada e Sessões
- [ ] Criar `Jornada` com `unidadeId` e `convenioId`
- [ ] Criar `Sessao` com `dosagemAplicada` e `insumoloteId`
- [ ] Migrar `CarteiraSessoes` para `Jornada`
- [ ] Workflow: gerar Sessoes ao criar Jornada de Programa

### Fase 5 · Operacional
- [ ] Atualizar `Agendamento` com `linkParent` procedimento e validações
- [ ] Criar `ProcedimentoRealizado` como filha de `Atendimento`
- [ ] Workflow: Atendimento automático ao realizar Agendamento
- [ ] Validação de `Prescricao` ao agendar injetável

### Fase 6 · Orçamento
- [ ] Criar entidade `Orcamento` com versionamento e ciclo de status
- [ ] Criar entidade `OrcamentoItem` com `linkParent` procedimento
- [ ] Workflow: ao aprovar, gerar `Jornada` + `LancamentoFinanceiro` automaticamente
- [ ] Scheduled job: expirar orçamentos vencidos
- [ ] Migrar orçamentos existentes (status `Orçamento` nas faturas atuais)

### Fase 7 · Financeiro
- [ ] Criar `LancamentoFinanceiro` com `linkParent` e split convênio
- [ ] Migrar faturas existentes
- [ ] Regra de autorização para descontos

### Fase 8 · Regras, Automações e Relatórios
- [ ] Workflows por entidade especializada (renovação implante, alerta retorno, confirmação estético)
- [ ] Relatórios por unidade: faturamento, produção, estoque
- [ ] Relatório de curva de dosagem por Jornada
- [ ] Dashboard consolidado Matriz

---

*Documento gerado em 09/03/2026 · Versão 3.0*