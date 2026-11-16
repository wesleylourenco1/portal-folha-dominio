# Acompanhamento do eSocial

O portal mostra, em **Funcionários**, se o eSocial recebeu os eventos de cada funcionário — em especial
se a **admissão (S-2200) foi aceita, com o número do recibo**. Quando a admissão de uma ficha do portal
é confirmada, a ficha passa para "Concluído (eSocial OK)" e o RH recebe um e-mail.

## Como funciona

- Quem consulta é o **robô da máquina da Domínio**, com o **certificado A1 (e-CNPJ) da empresa**.
- O certificado é enviado em **Configurações → Certificado digital do eSocial**: arquivo e senha ficam
  **criptografados no servidor** (APP_KEY; o .pfx em `storage/app/private/certificados`, a senha como segredo
  na tabela `configuracoes`) e nunca voltam para a tela. O robô recebe o certificado por HTTPS, com o token do
  agente, só na hora de consultar, e usa na memória. Alternativa: `ESOCIAL_CERTIFICADO` e
  `ESOCIAL_CERTIFICADO_SENHA` no `agente/.env` (usado só se não houver certificado no portal).
- Atenção: com a chave de criptografia no mesmo servidor, quem invadir o servidor consegue abrir o certificado.
  Mantenha o servidor atualizado, backups criptografados, e renove/revogue o certificado se houver suspeita.
- Serviço oficial: *Webservice de Consulta aos Identificadores dos Eventos*, método
  `ConsultarIdentificadoresEventosTrabalhador` (Manual de Orientação do Desenvolvedor v1.16, seção 7.7):
  CPF + período de recepção (até 31 dias) → eventos aceitos com número do recibo. Mensagem assinada
  (XMLDSig RSA-SHA256, C14N 1.0) e enviada com o certificado na conexão TLS.

## Regras do eSocial respeitadas pelo portal

- No máximo **10 acessos por dia** por empregador → o portal usa até 9 (padrão 8) e guarda saldo para o botão manual.
- **Nada entre os dias 1 e 7** de cada mês.
- Período de no máximo 31 dias, terminando pelo menos 1 hora antes de agora.

## Prioridade das consultas

1. Admitidos nos últimos 90 dias sem recibo da admissão (janela: 20 dias antes até 10 dias depois da admissão).
2. Rodízio dos demais ativos, cada um no máximo a cada 30 dias (eventos recebidos nos últimos 30 dias —
   normalmente a remuneração do mês). "Sem eventos" num ativo é sinal para conferir.

## Ativar

1. Envie o certificado em **Configurações → Certificado digital do eSocial** (o portal confere senha, CNPJ e validade).
2. No portal: **Configurações → eSocial → Consultar automaticamente: Sim**.
3. Teste com **Funcionários → Consultar agora** em um funcionário (gasta 1 das consultas do dia).
