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

## Escopo Atual

- Incluído: hidratação de dados principais do faturamento, vínculo com Agendamento e hydrate imediato do Agendamento.
- Fora de escopo (intencional): persistência de movimentos do faturamento.
