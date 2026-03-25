# Contextos Importantas

No endpoint de exportação de relatório de pacientes (https://app.clinicanasnuvens.com.br/pacientes/relatorio-completo) quando o filtro é referente a `tipoFiltroData=ALTERACAO` não é considerado `ALTERACAO` mudanças no cadastro (email, telefone, etc). Pelos nossos testes o usuário aparece nessa lista como alterado somente quando possui alguma alteração relevante como um novo agendamento.
