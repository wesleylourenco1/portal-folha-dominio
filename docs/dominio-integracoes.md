# Integrações documentadas da Domínio Folha

Resumo do que a Central de Soluções da Domínio (Thomson Reuters) documenta sobre entrada de dados sem digitação.
Fontes no fim do arquivo.

## 1. Cadastro de empregados

| Caminho | Situação |
|---|---|
| **Arquivo > Empregados > Novo** (tela) | Caminho oficial. Ao gravar, a Domínio envia o S-2200 ao eSocial. É o que o agente automatiza na VM do robô. |
| Importação de XML do eSocial (S-2200) | **Não existe.** A Central de Soluções afirma que não é possível importar XML. |
| **Utilitários > Importação de Dados do eSocial** | Baixa do Portal do eSocial (certificado da empresa ou procuração), por lista de CPFs. Restrições: a opção "Importação de Dados" é escolhida no início do uso do eSocial na empresa e **não pode ser trocada** depois; o colaborador **não pode já existir** na Domínio; não importa cálculos, férias, rescisões, afastamentos. Uso pensado para implantação/migração — não para o dia a dia de admissões. |
| Leiaute texto de cadastro de empregados | Não documentado (há só a "lista de colaboradores" .txt usada pela importação do eSocial). |

**Conclusão:** para admissões do dia a dia, a forma suportada é o cadastro pela tela — feito pelo agente na VM do robô,
sem ninguém abrir a Domínio. Enviar o S-2200 por fora (portal → eSocial) e importar depois não é o fluxo previsto pela
Domínio e criaria dois sistemas enviando eventos do mesmo trabalhador.

## 2. Importação de lançamentos (arquivo texto) — usado pela tela "Lançamentos do mês"

Leiaute "Importação Arquivo Texto | De Lançamentos". Campos numéricos com zeros à esquerda, posições fixas.

**Registro 10 – Lançamentos**

| Posição | Tam. | Conteúdo |
|---|---|---|
| 001–002 | 2 | `10` |
| 003–012 | 10 | Código do empregado |
| 013–018 | 6 | Competência `AAAAMM` |
| 019–027 | 9 | Código da rubrica |
| 028–029 | 2 | Tipo do processo |
| 030–038 | 9 | Valor |
| 039–048 | 10 | Código da empresa |

**Registro 11 – Dias de faltas** (logo após o registro 10 da rubrica de faltas)

| Posição | Tam. | Conteúdo |
|---|---|---|
| 001–002 | 2 | `11` |
| 003–010 | 8 | Data `AAAAMMDD` |
| 011 | 1 | Tipo: `1` normal, `2` DSR |

**Registro 12 – Faltas parciais**: `12` + quantidade de horas (5 posições).

Outros registros: 20/25 plano de saúde (operadora / beneficiário), 30 pensão alimentícia, 40 lançamento por serviço.

> A confirmar na instalação: valores possíveis de "Tipo do processo", formato decimal do valor (centavos implícitos?)
> e o menu de importação. Testar com um arquivo pequeno numa competência de teste antes de usar em produção.

Mapeamento previsto a partir da tela de Lançamentos: horas extras → rubrica 202 (referência em horas),
comissão → 37, desconto VR → 201, dias de falta → rubrica 8792 + um registro 11 por dia informado.

## Fontes (Central de Soluções Domínio)

- Como fazer importação de dados do eSocial? — solução 7686
- Perguntas e Respostas – Importação de Dados eSocial — solução 7873
- Leiaute: Importação Arquivo Texto | De Lançamentos — solução 8813
- Como cadastrar Empregado? S-2200 — solução 5
