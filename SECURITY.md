# Segurança

## Onde ficam os segredos

| Segredo | Onde | Versionado? |
|---|---|---|
| `APP_KEY`, banco, `AGENTE_TOKEN` | `portal/.env` | **Não** (`.gitignore`) |
| Chave da IA, senha SMTP | banco, criptografados com a `APP_KEY` (`Configuracao::salvar`) | Não |
| Token do agente, login da Domínio | `agente/.env` na máquina da Domínio (permissão só do usuário) | **Não** |

Os arquivos `.env.example` mostram apenas os nomes das variáveis, sem valores.

## Dados pessoais (LGPD)

- Fichas de admissão, folhas importadas e chave PIX são gravadas com o cast `encrypted` do Laravel.
- Documentos enviados ficam no disco `local` (fora da pasta pública) e só são servidos a usuários logados.
- Após o cadastro, o RH pode apagar os documentos da admissão.
- Este repositório não contém nenhum dado real: PDFs, planilhas, bancos e capturas de tela são ignorados pelo Git;
  os testes usam um extrato fictício.

## Comunicação portal ↔ agente

- O agente só faz conexões de saída (HTTPS) e se autentica com `Authorization: Bearer <AGENTE_TOKEN>`.
- O token tem 64 caracteres aleatórios (`php artisan portal:token-agente`) e é comparado em tempo constante.
- Sem token configurado (ou com menos de 32 caracteres) a API do agente recusa tudo.
- O agente recusa `PORTAL_URL` sem HTTPS (exceto localhost).

## Reportar uma vulnerabilidade

Abra uma *issue* sem detalhes sensíveis pedindo um contato privado.
