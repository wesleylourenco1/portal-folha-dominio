# Integração com o Conta Azul

O portal lança o **líquido total de cada folha** (adiantamento e folha mensal) como **conta a pagar** no Conta Azul,
pela API v2 oficial (`https://api-v2.contaazul.com`, documentação em https://developers.contaazul.com).

## Como configurar

1. Em https://developers-portal.contaazul.com/ crie uma aplicação e cadastre a URL de redirecionamento do portal:
   `https://SEU_PORTAL/contaazul/retorno` (local: `http://localhost:8000/contaazul/retorno`). Tem que ser exatamente igual.
2. No portal: **Configurações → Configurar Conta Azul**, informe Client ID e Client Secret e clique em **Conectar ao Conta Azul**.
   Entre com o usuário do Conta Azul **da empresa** (não o do Portal do Desenvolvedor) e autorize.
3. Escolha o **fornecedor** (ou crie "Folha de Pagamento"), a **conta financeira** e as **categorias** da folha e do adiantamento.

## Como funciona

- Na tela da folha, card **Conta Azul**: confira valor e vencimento e clique em **Enviar para o Conta Azul**.
- Vencimento sugerido: adiantamento no dia configurado (padrão 20); folha mensal no **5º dia útil do mês seguinte**
  (sábado conta; domingos e feriados nacionais fixos não). Dá para mudar a data antes de enviar.
- Chamada: `POST /v1/financeiro/contas-a-pagar` com `descricao`, `data_competencia` (1º dia da competência), `valor`,
  `id_fornecedor`, `conta_financeira`, `rateio` (1 categoria) e `condicao_pagamento.parcelas` (parcela única),
  `referencia_externa = portal-folha-{id}`.
- A mesma folha **nunca é lançada duas vezes** (trava + id do lançamento guardado na folha). Se a folha for reimportada
  com outro líquido, a tela avisa para ajustar no Conta Azul.

## Segurança

- OAuth 2.0 *authorization code* com `state` contra CSRF.
- Client Secret, access token e refresh token ficam **criptografados** (APP_KEY) na tabela `configuracoes` e nunca voltam para a tela.
- O access token vale 1 hora; o portal renova com o refresh token (que muda a cada renovação) sob uma trava, para não
  invalidar o token em renovações simultâneas. Se o acesso for revogado, o portal desconecta e pede nova conexão.
