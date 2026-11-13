# Agente da Domínio (Windows)

O agente roda na máquina — ou, de preferência, numa **VM dedicada** — que tem a Domínio Folha instalada.

## Instalação

1. Instale o Python 3.12 (`winget install Python.Python.3.12`).
2. Copie a pasta `agente/` para a máquina (ex.: `C:\Robo\agente`).
3. No portal, gere o token: `php artisan portal:token-agente` (o mesmo valor vai no `.env` do portal e do agente).
4. Rode o instalador:
   ```powershell
   cd C:\Robo\agente
   powershell -ExecutionPolicy Bypass -File windows\instalar.ps1
   ```
   Ele cria o ambiente Python, pergunta URL do portal/token/caminhos, testa a conexão e configura a inicialização
   automática no login do Windows (com um vigia que religa o agente se ele parar).
5. Se a máquina recebe acesso remoto (RDP), rode como Administrador `windows\manter_sessao_ativa.ps1`:
   quando a conexão cair, a sessão volta para o console e o robô continua com a tela ativa.
   Numa VM dedicada sem acesso remoto isso não é necessário — basta login automático do Windows.

## Rotina Automática do adiantamento (na Domínio)

O adiantamento roda pela própria Domínio, em segundo plano:

1. **Processos → Rotinas Automáticas → Novo**. No código da rotina, **F7** abre o cadastro de rotinas:
   - **Geral**: ☑ Cálculo folha adiantamento → `…`: Percentual (ex.: 40), **Forma vencimento "Dias corridos direto" e
     Total de dias (ex.: 20)** — sem data de pagamento, funcionários admitidos no mês ficam de fora
     (parâmetro "Se estiver trabalhando na data do pagamento").
   - **Relatórios**: ☑ Crédito do Trabalhador - Consulta e Importação via API, ☑ Extrato, ☑ Relatório dos líquidos.
   - **PDF**: ☑ Extrato, ☑ Relatório de Líquidos, **Pasta de destino** = `PASTA_RELATORIOS` do agente.
2. No agendamento: Mensalmente, dia 15, 08:00, "Competência da execução", empresa(s) desejada(s).
3. Deixe o serviço **ServicoAgendador** da Domínio em modo **Automático** (Serviços do Windows).

O agente envia ao portal todo `ExtratoMensal-MMAAAA.pdf` novo (com o `RelatóriodeLíquidos-MMAAAA.pdf` correspondente).
O botão "Gerar adiantamento agora" do portal cria uma tarefa; o agente abre as Rotinas Automáticas e clica em Executar.

## Roteiros

`roteiro.json` descreve os passos na tela da Domínio (ações: `menu`, `clicar_botao`, `texto_controle`, `combo`,
`responder`, `aguardar_fim`, `salvar_como`, `clicar_rel`, `digitar`, `tecla`...). Para mapear uma tela nova:
`python agente.py --inspecionar` lista os controles da janela da Domínio aberta.

Coordenadas de menu dependem da resolução e da Domínio maximizada — numa VM, fixe a resolução.
