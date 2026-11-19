"""Agente da Domínio: roda na máquina (ou VM) que tem a Domínio Folha e conversa com o portal.

Só faz conexões de SAÍDA (HTTPS para o portal): nenhuma porta precisa ser aberta.

  1. Envia ao portal os PDFs que a Rotina Automática da Domínio salva na pasta (extrato + líquidos).
  2. Busca tarefas no portal (gerar adiantamento, cadastrar admissão) e executa na tela da Domínio.

Uso:
  python agente.py               -> fica rodando (laço)
  python agente.py --uma-vez     -> executa um ciclo e sai (útil para testar)
  python agente.py --inspecionar -> lista os controles da janela da Domínio (para mapear roteiros)
"""
import argparse
import json
import os
import re
import sys
import time
import traceback
from pathlib import Path

import requests

BASE = Path(__file__).parent


def carregar_env(arquivo: Path = BASE / ".env") -> None:
    if arquivo.exists():
        for linha in arquivo.read_text(encoding="utf-8").splitlines():
            linha = linha.strip()
            if linha and not linha.startswith("#") and "=" in linha:
                k, v = linha.split("=", 1)
                os.environ.setdefault(k.strip(), v.strip())


carregar_env()
PORTAL = os.environ.get("PORTAL_URL", "").rstrip("/")
TOKEN = os.environ.get("AGENTE_TOKEN", "")
INTERVALO = int(os.environ.get("INTERVALO_SEGUNDOS", "30"))
PASTA_PDFS = Path(os.environ.get("PASTA_RELATORIOS", str(BASE / "relatorios")))
ROTEIRO = Path(os.environ.get("ROTEIRO", str(BASE / "roteiro.json")))
ESTADO = BASE / "estado.json"   # quais PDFs já foram enviados (fica só na máquina)


def log(msg: str) -> None:
    print(time.strftime("[%d/%m/%Y %H:%M:%S]"), msg, flush=True)


# ---------------------------------------------------------------- portal

def api(metodo: str, caminho: str, **kw) -> requests.Response:
    if not PORTAL.startswith("https://") and not PORTAL.startswith("http://localhost") and not PORTAL.startswith("http://127."):
        raise RuntimeError("PORTAL_URL precisa ser HTTPS (exceto localhost).")
    r = requests.request(metodo, f"{PORTAL}/api/agente{caminho}", timeout=120,
                         headers={"Authorization": f"Bearer {TOKEN}", "Accept": "application/json"}, **kw)
    if r.status_code == 401:
        raise RuntimeError("Portal recusou o token (AGENTE_TOKEN diferente do .env do portal).")
    r.raise_for_status()
    return r


def concluir(tarefa_id: int, sucesso: bool, mensagem: str = "", resultado: dict | None = None) -> None:
    api("POST", f"/tarefas/{tarefa_id}/concluir", json={"sucesso": sucesso, "mensagem": mensagem[:4900], "resultado": resultado or {}})


# ---------------------------------------------------------------- PDFs da Rotina Automática

def _estado() -> dict:
    try:
        return json.loads(ESTADO.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def enviar_pdfs_da_pasta() -> list[dict]:
    """Envia cada ExtratoMensal-MMAAAA.pdf novo (ou regravado) com a Relação de Líquidos correspondente."""
    PASTA_PDFS.mkdir(parents=True, exist_ok=True)
    estado, enviados = _estado(), []
    for pdf in sorted(PASTA_PDFS.glob("ExtratoMensal-*.pdf")):
        marca = str(int(pdf.stat().st_mtime))
        if estado.get(pdf.name) == marca or time.time() - pdf.stat().st_mtime < 20:   # já enviado / ainda gravando
            continue
        liquidos = next(PASTA_PDFS.glob(pdf.name.replace("ExtratoMensal", "Relat*rio*L*quidos")), None)
        arquivos = {"extrato": (pdf.name, pdf.read_bytes(), "application/pdf")}
        if liquidos:
            arquivos["liquidos"] = (liquidos.name, liquidos.read_bytes(), "application/pdf")
        try:
            r = api("POST", "/folhas", files=arquivos, data={"origem": f"{pdf.name} (Rotina Automática da Domínio)"}).json()
        except requests.HTTPError as e:
            log(f"   {pdf.name}: portal não aceitou ({e.response.status_code}): {e.response.text[:300]}")
            estado[pdf.name] = marca
            continue
        estado[pdf.name] = marca
        enviados.append(r)
        log(f"   enviado {pdf.name}: {r['calculo']} {r['competencia']}, {r['funcionarios']} funcionários, líquido {r['total_liquido']}")
    ESTADO.write_text(json.dumps(estado), encoding="utf-8")
    return enviados


# ---------------------------------------------------------------- tarefas

def gerar_adiantamento(tarefa: dict) -> dict:
    import motor
    comp = tarefa["payload"].get("competencia", time.strftime("%m/%Y"))
    if comp != time.strftime("%m/%Y"):
        raise motor.ErroRoteiro(f"A rotina automática calcula a competência atual ({time.strftime('%m/%Y')}); pedido era {comp}.")
    roteiro = motor.carregar_roteiro(ROTEIRO)
    inicio = time.time()
    _, jan = motor.janela_dominio(roteiro)
    trocar_modulo(jan, "Folha")                 # a Domínio pode ter aberto na Escrita Fiscal
    for i, passo in enumerate(roteiro["executar_rotina_adiantamento"], 1):
        try:
            motor.executar_passo(passo, {}, jan, salvar=True)
        except Exception as e:
            motor.print_tela(jan, PASTA_PDFS / f"erro_rotina_passo_{i}.png")
            raise motor.ErroRoteiro(f"Rotina automática, passo {i} ({passo.get('descricao') or passo['acao']}): {e}") from e
    mes, ano = comp.split("/")
    pdf = PASTA_PDFS / f"ExtratoMensal-{mes}{ano}.pdf"
    if not pdf.exists() or pdf.stat().st_mtime < inicio:
        raise motor.ErroRoteiro(f"A rotina terminou mas não gerou {pdf.name} na pasta {PASTA_PDFS}.")
    time.sleep(21)
    enviados = enviar_pdfs_da_pasta()
    if not enviados:
        raise motor.ErroRoteiro(f"{pdf.name} foi gerado mas não foi enviado ao portal.")
    return enviados[-1]


def cadastrar_admissao(tarefa: dict) -> dict:
    import motor
    roteiro = motor.carregar_roteiro(ROTEIRO)
    if not any(p["acao"] != "_comentario" for p in roteiro.get("cadastro_admissao", [])):
        raise motor.ErroRoteiro("Roteiro de cadastro de admissão ainda não mapeado para esta Domínio.")
    _, jan = motor.janela_dominio(roteiro)
    trocar_modulo(jan, "Folha")                 # a Domínio pode ter aberto na Escrita Fiscal
    dados = tarefa["payload"]["dados"]
    for i, passo in enumerate(roteiro["cadastro_admissao"], 1):
        try:
            motor.executar_passo(passo, dados, jan, salvar=True)
        except Exception as e:
            motor.print_tela(jan, PASTA_PDFS / f"erro_cadastro_passo_{i}.png")
            raise motor.ErroRoteiro(f"Cadastro, passo {i} ({passo.get('descricao') or passo['acao']}): {e}") from e
    return {"admissao_id": tarefa["payload"].get("admissao_id")}


def importar_txt(arquivo: Path, salvar: bool = True) -> None:
    """Importa um TXT de lançamentos na Domínio (Utilitários > Importação > de Arquivo Texto > de Lançamentos)."""
    import motor
    roteiro = motor.carregar_roteiro(ROTEIRO)
    _, jan = motor.janela_dominio(roteiro)
    trocar_modulo(jan, "Folha")                 # a Domínio pode ter aberto na Escrita Fiscal
    dados = {"arquivo_txt": str(arquivo.resolve())}
    for i, passo in enumerate(roteiro["importar_lancamentos"], 1):
        try:
            motor.executar_passo(passo, dados, jan, salvar=salvar)
        except Exception as e:
            motor.print_tela(jan, PASTA_PDFS / f"erro_importacao_passo_{i}.png")
            raise motor.ErroRoteiro(f"Importação de lançamentos, passo {i} ({passo.get('descricao') or passo['acao']}): {e}") from e


def importar_lancamentos(tarefa: dict) -> dict:
    import base64
    p = tarefa["payload"]
    if not p.get("linhas"):
        raise RuntimeError("Nenhum lançamento para importar nesta competência. " + " ".join(p.get("avisos", [])))
    pasta = BASE / "importacoes" / "folha"     # fora da pasta da Importação Padrão da Escrita Fiscal
    pasta.mkdir(parents=True, exist_ok=True)
    arquivo = pasta / p["arquivo"]
    arquivo.write_bytes(base64.b64decode(p["txt_base64"]))
    importar_txt(arquivo, salvar=True)
    return {"linhas": p["linhas"], "competencia": p["competencia"], "avisos": p.get("avisos", [])}


def consultar_esocial(tarefa: dict) -> dict:
    """Consulta no eSocial os eventos aceitos de cada CPF pedido pelo portal (certificado A1 desta máquina).

    Devolve só o resultado (códigos, recibos, quantidade); o certificado nunca sai daqui.
    Para no primeiro "parar hoje" do eSocial (limite diário, dias 1-7, consulta em andamento).
    """
    import base64
    import esocial
    # 1º o certificado enviado ao portal (criptografado lá; vem por HTTPS e fica só na memória);
    # 2º, se não houver, o arquivo indicado no .env desta máquina
    try:
        d = api("GET", "/certificado").json()
    except requests.HTTPError as e:
        if e.response is None or e.response.status_code != 404:
            raise
        d = {}
    if d.get("pfx_base64"):
        cert = esocial.carregar_certificado_bytes(base64.b64decode(d["pfx_base64"]), d.get("senha") or "")
        d.clear()
    else:
        caminho, senha = os.environ.get("ESOCIAL_CERTIFICADO", ""), os.environ.get("ESOCIAL_CERTIFICADO_SENHA", "")
        if not caminho or not Path(caminho).exists():
            raise RuntimeError("Nenhum certificado: envie o e-CNPJ A1 em Configurações do portal (ou configure ESOCIAL_CERTIFICADO no .env do agente).")
        cert = esocial.carregar_certificado(caminho, senha)
    p = tarefa["payload"]
    itens, parado = [], None
    for c in p.get("consultas", []):
        item = {"funcionario_id": c["funcionario_id"], "motivo": c.get("motivo"), "dt_ini": c["dt_ini"], "dt_fim": c["dt_fim"]}
        try:
            r = esocial.consultar_trabalhador(p["cnpj"], c["cpf"], c["dt_ini"], c["dt_fim"], certificado=cert,
                                              ambiente=p.get("ambiente", "producao"))
            item.update(codigo=r["codigo"], descricao=r["descricao"], total=r["total"], ultimo=r["ultimo"],
                        recibos=[e["nrRec"] for e in r["eventos"]])
            log(f"   eSocial {c.get('motivo')}: funcionário {c['funcionario_id']} -> {r['codigo']} ({r['total']} evento(s))")
        except esocial.EsocialPararHoje as e:
            parado = str(e)
            log(f"   eSocial parou por hoje: {e}")
            break
        except esocial.EsocialErro as e:
            item.update(codigo="erro", descricao=str(e))
            log(f"   eSocial erro: {e}")
            if "Certificado" in str(e):
                itens.append(item)
                break
        itens.append(item)
    return {"itens": itens, "parado": parado, "validade_certificado": esocial.validade(cert[1]).strftime("%d/%m/%Y")}


# ---------------------------------------------------------------- nota do aluguel (Escrita Fiscal)
# Coordenadas relativas à janela principal da Domínio maximizada (1440x900), conferidas na 10.6A.
LOGO_MODULOS = (57, 85)                      # botão "DOMÍNIO" que abre a lista de módulos
MODULOS = {"Escrita Fiscal": (99, 257), "Folha": (78, 200)}
MENU_IMPORTACAO_PADRAO = [(504, 60), (599, 654), (1081, 684), (843, 713)]  # Utilitários > Importação > Importação Padrão > Leiaute com Separador
ABA_RESUMO = {"registros": (73, 12), "advertencias": (181, 12)}             # abas da tela "Resumo dos Dados"
TELA_IMPORTACAO = "Importação Padrão - Leiaute"
PASTA_ESCRITA = BASE / "importacoes"         # pasta guardada na Importação Padrão da Escrita Fiscal (só a nota da vez)


def trocar_modulo(jan, nome: str) -> None:
    """Troca o módulo da Domínio (Folha <-> Escrita Fiscal): é o mesmo programa, muda o título da janela."""
    import motor
    from pywinauto import mouse
    if nome in jan.window_text():
        return
    jan.set_focus()
    r = jan.rectangle()
    mouse.click(coords=(r.left + LOGO_MODULOS[0], r.top + LOGO_MODULOS[1]))
    time.sleep(2)
    x, y = MODULOS[nome]
    mouse.click(coords=(r.left + x, r.top + y))
    fim = time.time() + 120
    while time.time() < fim:
        time.sleep(2)
        if nome in jan.window_text():
            time.sleep(5)
            return
    raise motor.ErroRoteiro(f"A Domínio não abriu o módulo {nome}.")


def _filho(jan, classe: str, cid: int | None = None, titulo_janela: str | None = None, timeout: float = 15,
           texto: str | None = None, todos: bool = False):
    """Controle(s) visível(is) na janela principal ou na janela interna com esse título, pelo control_id ou pelo texto
    ("&" ignorado). Os ids dos botões de opção mudam a cada abertura da Domínio: para eles use o texto."""
    import motor
    fim = time.time() + timeout
    while True:
        bases = [jan]
        if titulo_janela:
            bases = [c for c in jan.descendants()
                     if c.class_name() == "FNWND3190" and titulo_janela in (c.window_text() or "") and c.is_visible()]
        achados = []
        for b in bases:
            for c in b.descendants():
                try:
                    if (c.class_name() == classe and c.is_visible()
                            and (cid is None or c.control_id() == cid)
                            and (texto is None or c.window_text().replace("&", "") == texto)):
                        achados.append(c)
                except Exception:  # noqa: BLE001 - controle sumiu durante a leitura
                    continue
        if achados:
            return achados if todos else achados[0]
        if time.time() > fim:
            raise motor.ErroRoteiro(f"Controle {classe} {cid or texto!r} não apareceu" + (f" em '{titulo_janela}'" if titulo_janela else ""))
        time.sleep(0.5)


def _apertar(controle) -> None:
    """Aciona botão/opção pelo teclado (foco + espaço): funciona nos controles da Domínio e não depende do mouse."""
    from pywinauto import keyboard
    controle.set_focus()
    time.sleep(0.3)
    keyboard.send_keys("{SPACE}")
    time.sleep(0.8)


def _mensagem_dominio(jan, timeout: float, botoes: tuple = ("OK", "Ok")):
    """Primeiro diálogo solto da Domínio que tenha um destes botões: (texto, botão) ou None.
    Nunca passe "Sim" depois de Cancelar: a Domínio pergunta se quer gravar."""
    import motor
    fim = time.time() + timeout
    while time.time() < fim:
        for w in motor.janelas(jan.process_id()):
            try:
                if w.handle == jan.handle or w.class_name() == "FNWND3190" or "Sele" in w.window_text():
                    continue
                filhos = w.descendants()
                texto = " ".join(t for c in filhos if c.is_visible() and (t := c.window_text()))
                ok = next((c for c in filhos if c.class_name() == "Button" and c.is_visible()
                           and c.window_text().replace("&", "") in botoes), None)
                if ok and texto:
                    return f"{w.window_text()}: {texto}", ok
            except Exception:  # noqa: BLE001 - janela fechou durante a leitura
                continue
        time.sleep(0.5)
    return None


def _selecionar_arquivo(jan, pasta: str) -> None:
    """Botão "..." da Importação Padrão -> "Seleção de arquivos": aponta a pasta e marca todos os .txt dela."""
    import motor
    _apertar(_filho(jan, "Button", texto="...", titulo_janela=TELA_IMPORTACAO))
    dlg = None
    fim = time.time() + 15
    while not dlg and time.time() < fim:
        dlg = next((w for w in motor.janelas(jan.process_id()) if "Sele" in w.window_text() and "arquivos" in w.window_text()), None)
        time.sleep(0.5)
    if not dlg:
        raise motor.ErroRoteiro("A tela 'Seleção de arquivos' da Importação Padrão não abriu.")
    ctl = {c.control_id(): c for c in dlg.descendants() if c.class_name() in ("Button", "Edit")}
    botao = {c.window_text().replace("&", ""): c for c in ctl.values() if c.class_name() == "Button"}
    edit = next(c for c in ctl.values() if c.class_name() == "Edit")
    edit.set_edit_text(pasta)
    time.sleep(0.3)
    botao["Atualizar"].click()
    time.sleep(1.5)
    botao["Todos"].click()
    time.sleep(1)
    botao["OK"].click()
    time.sleep(2)


def importar_nota_escrita(arquivo: Path, salvar: bool = True) -> str:
    """Importação Padrão (Leiaute Domínio com Separador) na Escrita Fiscal e gravação.
    A pasta do arquivo deve conter só ele. Volta para o módulo Folha no fim."""
    import motor
    from pywinauto import mouse
    roteiro = motor.carregar_roteiro(ROTEIRO)
    _, jan = motor.janela_dominio(roteiro)
    pasta = str(arquivo.parent.resolve())
    try:
        trocar_modulo(jan, "Escrita Fiscal")
        r = jan.rectangle()
        pontos = [(r.left + x, r.top + y) for x, y in MENU_IMPORTACAO_PADRAO]
        jan.set_focus()
        time.sleep(0.5)
        mouse.click(coords=pontos[0])
        time.sleep(1.2)
        for p in pontos[1:-1]:
            mouse.move(coords=p)
            time.sleep(1.0)
        mouse.move(coords=pontos[-1])
        time.sleep(0.4)
        mouse.click(coords=pontos[-1])
        _filho(jan, "Button", texto="Importar", titulo_janela=TELA_IMPORTACAO, timeout=20)

        _selecionar_arquivo(jan, pasta)
        caminho = _filho(jan, "Edit", 1026, TELA_IMPORTACAO)
        if caminho.window_text().rstrip("\\").lower() != pasta.lower():
            raise motor.ErroRoteiro(f"A Importação Padrão ficou na pasta {caminho.window_text()} (esperado {pasta}).")
        rotulo = _filho(jan, "Button", texto="Identificação:", titulo_janela=TELA_IMPORTACAO).rectangle()
        combo = next(c for c in _filho(jan, "ComboBox", titulo_janela=TELA_IMPORTACAO, todos=True)
                     if abs(c.rectangle().top - rotulo.top) < 12)   # clientes e fornecedores: identificação
        if "Inscri" not in combo.window_text():
            combo.select("Inscrição federal")
        opcoes = _filho(jan, "Button", texto="Importar somente registros inexistentes", titulo_janela=TELA_IMPORTACAO, todos=True)
        for op in opcoes:                                  # cadastros e movimentos: nunca sobrescrever
            if op.get_check_state() != 1:
                _apertar(op)
            if op.get_check_state() != 1:
                raise motor.ErroRoteiro("Não consegui marcar 'Importar somente registros inexistentes'.")
        if len(opcoes) != 2:
            raise motor.ErroRoteiro("A tela da Importação Padrão mudou (opções de registros existentes).")
        _apertar(_filho(jan, "Button", texto="Importar", titulo_janela=TELA_IMPORTACAO))

        fim = time.time() + 300
        while "Resumo dos Dados" not in jan.window_text():
            aviso = _mensagem_dominio(jan, 1)
            if aviso:
                motor.clicar(aviso[1])
                raise motor.ErroRoteiro(f"A Domínio avisou: {aviso[0]}")
            if time.time() > fim:
                raise motor.ErroRoteiro("A Domínio não mostrou o Resumo dos Dados da importação.")
            time.sleep(1)
        time.sleep(3)
        ra = _filho(jan, "PBTabControl32_100", timeout=10).rectangle()
        for aba in ("registros", "advertencias"):                    # marca as notas nas duas abas
            mouse.click(coords=(ra.left + ABA_RESUMO[aba][0], ra.top + ABA_RESUMO[aba][1]))
            time.sleep(1.5)
            try:
                _apertar(_filho(jan, "Button", texto="Todos", timeout=3))
            except motor.ErroRoteiro:
                pass
        gravar = _filho(jan, "Button", texto="Gravar", timeout=5)
        cancelar = _filho(jan, "Button", texto="Cancelar", timeout=5)
        if not gravar.is_enabled():
            _apertar(cancelar)
            raise motor.ErroRoteiro("Nada para gravar: a nota já existe na Escrita Fiscal ou foi recusada (veja as críticas da importação).")
        if not salvar:
            _apertar(cancelar)
            resp = _mensagem_dominio(jan, 5, botoes=("Não", "Nao"))   # "deseja gravar?" -> NÃO
            if resp:
                motor.clicar(resp[1])
            return "modo teste: resumo aberto com a nota marcada e cancelado, nada gravado"
        _apertar(gravar)
        msg = _mensagem_dominio(jan, 120)
        if not msg:
            raise motor.ErroRoteiro("A Domínio não confirmou a gravação.")
        texto, ok = msg
        motor.clicar(ok)
        time.sleep(1)
        if "1 registro" not in texto:
            raise motor.ErroRoteiro(f"Gravação inesperada: {texto}")
        try:
            _apertar(_filho(jan, "Button", texto="Cancelar", timeout=5))   # fecha o resumo
        except motor.ErroRoteiro:
            pass
        return texto
    except Exception:
        motor.print_tela(jan, PASTA_PDFS / "erro_nota_aluguel.png")
        raise
    finally:
        try:
            if "Resumo dos Dados" not in jan.window_text() and not _mensagem_dominio(jan, 1):
                _apertar(_filho(jan, "Button", texto="Fechar", titulo_janela=TELA_IMPORTACAO, timeout=5))
                time.sleep(1)
                trocar_modulo(jan, "Folha")
        except Exception:  # noqa: BLE001
            log("   aviso: a Domínio ficou na Escrita Fiscal (feche a tela manualmente)")


def lancar_nota_aluguel(tarefa: dict) -> dict:
    """Lança a nota mensal do aluguel na Escrita Fiscal com o arquivo gerado pelo portal.
    NÃO gera nem transmite o EFD-Reinf."""
    import base64
    p = tarefa["payload"]
    if p.get("transmitir_reinf"):
        raise RuntimeError("Este robô não transmite o EFD-Reinf.")
    if not p.get("txt_base64"):
        raise RuntimeError("Tarefa sem o arquivo da nota (atualize o portal).")
    pasta = PASTA_ESCRITA
    (pasta / "arquivados").mkdir(parents=True, exist_ok=True)
    for velho in pasta.glob("*.txt"):            # a Importação Padrão lê a pasta inteira: deixa só esta nota
        velho.replace(pasta / "arquivados" / velho.name)
    arquivo = pasta / Path(p["arquivo"]).name
    arquivo.write_bytes(base64.b64decode(p["txt_base64"]))
    texto = importar_nota_escrita(arquivo, salvar=True)
    return {"documento": p.get("nota", {}).get("documento"), "competencia": p.get("competencia"), "dominio": texto}



# ---------------------------------------------------------------- eSocial da folha (Relatórios > e-Social)
# Coordenadas relativas à janela principal maximizada (1440x900), conferidas na 10.6A (módulo Folha).
MENU_ESOCIAL = [(396, 60), (402, 130), (754, 130)]           # Relatórios > e-Social > (corredor até o submenu)
ITEM_ESOCIAL = {"eventos": (780, 200), "status": (827, 250), "retorno": (837, 402)}
ANALITICO_INSS = [(1034, 404), (1083, 404)]                   # Retorno do evento de Fechamento > Analítico INSS
PDF_PREVIA = (40, 356)                                         # botão PDF da barra lateral da pré-visualização
PASTA_ESOCIAL = BASE / "relatorios_esocial"
EVENTOS_FOLHA = {"S-1200": "S-1200 - Remuneração", "S-1210": "S-1210 - Pagamentos",
                 "S-1299": "S-1299 - Fechamento dos Eventos Periódicos"}
NUNCA_MARCAR = ("Efetuar Reabertura", "Efetuar a transmissão imediata da DCTFWeb")


def _menu_esocial(jan, item: str) -> None:
    from pywinauto import mouse
    r = jan.rectangle()
    p = [(r.left + x, r.top + y) for x, y in MENU_ESOCIAL]
    jan.set_focus()
    time.sleep(0.5)
    mouse.click(coords=p[0])
    time.sleep(1.2)
    mouse.click(coords=p[1])
    time.sleep(1.5)
    mouse.move(coords=p[2])
    time.sleep(0.5)
    x, y = ITEM_ESOCIAL[item]
    mouse.move(coords=(r.left + x, r.top + y))
    time.sleep(0.5)
    if item != "retorno":
        mouse.click(coords=(r.left + x, r.top + y))
        return
    time.sleep(1.0)                                            # submenu do retorno do fechamento
    for x, y in ANALITICO_INSS:
        mouse.move(coords=(r.left + x, r.top + y))
        time.sleep(0.5)
    mouse.click(coords=(r.left + ANALITICO_INSS[-1][0], r.top + ANALITICO_INSS[-1][1]))


def _janela_interna(jan, titulo: str, timeout: float = 20):
    import motor
    fim = time.time() + timeout
    while time.time() < fim:
        for c in jan.descendants():
            try:
                if c.class_name() == "FNWND3190" and (c.window_text() or "") == titulo and c.is_visible():
                    return c
            except Exception:  # noqa: BLE001
                continue
        time.sleep(0.5)
    raise motor.ErroRoteiro(f"A tela '{titulo}' não abriu.")


def _digitar_competencia(edit, comp: str) -> None:
    import motor
    from pywinauto import keyboard
    edit.click_input()
    time.sleep(0.3)
    keyboard.send_keys("{HOME}+{END}" + comp.replace("/", ""), pause=0.05)
    time.sleep(0.4)
    if edit.window_text() != comp:
        raise motor.ErroRoteiro(f"A competência ficou '{edit.window_text()}' (esperado {comp}).")


def _periodo(jan, janela, comp: str) -> None:
    """Campos "Competência ... até ...": a Domínio não aceita fim antes do início, então a ordem depende do valor atual."""
    import motor
    ini, fim = sorted([c for c in janela.descendants() if c.class_name() == "PBEDIT190" and c.is_visible()][:2],
                      key=lambda c: c.rectangle().left)
    def mmaaaa(v):
        m, a = v.split("/")
        return int(a) * 100 + int(m)
    ordem = (ini, fim) if mmaaaa(comp) <= mmaaaa(ini.window_text()) else (fim, ini)
    for e in ordem:
        _digitar_competencia(e, comp)
        aviso = _mensagem_dominio(jan, 1)
        if aviso:
            _apertar(aviso[1])
            raise motor.ErroRoteiro(f"A Domínio recusou a competência: {aviso[0]}")


def _marcar(janela, texto: str, marcado: bool) -> None:
    import motor
    caixa = next((c for c in janela.descendants() if c.class_name() == "Button"
                  and c.window_text().replace("&", "") == texto and c.is_visible()), None)
    if caixa is None:
        if marcado:
            raise motor.ErroRoteiro(f"Opção '{texto}' não encontrada na tela.")
        return
    if (caixa.get_check_state() == 1) != marcado:
        if not caixa.is_enabled():
            raise motor.ErroRoteiro(f"A opção '{texto}' está desabilitada.")
        _apertar(caixa)
    if (caixa.get_check_state() == 1) != marcado:
        raise motor.ErroRoteiro(f"Não consegui {'marcar' if marcado else 'desmarcar'} '{texto}'.")


def _dialogos(jan) -> list:
    """Diálogos soltos da Domínio abertos agora: [(janela, texto, {rótulo: botão})]."""
    import motor
    achados = []
    for w in motor.janelas(jan.process_id()):
        try:
            if w.handle == jan.handle or w.class_name() == "FNWND3190":
                continue
            filhos = [c for c in w.descendants() if c.is_visible()]
            botoes = {c.window_text().replace("&", ""): c for c in filhos if c.class_name() == "Button" and c.window_text()}
            texto = " ".join(c.window_text() for c in filhos if c.class_name() != "Button" and c.window_text())
            if not botoes or re.match(r"Aguarde|Progresso", w.window_text() or ""):   # progresso: não é pergunta
                continue
            achados.append((w, f"{w.window_text()}: {texto}".strip(), botoes))
        except Exception:  # noqa: BLE001
            continue
    return achados


def enviar_eventos_periodicos(jan, comp: str, eventos: list, transmitir: bool) -> list:
    """Relatórios > e-Social > Eventos periódicos: marca os eventos, NUNCA a DCTFWeb nem a reabertura,
    e (se transmitir) clica em Enviar Todos. Respostas aceitas aos diálogos vêm do roteiro (esocial_respostas)."""
    import motor
    roteiro = motor.carregar_roteiro(ROTEIRO)
    _menu_esocial(jan, "eventos")
    t = _janela_interna(jan, "Eventos Periódicos")
    try:
        _digitar_competencia(next(c for c in t.descendants() if c.class_name() == "PBEDIT190" and c.is_visible()), comp)
        combo = next(c for c in t.descendants() if c.class_name() == "ComboBox" and c.is_visible())
        if combo.window_text() != "Mensal":
            combo.select("Mensal")
        for ev, rotulo in EVENTOS_FOLHA.items():
            _marcar(t, rotulo, ev in eventos)
        for rotulo in NUNCA_MARCAR:
            _marcar(t, rotulo, False)
        if not transmitir:
            return ["(modo preparo: eventos marcados, nada enviado)"]
        _apertar(_filho(jan, "Button", texto="Enviar Todos", titulo_janela="Eventos Periódicos", timeout=5))
        respostas = roteiro.get("esocial_respostas", [])
        vistos, fim = [], time.time() + 1800
        while time.time() < fim:
            dlg = _dialogos(jan)
            if not dlg:
                time.sleep(2)
                continue
            w, texto, botoes = dlg[0]
            vistos.append(texto)
            log(f"   Domínio: {texto[:200]}")
            regra = next((r for r in respostas if re.search(r["texto"], texto, re.I)), None)
            if regra and regra["botao"] in botoes:
                _apertar(botoes[regra["botao"]])
                time.sleep(2)
                if regra.get("fim"):
                    return vistos
                continue
            if set(botoes) <= {"OK", "Ok"} and re.search(r"sucesso|conclu|enviad|transmitid", texto, re.I):
                _apertar(next(iter(botoes.values())))
                return vistos
            raise motor.ErroRoteiro(f"A Domínio perguntou/avisou e o robô não sabe responder (deixei a tela como está): {texto}")
        raise motor.ErroRoteiro("O envio não terminou em 30 minutos.")
    finally:
        if not _dialogos(jan):
            try:
                _apertar(_filho(jan, "Button", texto="Fechar", titulo_janela="Eventos Periódicos", timeout=3))
            except Exception:  # noqa: BLE001
                pass


def relatorio_status_esocial(jan, comp: str) -> Path:
    """Relatórios > e-Social > Relação de status dos eventos periódicos -> PDF."""
    import motor
    from pywinauto import mouse
    _menu_esocial(jan, "status")
    t = _janela_interna(jan, "Relação de Status dos Eventos Periódicos")
    _periodo(jan, t, comp)
    _apertar(_filho(jan, "Button", texto="OK", titulo_janela="Relação de Status dos Eventos Periódicos", timeout=5))
    fim = time.time() + 120
    while "[Relação de Status" not in jan.window_text():
        if time.time() > fim:
            raise motor.ErroRoteiro("A Domínio não mostrou o relatório de status.")
        time.sleep(1)
    time.sleep(2)
    PASTA_ESOCIAL.mkdir(exist_ok=True)
    destino = PASTA_ESOCIAL / f"status_{comp.replace('/', '')}_{time.strftime('%Y%m%d%H%M%S')}.pdf"
    r = jan.rectangle()
    mouse.click(coords=(r.left + PDF_PREVIA[0], r.top + PDF_PREVIA[1]))
    motor.executar_passo({"acao": "salvar_como", "titulo": "Salvar em PDF", "campo": "destino", "timeout": 20},
                         {"destino": str(destino)}, jan, salvar=True)
    fim = time.time() + 30
    while not destino.exists() and time.time() < fim:
        time.sleep(1)
    time.sleep(1)
    for c in [c for c in jan.descendants() if c.class_name() == "FNWND3190" and "Status dos Eventos" in (c.window_text() or "")]:
        try:
            c.close()
            time.sleep(1)
        except Exception:  # noqa: BLE001
            pass
    if not destino.exists():
        raise motor.ErroRoteiro("O PDF do relatório de status não foi salvo.")
    return destino


def fechamento_validado(jan, comp: str) -> bool:
    """Retorno do evento de Fechamento > Analítico INSS: a Domínio só emite se o S-1299 da competência estiver validado."""
    import motor
    _menu_esocial(jan, "retorno")
    t = _janela_interna(jan, "Analítico INSS")
    try:
        _periodo(jan, t, comp)
        _apertar(_filho(jan, "Button", texto="OK", titulo_janela="Analítico INSS", timeout=5))
        fim = time.time() + 90
        while time.time() < fim:
            aviso = _mensagem_dominio(jan, 1)
            if aviso:
                _apertar(aviso[1])
                if re.search(r"fechamento validado", aviso[0], re.I):
                    return False
                raise motor.ErroRoteiro(f"Analítico INSS: {aviso[0]}")
            if "[Analítico INSS]" in jan.window_text():
                return True
        raise motor.ErroRoteiro("A Domínio não respondeu ao Analítico INSS.")
    finally:
        for c in [c for c in jan.descendants() if c.class_name() == "FNWND3190" and "Analítico INSS" in (c.window_text() or "")]:
            try:
                c.close()
                time.sleep(1)
            except Exception:  # noqa: BLE001
                pass


def transmitir_esocial_folha(tarefa: dict) -> dict:
    """eSocial da folha mensal, em etapas (a Domínio não deixa o S-1299 junto com S-1200/S-1210):
      etapa "remuneracao": envia S-1200 e S-1210; "fechamento": envia só o S-1299; "conferir": nada é enviado.
    Sempre devolve o relatório de status (PDF) e se o fechamento está validado. A DCTFWeb nunca é transmitida aqui."""
    import base64
    import motor
    p = tarefa["payload"]
    if p.get("transmitir_dctfweb"):
        raise RuntimeError("Este robô não transmite a DCTFWeb.")
    comp, etapa = p["competencia"], p.get("etapa", "conferir")
    if not re.fullmatch(r"\d{2}/\d{4}", comp) or etapa not in ("remuneracao", "fechamento", "conferir"):
        raise RuntimeError(f"Pedido inválido: {comp} / {etapa}")
    time.sleep(int(p.get("esperar_segundos", 0)))           # nova conferência: dá tempo ao retorno do governo
    _, jan = motor.janela_dominio(motor.carregar_roteiro(ROTEIRO))
    trocar_modulo(jan, "Folha")
    dialogos = []
    if etapa != "conferir":
        eventos = ["S-1200", "S-1210"] if etapa == "remuneracao" else ["S-1299"]
        dialogos = enviar_eventos_periodicos(jan, comp, eventos, transmitir=True)
        time.sleep(int(os.environ.get("ESOCIAL_ESPERA_SEGUNDOS", "120")))   # o retorno do governo demora
    pdf = relatorio_status_esocial(jan, comp)
    fechado = fechamento_validado(jan, comp)
    return {"etapa": etapa, "enviou": etapa != "conferir", "dialogos": dialogos, "relatorio": pdf.name,
            "fechamento_validado": fechado, "status_pdf_base64": base64.b64encode(pdf.read_bytes()).decode()}



# ---------------------------------------------------------------- EFD-Reinf (Escrita Fiscal)
# Relatórios > Informativos > Federais > EFD-Reinf > Enviar Arquivos (coordenadas relativas à janela maximizada).
MENU_REINF = [("clicar", (419, 60)), ("clicar", (433, 109)), ("mover", (609, 109)), ("clicar", (635, 111)),
              ("mover", (796, 111)), ("mover", (780, 156)), ("clicar", (757, 156)), ("mover", (1034, 156)),
              ("clicar", (1063, 158))]
TELA_REINF = "EFD-Reinf - Envio de Arquivos"
MENU_APURACAO = [(324, 60), (321, 259)]                       # Movimentos > Apuração (Escrita Fiscal)
TELA_REINF_CONSULTA = "EFD-Reinf - Consulta Envio dos Arquivos"


def _abrir_reinf(jan):
    from pywinauto import mouse
    r = jan.rectangle()
    jan.set_focus()
    time.sleep(0.5)
    for acao, (x, y) in MENU_REINF:
        (mouse.click if acao == "clicar" else mouse.move)(coords=(r.left + x, r.top + y))
        time.sleep(1.2 if acao == "clicar" else 0.4)
    return _janela_interna(jan, TELA_REINF)


def _fechar_previa(jan) -> None:
    """Fecha a pré-visualização aberta (a janela interna cujo título aparece entre [ ] na janela principal)."""
    m = re.search(r"\[(.+)\]\s*$", jan.window_text())
    if not m:
        return
    for c in [c for c in jan.descendants() if c.class_name() == "FNWND3190" and (c.window_text() or "") == m.group(1)]:
        try:
            c.close()
            time.sleep(1)
        except Exception:  # noqa: BLE001
            pass


def _salvar_previa_pdf(jan, nome: str, titulo_previa: str) -> Path | None:
    """Salva em PDF a pré-visualização que abriu. None se a Domínio disse que não há dados."""
    import motor
    from pywinauto import mouse
    fim = time.time() + 120
    while titulo_previa not in jan.window_text():
        aviso = _mensagem_dominio(jan, 1)
        if aviso and re.search(r"sem dados", aviso[0], re.I):
            _apertar(aviso[1])
            time.sleep(1)
            _fechar_previa(jan)
            return None
        if time.time() > fim:
            raise motor.ErroRoteiro("A Domínio não mostrou o relatório.")
    aviso = _mensagem_dominio(jan, 4)                         # a prévia vazia abre antes do "Sem dados"
    if aviso and re.search(r"sem dados", aviso[0], re.I):
        _apertar(aviso[1])
        time.sleep(1)
        _fechar_previa(jan)
        return None
    PASTA_ESOCIAL.mkdir(exist_ok=True)
    destino = PASTA_ESOCIAL / f"{nome}_{time.strftime('%Y%m%d%H%M%S')}.pdf"
    r = jan.rectangle()
    mouse.click(coords=(r.left + PDF_PREVIA[0], r.top + PDF_PREVIA[1]))
    motor.executar_passo({"acao": "salvar_como", "titulo": "Salvar em PDF", "campo": "destino", "timeout": 20},
                         {"destino": str(destino)}, jan, salvar=True)
    fim = time.time() + 30
    while not destino.exists() and time.time() < fim:
        time.sleep(1)
    time.sleep(1)
    _fechar_previa(jan)
    if not destino.exists():
        raise motor.ErroRoteiro("O PDF do relatório não foi salvo.")
    return destino


def apurar_escrita(jan, comp: str) -> list:
    """Movimentos > Apuração > Processa período (a Domínio não envia a Reinf sem a apuração do mês calculada)."""
    import motor
    from pywinauto import mouse
    r = jan.rectangle()
    jan.set_focus()
    time.sleep(0.5)
    mouse.click(coords=(r.left + MENU_APURACAO[0][0], r.top + MENU_APURACAO[0][1]))
    time.sleep(1.5)
    mouse.click(coords=(r.left + MENU_APURACAO[1][0], r.top + MENU_APURACAO[1][1]))
    t = _janela_interna(jan, "Apuração", timeout=40)
    avisos = []
    try:
        _periodo(jan, t, comp)
        _apertar(_filho(jan, "Button", texto="Processa período", titulo_janela="Apuração", timeout=5))
        time.sleep(3)
        fim, quieto = time.time() + 1200, 0
        while time.time() < fim and quieto < 3:
            try:
                abertos = [w.window_text() for w in motor.janelas(jan.process_id()) if w.handle != jan.handle]
            except Exception:  # noqa: BLE001 - Domínio ocupada
                abertos = ["(ocupada)"]
            dlg = [d for d in _dialogos(jan) if not re.match(r"Progresso|Aguarde", d[1])]
            if dlg:
                w, texto, botoes = dlg[0]
                avisos.append(texto)
                log(f"   Apuração: {texto[:200]}")
                if re.search(r"Fim da apura", texto, re.I) and "Não" in botoes:   # "Deseja ver os dados apurados?"
                    _apertar(botoes["Não"])
                    time.sleep(2)
                    return avisos
                if not ({"OK", "Ok"} & set(botoes)) or re.search(r"erro|n.o foi poss", texto, re.I):
                    raise motor.ErroRoteiro(f"A apuração de {comp} parou com: {texto}")
                _apertar(botoes.get("OK") or botoes.get("Ok"))
                quieto = 0
            quieto = quieto + 1 if not abertos else 0
            time.sleep(2)
        if quieto < 3:
            raise motor.ErroRoteiro(f"A apuração de {comp} não terminou em 20 minutos.")
        return avisos
    finally:
        if not _dialogos(jan):
            try:
                _apertar(_filho(jan, "Button", texto="Fechar", titulo_janela="Apuração", timeout=5))
                time.sleep(1)
            except Exception:  # noqa: BLE001
                pass


def consultar_reinf(jan, comp: str) -> Path | None:
    """Botão Consultar Envio -> Listar -> Relatório (evento, situação e recibo) em PDF."""
    _apertar(_filho(jan, "Button", texto="Consultar Envio", titulo_janela=TELA_REINF, timeout=5))
    t = _janela_interna(jan, TELA_REINF_CONSULTA)
    try:
        _periodo(jan, t, comp)
        _apertar(_filho(jan, "Button", texto="Listar", titulo_janela=TELA_REINF_CONSULTA, timeout=5))
        time.sleep(4)
        _apertar(_filho(jan, "Button", texto="Relatório", titulo_janela=TELA_REINF_CONSULTA, timeout=5))
        return _salvar_previa_pdf(jan, f"reinf_{comp.replace('/', '')}", "[")
    finally:
        if not _dialogos(jan):                               # fechar com diálogo aberto derruba a Domínio
            try:
                _fechar_previa(jan)
                _apertar(_filho(jan, "Button", texto="Fechar", titulo_janela=TELA_REINF_CONSULTA, timeout=3))
            except Exception:  # noqa: BLE001
                pass


def transmitir_reinf(tarefa: dict) -> dict:
    """EFD-Reinf do mês na Escrita Fiscal: "enviar" = Entrega completa (eventos R-4000 + fechamento);
    "conferir" = só o relatório da Consulta Envio. A DCTFWeb nunca é transmitida aqui."""
    import base64
    import motor
    p = tarefa["payload"]
    comp, etapa = p["competencia"], p.get("etapa", "conferir")
    if not re.fullmatch(r"\d{2}/\d{4}", comp) or etapa not in ("enviar", "conferir"):
        raise RuntimeError(f"Pedido inválido: {comp} / {etapa}")
    time.sleep(int(p.get("esperar_segundos", 0)))
    _, jan = motor.janela_dominio(motor.carregar_roteiro(ROTEIRO))
    dialogos = []
    try:
        trocar_modulo(jan, "Escrita Fiscal")
        if etapa == "enviar":
            dialogos += [f"Apuração: {a}" for a in apurar_escrita(jan, comp)]
        t = _abrir_reinf(jan)
        _digitar_competencia(next(c for c in t.descendants() if c.class_name() == "PBEDIT190" and c.is_visible()), comp)
        combo = next(c for c in t.descendants() if c.class_name() == "ComboBox" and c.is_visible())
        if combo.window_text() != "Entrega completa":
            combo.select("Entrega completa")
        _marcar(t, "Sem movimentação no período", False)
        if etapa == "enviar":
            _apertar(_filho(jan, "Button", texto="Enviar", titulo_janela=TELA_REINF, timeout=5))
            respostas = motor.carregar_roteiro(ROTEIRO).get("reinf_respostas", [])
            fim = time.time() + 1800
            while time.time() < fim:
                dlg = _dialogos(jan)
                if not dlg:
                    time.sleep(2)
                    continue
                w, texto, botoes = dlg[0]
                dialogos.append(texto)
                log(f"   Domínio: {texto[:200]}")
                if re.search(r"Fim do Processamento", texto, re.I) and "Não" in botoes:   # "Deseja consultar o resultado?"
                    _apertar(botoes["Não"])                        # o robô consulta pelo relatório logo depois
                    break
                if re.search(r"saldo dos impostos n.o foram calculados", texto, re.I):
                    _apertar(botoes.get("OK") or next(iter(botoes.values())))
                    raise motor.ErroRoteiro(f"A Domínio avisou que a apuração de {comp} não foi calculada; nada foi enviado. "
                                            "Faça Movimentos > Apuração na Escrita Fiscal e transmita de novo.")
                regra = next((r for r in respostas if re.search(r["texto"], texto, re.I)), None)
                if regra and regra["botao"] in botoes:
                    _apertar(botoes[regra["botao"]])
                    time.sleep(2)
                    if regra.get("fim"):
                        break
                    continue
                if set(botoes) <= {"OK", "Ok"} and re.search(r"sucesso|conclu|enviad|transmitid", texto, re.I):
                    _apertar(next(iter(botoes.values())))
                    break
                raise motor.ErroRoteiro(f"A Domínio perguntou/avisou e o robô não sabe responder (deixei a tela como está): {texto}")
            else:
                raise motor.ErroRoteiro("O envio da EFD-Reinf não terminou em 30 minutos.")
            time.sleep(int(os.environ.get("REINF_ESPERA_SEGUNDOS", "60")))
        pdf = consultar_reinf(jan, comp)
        return {"etapa": etapa, "enviou": etapa == "enviar", "dialogos": dialogos, "relatorio": pdf.name if pdf else None,
                "consulta_pdf_base64": base64.b64encode(pdf.read_bytes()).decode() if pdf else None}
    finally:
        if not _dialogos(jan):
            try:
                _apertar(_filho(jan, "Button", texto="Fechar", titulo_janela=TELA_REINF, timeout=3))
                time.sleep(1)
                trocar_modulo(jan, "Folha")
            except Exception:  # noqa: BLE001
                log("   aviso: a Domínio ficou na Escrita Fiscal")


EXECUTORES = {"gerar_adiantamento": gerar_adiantamento, "cadastrar_admissao": cadastrar_admissao,
              "importar_lancamentos": importar_lancamentos, "consultar_esocial": consultar_esocial,
              "lancar_nota_aluguel": lancar_nota_aluguel, "transmitir_esocial_folha": transmitir_esocial_folha,
              "transmitir_reinf": transmitir_reinf}


def processar_tarefa() -> bool:
    r = api("GET", "/tarefas/proxima")
    if r.status_code == 204:
        return False
    tarefa = r.json()
    log(f"-> tarefa #{tarefa['id']}: {tarefa['tipo']}")
    executor = EXECUTORES.get(tarefa["tipo"])
    try:
        if not executor:
            raise RuntimeError(f"Tipo de tarefa desconhecido: {tarefa['tipo']}")
        resultado = executor(tarefa)
        concluir(tarefa["id"], True, "OK", resultado)
        log(f"   OK tarefa #{tarefa['id']}")
    except Exception as e:  # noqa: BLE001 - devolve o erro ao portal e segue
        traceback.print_exc()
        concluir(tarefa["id"], False, str(e))
    return True


def ciclo() -> None:
    try:
        enviar_pdfs_da_pasta()
    except Exception:  # noqa: BLE001 - não pode derrubar o agente
        traceback.print_exc()
    processar_tarefa()


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--uma-vez", action="store_true")
    ap.add_argument("--inspecionar", action="store_true")
    ap.add_argument("--testar-nota-escrita", metavar="ARQUIVO_TXT",
                    help="importa a nota na Escrita Fiscal até o resumo e CANCELA (não grava)")
    ap.add_argument("--testar-importacao", metavar="ARQUIVO_TXT",
                    help="importa o TXT até a prévia da Domínio e CANCELA (não grava)")
    a = ap.parse_args()
    if a.testar_nota_escrita:
        log(importar_nota_escrita(Path(a.testar_nota_escrita), salvar=False))
        return
    if a.testar_importacao:
        importar_txt(Path(a.testar_importacao), salvar=False)
        log("Teste de importação concluído: prévia aberta e cancelada, nada gravado.")
        return
    if a.inspecionar:
        import motor
        _, jan = motor.janela_dominio()
        r0 = jan.rectangle()
        for c in jan.descendants():
            r = c.rectangle()
            print(f"{c.class_name():28} id={c.control_id():<6} texto={c.window_text()[:40]!r:44} x={r.left - r0.left} y={r.top - r0.top}")
        return
    if not (PORTAL and len(TOKEN) >= 32):
        sys.exit("Configure PORTAL_URL e AGENTE_TOKEN no arquivo .env do agente (veja .env.example).")
    log(f"Agente ativo: portal {PORTAL}, pasta {PASTA_PDFS}, a cada {INTERVALO}s.")
    while True:
        try:
            ciclo()
        except Exception:  # noqa: BLE001
            traceback.print_exc()
        if a.uma_vez:
            break
        time.sleep(INTERVALO)


if __name__ == "__main__":
    main()
