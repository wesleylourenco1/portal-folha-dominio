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
    pasta = BASE / "importacoes"
    pasta.mkdir(exist_ok=True)
    arquivo = pasta / p["arquivo"]
    arquivo.write_bytes(base64.b64decode(p["txt_base64"]))
    importar_txt(arquivo, salvar=True)
    return {"linhas": p["linhas"], "competencia": p["competencia"], "avisos": p.get("avisos", [])}


EXECUTORES = {"gerar_adiantamento": gerar_adiantamento, "cadastrar_admissao": cadastrar_admissao,
              "importar_lancamentos": importar_lancamentos}


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
    ap.add_argument("--testar-importacao", metavar="ARQUIVO_TXT",
                    help="importa o TXT até a prévia da Domínio e CANCELA (não grava)")
    a = ap.parse_args()
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
