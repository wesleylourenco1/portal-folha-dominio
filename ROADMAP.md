# Roadmap

Ideias e próximos passos, em ordem aproximada de prioridade.

## Produto multi-escritório
- [ ] **VM do robô por escritório**: VM Windows dedicada, conectada ao escritório do cliente por **NetBird**, com a Domínio
      instalada apontando para o servidor do cliente. Ninguém acessa a VM — ela só roda o agente e conversa com o portal.
      Login automático e resolução fixa (a sessão nunca "cai").
- [ ] **Instalador/preparador da VM**: assistente que pergunta os dados do servidor da Domínio e do portal e prepara tudo
      (Python, agente, inicialização automática, ServicoAgendador, token, teste de conexão).
- [ ] **Preparador do adiantamento**: o agente cria na Domínio as Rotinas Automáticas (definição + agendamento) a partir das
      escolhas feitas no portal (percentual, dia de geração, vencimento), sem configuração manual.
- [ ] Vários escritórios/empresas no mesmo portal (multi-tenant), com usuários e permissões por empresa.
- [ ] Princípio: o cliente usa **só o portal** (ou a Domínio na própria máquina). Tudo o que o robô produz chega pelo portal.

## Admissão
- [ ] Mapear o roteiro de **cadastro de empregado** na Domínio (Arquivo > Empregados).
- [ ] Depois do cadastro, **baixar da Domínio os documentos do funcionário** (contrato de trabalho, contrato de experiência,
      ficha de registro, termo de VT... lista a definir) e anexar à admissão no portal para assinatura.
- [x] Conferir o retorno do eSocial (S-2200) e avisar por e-mail — consulta com o certificado A1 pelo robô ([docs/esocial.md](docs/esocial.md)).
- [ ] eSocial: conferência dos totalizadores da folha (INSS/IRRF/FGTS) e qualificação cadastral antes da admissão.

## Folha
- [ ] Gerar o **TXT de importação de lançamentos** da Domínio a partir da tela de Lançamentos do mês e importar pelo agente.
- [ ] Rotina automática da **folha mensal** (cálculo + extrato + líquidos) com disparo pelo portal.
- [ ] Faltas por tipo (injustificada / atestado com anexo / abonada) e regra de desconto do VR por falta.
- [ ] Importar o extrato de horas do portal do VR para preencher horas extras automaticamente.
- [ ] Sincronização diária da relação completa de empregados (admissões e demissões no meio do mês).
- [x] Enviar contas a pagar da folha ao **Conta Azul** — API v2 (OAuth 2.0): um total por folha (adiantamento e mensal), botão na tela da folha. Ver [docs/contaazul.md](docs/contaazul.md).
- [ ] Conta Azul: dar baixa automática quando o pagamento for confirmado; opção de uma conta por funcionário.

## Plataforma
- [ ] Telas de usuários e permissões no próprio portal.
- [ ] Screenshots e vídeo de demonstração com dados fictícios.
- [ ] CI no GitHub Actions rodando `php artisan test`.
