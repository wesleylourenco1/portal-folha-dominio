"""Motor de automação da Domínio (Windows): executa passos de um roteiro JSON na tela.

Ações disponíveis: foco, tecla, digitar, copiar_campo, texto_fixo, clicar, clicar_controle,
aguardar_janela, esperar, salvar, menu, clicar_rel, clicar_botao, combo, texto_controle,
responder, aguardar_fim, salvar_como, erro_se_janela. Veja docs/roteiro.md.
"""
import json
import os
import re
import time
from pathlib import Path

from pywinauto import Application, Desktop, keyboard, mouse

EXE_DOMINIO = os.environ.get("DOMINIO_EXE", r"C:\Contabil\contabil.exe")


class ErroRoteiro(Exception):
    pass


def so_digitos(v: str | None) -> str:
    return re.sub(r"\D", "", v or "")


def carregar_roteiro(arquivo: Path) -> dict:
    return json.loads(arquivo.read_text(encoding="utf-8"))


def janela_dominio(roteiro: dict | None = None):
    """Conecta na Domínio aberta; se estiver fechada, abre e faz login (seção "login" do roteiro)."""
    try:
        app = Application(backend="win32").connect(path=EXE_DOMINIO, timeout=5)
    except Exception:  # noqa: BLE001 - não está aberta
        app = abrir_dominio(roteiro or {})
    jan = app.top_window()
    if jan.is_minimized():
        jan.restore()
    jan.set_focus()
    return app, jan


def abrir_dominio(roteiro: dict) -> Application:
    usuario = os.environ.get("DOMINIO_USUARIO", "")
    senha = os.environ.get("DOMINIO_SENHA", "")
    if not (usuario and senha and roteiro.get("login")):
        raise ErroRoteiro("A Domínio está fechada e o login automático não foi configurado (.env do agente + roteiro).")
    app = Application(backend="win32").start(EXE_DOMINIO, work_dir=str(Path(EXE_DOMINIO).parent), timeout=60)
    time.sleep(5)
    jan = app.top_window()
    for i, passo in enumerate(roteiro["login"], 1):
        try:
            executar_passo(passo, {"dominio_usuario": usuario, "dominio_senha": senha}, jan, salvar=True)
        except Exception as e:
            raise ErroRoteiro(f"Login na Domínio, passo {i}: {e}") from e
    return app


def formatar(valor: str, formato: str | None) -> str:
    valor = (valor or "").strip()
    if formato in ("digitos", "data"):           # CPF, PIS, CEP / DD/MM/AAAA -> só números
        return so_digitos(valor)
    if formato == "valor":                       # "1.500,00" -> "1500,00"
        return valor.replace("R$", "").replace(".", "").strip()
    if formato == "maiusculo":
        return valor.upper()
    if formato and formato.startswith("mapa:"):  # mapa:M=1,F=2
        mapa = dict(p.split("=", 1) for p in formato[5:].split(","))
        return mapa.get(valor.upper(), valor)
    return valor


def ler_campo_focado() -> str:
    import win32clipboard
    win32clipboard.OpenClipboard()
    win32clipboard.EmptyClipboard()
    win32clipboard.CloseClipboard()
    keyboard.send_keys("^a^c", pause=0.05)
    time.sleep(0.3)
    win32clipboard.OpenClipboard()
    try:
        if win32clipboard.IsClipboardFormatAvailable(win32clipboard.CF_UNICODETEXT):
            return (win32clipboard.GetClipboardData(win32clipboard.CF_UNICODETEXT) or "").strip()
        return ""
    finally:
        win32clipboard.CloseClipboard()


def _escapar(texto: str) -> str:
    return re.sub(r"([{}+^%~()])", r"{\1}", texto)

def janelas(pid: int | None = None) -> list:
    """Janelas visíveis (de um processo, se informado). Tolera janelas que fecham durante a leitura."""
    for _ in range(5):
        try:
            kw = {"visible_only": True}
            if pid:
                kw["process"] = pid
            return Desktop(backend="win32").windows(**kw)
        except Exception:  # noqa: BLE001 - handle inválido no meio da enumeração
            time.sleep(0.3)
    return []






# ---------------------------------------------------------------- execução

def executar_passo(passo: dict, dados: dict, jan, salvar: bool) -> None:
    acao = passo["acao"]
    pausa = passo.get("pausa", 0.3)

    if acao.startswith("_"):                     # comentário / passo desativado
        return
    if passo.get("grava") and not salvar:        # passo que grava na Domínio: pulado no modo teste
        print(f"   (modo teste: pulado '{passo.get('descricao') or acao}')")
        return
    if passo.get("so_teste") and salvar:         # passo só do modo teste (ex.: cancelar a prévia)
        return
    if acao not in ("esperar", "aguardar_fim", "responder", "salvar_como", "aguardar_janela"):
        try:                                     # outra janela (ex.: navegador) pode ter ficado por cima
            jan.set_focus()
            time.sleep(0.3)
        except Exception:  # noqa: BLE001
            pass
    if acao == "foco":
        jan.set_focus()
    elif acao == "tecla":                        # ex.: "{TAB}", "{ENTER}", "^s", "{F8}"
        keyboard.send_keys(passo["tecla"] * passo.get("vezes", 1), pause=0.05)
    elif acao == "digitar":                      # campo do formulário -> tela
        valor = formatar(dados.get(passo["campo"], ""), passo.get("formato"))
        if not valor and passo.get("obrigatorio"):
            raise ErroRoteiro(f"Campo obrigatório vazio: {passo['campo']}")
        if passo.get("limpar", True):
            keyboard.send_keys("^a{BACKSPACE}", pause=0.03)
        if valor:
            keyboard.send_keys(_escapar(valor), with_spaces=True, pause=0.02)
        if passo.get("depois"):
            keyboard.send_keys(passo["depois"], pause=0.05)
    elif acao == "copiar_campo":                 # lê o campo com foco (Ctrl+A, Ctrl+C) e guarda em dados[guardar]
        dados[passo["guardar"]] = ler_campo_focado()
    elif acao == "texto_fixo":
        keyboard.send_keys(_escapar(passo["texto"]), with_spaces=True, pause=0.02)
    elif acao == "clicar":                       # coordenadas relativas à janela principal
        r = jan.rectangle()
        mouse.click(coords=(r.left + passo["x"], r.top + passo["y"]))
    elif acao == "clicar_controle":              # controle identificado no --inspecionar
        jan.child_window(**passo["seletor"]).click_input()
    elif acao == "aguardar_janela":
        aguardar_janela(passo["titulo"], passo.get("timeout", 15))
    elif acao == "esperar":
        time.sleep(passo.get("segundos", 1))
    elif acao == "salvar":                       # só executa no modo real
        if salvar:
            keyboard.send_keys(passo["tecla"], pause=0.05)
        else:
            print("   (modo teste: NÃO salvou)")
    elif acao == "menu":                         # cliques em sequência (coordenadas de tela, Domínio maximizada)
        jan.set_focus()
        time.sleep(0.5)
        pontos = [tuple(p) for p in passo["cliques"]]
        mouse.click(coords=pontos[0])             # abre o menu
        time.sleep(1.2)
        for p in pontos[1:-1]:                    # passa pelos submenus
            mouse.move(coords=p)
            time.sleep(1.0)
        if len(pontos) > 1:
            mouse.move(coords=pontos[-1]); time.sleep(0.4)
            mouse.click(coords=pontos[-1])        # clica no item final
    elif acao == "clicar_rel":                   # clique relativo a uma janela interna da Domínio (pelo título)
        alvo = None
        fim = time.time() + passo.get("timeout", 10)
        while not alvo and time.time() < fim:
            for c in jan.descendants():
                try:
                    if c.window_text() == passo["janela"] and c.is_visible():
                        alvo = c
                        break
                except Exception:  # noqa: BLE001
                    continue
            if not alvo:
                time.sleep(0.5)
        if not alvo:
            raise ErroRoteiro(f"Janela não encontrada: {passo['janela']}")
        r = alvo.rectangle()
        mouse.click(coords=(r.left + passo["x"], r.top + passo["y"]))
    elif acao == "clicar_botao":                 # botão pelo texto (ex.: "&Consultar"); "opcional": true ignora se não existir
        try:
            clicar(_controle(jan, passo, class_name="Button", title=passo["titulo"]))
        except ErroRoteiro:
            if not passo.get("opcional"):
                raise
    elif acao == "combo":                        # seleciona item de ComboBox pelo control_id
        _controle(jan, passo, class_name="ComboBox", control_id=passo["id"]).select(passo["valor"])
    elif acao == "texto_controle":               # escreve num campo pelo control_id
        valor = formatar(dados.get(passo["campo"], passo.get("valor", "")), passo.get("formato"))
        ctl = _controle(jan, passo, class_name=passo.get("classe", "PBEDIT190"), control_id=passo["id"])
        ctl.click_input(); time.sleep(0.3)
        keyboard.send_keys("{HOME}+{END}", pause=0.05)   # seleciona tudo (funciona em campo com máscara)
        keyboard.send_keys(_escapar(valor), pause=0.05)
        time.sleep(0.3)
        lido = ctl.window_text()
        if passo.get("formato") == "digitos" and so_digitos(lido) != so_digitos(valor):
            raise ErroRoteiro(f"Campo ficou '{lido}', esperado '{valor}'")
    elif acao == "responder":                    # responde um diálogo se aparecer (titulo regex, botão)
        fim = time.time() + passo.get("timeout", 5)
        while time.time() < fim:
            for w in janelas(jan.process_id()):
                try:
                    if w.class_name() != "FNWND3190" and re.search(passo["titulo"], w.window_text() or ""):
                        if passo.get("texto") and not re.search(passo["texto"], " ".join(c.window_text() for c in w.descendants())):
                            continue
                        botao = next(c for c in w.descendants() if c.class_name() == "Button"
                                     and re.fullmatch(passo["botao"], c.window_text() or ""))
                        clicar(botao)
                        fim = 0
                        break
                except Exception:  # noqa: BLE001 - janela fechou durante a leitura
                    continue
            time.sleep(0.5)
    elif acao == "aguardar_fim":                 # espera sumir uma janela de progresso (titulo regex)
        time.sleep(2)
        fim = time.time() + passo.get("timeout", 300)
        while time.time() < fim and any(re.search(passo["titulo"], w.window_text() or "")
                                         for w in janelas(jan.process_id())):
            time.sleep(2)
    elif acao == "salvar_como":                  # janela padrão "Salvar" do Windows
        achada = aguardar_janela(passo.get("titulo", "Salvar"), passo.get("timeout", 20))
        dlg = Application(backend="win32").connect(handle=achada.handle).window(handle=achada.handle)
        destino = dados.get(passo["campo"])
        Path(destino).parent.mkdir(parents=True, exist_ok=True)
        dlg.child_window(class_name="Edit", control_id=1148).set_edit_text(destino)
        time.sleep(0.5)
        dlg.child_window(title="Sa&lvar", class_name="Button").click_input()
        responde_sobrescrever = time.time() + 3
        while time.time() < responde_sobrescrever:
            for w in janelas():
                if re.search("Confirmar|Salvar", w.window_text() or "") and "j[aá] existe" in " ".join(c.window_text() for c in w.descendants()):
                    w.child_window(title_re="&Sim|Sim").click_input()
            time.sleep(0.5)
    elif acao == "erro_se_janela":               # ex.: aviso de CPF já cadastrado
        for w in janelas():
            if re.search(passo["titulo"], w.window_text() or "", re.I):
                textos = " | ".join(t for c in w.descendants() if (t := c.window_text()))
                raise ErroRoteiro(f"A Domínio exibiu: {w.window_text()} - {textos}")
    else:
        raise ErroRoteiro(f"Ação desconhecida no roteiro: {acao}")
    time.sleep(pausa)


def clicar(controle) -> None:
    """Clica num botão mandando a mensagem direto para ele (BM_CLICK): não usa o mouse e funciona
    mesmo com outra janela na frente. Se o controle não aceitar, cai para o clique físico."""
    try:
        if controle.class_name() == "Button":
            controle.click()
            return
    except Exception:  # noqa: BLE001
        pass
    controle.click_input()


def _controle(jan, passo: dict, **criterios):
    """Controle visível da Domínio. Procura primeiro nas janelas soltas do processo (diálogos e prévias,
    como "Lançamentos importados") e depois dentro da janela principal."""
    fim = time.time() + passo.get("timeout", 10)
    while time.time() < fim:
        soltas = []
        for w in janelas(jan.process_id()):
            try:
                if w.handle != jan.handle:
                    soltas.extend(w.descendants())
            except Exception:  # noqa: BLE001
                continue
        for c in soltas + jan.descendants():
            try:
                if (("class_name" not in criterios or c.class_name() == criterios["class_name"])
                        and ("control_id" not in criterios or c.control_id() == criterios["control_id"])
                        and ("title" not in criterios
                             or c.window_text().replace("&", "") == criterios["title"].replace("&", ""))
                        and c.is_visible() and c.is_enabled()):
                    return c
            except Exception:  # noqa: BLE001 - controle sumiu durante a leitura
                continue
        time.sleep(0.5)
    raise ErroRoteiro(f"Controle não encontrado na tela: {criterios}")


def print_tela(jan, destino: Path) -> None:
    try:
        jan.capture_as_image().save(destino)
    except Exception:  # noqa: BLE001 - print é só evidência
        pass

def aguardar_janela(regex: str, timeout: float = 15):
    fim = time.time() + timeout
    while time.time() < fim:
        for w in janelas():
            if re.search(regex, w.window_text() or "", re.I):
                w.set_focus()
                return w
        time.sleep(0.4)
    raise ErroRoteiro(f"Janela não apareceu em {timeout}s: {regex}")
