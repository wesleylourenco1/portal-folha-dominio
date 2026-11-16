"""Consulta ao eSocial com o certificado A1 (e-CNPJ) da empresa.

Webservice de Consulta aos Identificadores dos Eventos — Manual de Orientação do
Desenvolvedor (MOD) v1.16, seção 7.7. Método ConsultarIdentificadoresEventosTrabalhador:
recebe CPF + período (data de RECEPÇÃO do evento, até 31 dias) e devolve os eventos
aceitos (id + número do recibo).

Regras do eSocial (MOD 7.7.7): no máximo 10 acessos por dia por empregador (consulta +
download somados), nenhuma consulta entre os dias 1 e 7 do mês, uma por vez, e a data
fim tem que ser pelo menos 1 hora antes de agora.

O certificado fica só nesta máquina; o portal recebe apenas o resultado.
"""
from __future__ import annotations

import os
import re
import tempfile
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path

import requests
from cryptography.hazmat.primitives.serialization import Encoding, NoEncryption, PrivateFormat, pkcs12
from lxml import etree
from signxml import XMLSigner
from signxml.algorithms import CanonicalizationMethod, DigestAlgorithm, SignatureMethod

URLS = {
    "producao": "https://webservices.download.esocial.gov.br/servicos/empregador/dwlcirurgico/WsConsultarIdentificadoresEventos.svc",
    "restrita": "https://webservices.producaorestrita.esocial.gov.br/servicos/empregador/dwlcirurgico/WsConsultarIdentificadoresEventos.svc",
}
NS_SERVICO = "http://www.esocial.gov.br/servicos/empregador/consulta/identificadores-eventos/v1_0_0"
NS_TRABALHADOR = "http://www.esocial.gov.br/schema/consulta/identificadores-eventos/trabalhador/v1_0_0"
NS_DSIG = "http://www.w3.org/2000/09/xmldsig#"
METODO = "ConsultarIdentificadoresEventosTrabalhador"
SOAP_ACTION = f"{NS_SERVICO}/ServicoConsultarIdentificadoresEventos/{METODO}"

# códigos de resposta (MOD 7.7.17)
SUCESSO = {"201", "203"}
NADA_ENCONTRADO = "406"
PARAR_HOJE = {"403", "404", "405"}       # dias 1-7, consulta em andamento, limite diário
ERRO_CERTIFICADO = {"407", "411"}


class EsocialPararHoje(Exception):
    """O eSocial não aceita mais consultas hoje (limite, dias 1-7 ou consulta em andamento)."""


class EsocialErro(Exception):
    pass


def so_digitos(v: str) -> str:
    return re.sub(r"\D", "", v or "")


def carregar_certificado(caminho: str, senha: str):
    dados = Path(caminho).read_bytes()
    chave, cert, cadeia = pkcs12.load_key_and_certificates(dados, senha.encode() if senha else None)
    if not chave or not cert:
        raise EsocialErro("Certificado sem chave privada (precisa ser A1 .pfx/.p12).")
    return chave, cert, list(cadeia or [])


def validade(cert) -> datetime:
    return getattr(cert, "not_valid_after_utc", None) or cert.not_valid_after


@contextmanager
def arquivos_pem(chave, cert, cadeia):
    """PEMs temporários para a conexão TLS com certificado de cliente; apagados ao sair."""
    pasta = tempfile.mkdtemp(prefix="esocial_")
    pc, pk = Path(pasta, "cert.pem"), Path(pasta, "key.pem")
    try:
        pc.write_bytes(b"".join(c.public_bytes(Encoding.PEM) for c in [cert, *cadeia]))
        pk.write_bytes(chave.private_bytes(Encoding.PEM, PrivateFormat.TraditionalOpenSSL, NoEncryption()))
        yield str(pc), str(pk)
    finally:
        for p in (pc, pk):
            try:
                p.unlink()
            except OSError:
                pass
        try:
            os.rmdir(pasta)
        except OSError:
            pass


def montar_consulta(cnpj: str, cpf: str, dt_ini: str, dt_fim: str) -> etree._Element:
    """XML da consulta por trabalhador (ainda sem assinatura). Datas no formato AAAA-MM-DDTHH:MM:SS."""
    raiz = so_digitos(cnpj)[:8]           # empregador CNPJ: raiz (8 dígitos)
    e = etree.Element("{%s}eSocial" % NS_TRABALHADOR, nsmap={None: NS_TRABALHADOR})
    c = etree.SubElement(e, "{%s}consultaIdentificadoresEvts" % NS_TRABALHADOR)
    ide = etree.SubElement(c, "{%s}ideEmpregador" % NS_TRABALHADOR)
    etree.SubElement(ide, "{%s}tpInsc" % NS_TRABALHADOR).text = "1"
    etree.SubElement(ide, "{%s}nrInsc" % NS_TRABALHADOR).text = raiz
    f = etree.SubElement(c, "{%s}consultaEvtsTrabalhador" % NS_TRABALHADOR)
    etree.SubElement(f, "{%s}cpfTrab" % NS_TRABALHADOR).text = so_digitos(cpf)
    etree.SubElement(f, "{%s}dtIni" % NS_TRABALHADOR).text = dt_ini
    etree.SubElement(f, "{%s}dtFim" % NS_TRABALHADOR).text = dt_fim
    return e


def assinar(xml: etree._Element, chave, cert) -> etree._Element:
    """Assinatura enveloped do elemento raiz (URI=""), RSA-SHA256, C14N 1.0 — padrão do eSocial."""
    signer = XMLSigner(
        signature_algorithm=SignatureMethod.RSA_SHA256,
        digest_algorithm=DigestAlgorithm.SHA256,
        c14n_algorithm=CanonicalizationMethod.CANONICAL_XML_1_0,
    )
    signer.namespaces = {None: NS_DSIG}   # <Signature xmlns="...xmldsig#"> sem prefixo, como o eSocial publica
    assinado = signer.sign(xml, key=chave, cert=[cert])
    return etree.fromstring(etree.tostring(assinado))   # relê: igual ao que vai pela rede


def envelope(assinado: etree._Element) -> bytes:
    corpo = etree.tostring(assinado, encoding="unicode")
    return (
        '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
        f'xmlns:v1="{NS_SERVICO}"><soapenv:Header/><soapenv:Body>'
        f"<v1:{METODO}><v1:consultaEventosTrabalhador>{corpo}</v1:consultaEventosTrabalhador></v1:{METODO}>"
        "</soapenv:Body></soapenv:Envelope>"
    ).encode("utf-8")


def _texto(raiz, nome: str) -> str:
    achou = raiz.xpath(f"//*[local-name()='{nome}']")
    return (achou[0].text or "").strip() if achou else ""


def interpretar_retorno(xml: bytes) -> dict:
    raiz = etree.fromstring(xml)
    eventos = [
        {"id": ev.xpath("string(./*[local-name()='id'])").strip(),
         "nrRec": ev.xpath("string(./*[local-name()='nrRec'])").strip()}
        for ev in raiz.xpath("//*[local-name()='identificadorEvt']")
    ]
    return {
        "codigo": _texto(raiz, "cdResposta"),
        "descricao": _texto(raiz, "descResposta"),
        "total": int(_texto(raiz, "qtdeTotEvtsConsulta") or len(eventos)),
        "ultimo": _texto(raiz, "dhUltimoEvtRetornado"),
        "eventos": eventos,
    }


def consultar_trabalhador(cnpj: str, cpf: str, dt_ini: str, dt_fim: str, *, certificado, ambiente: str = "producao") -> dict:
    chave, cert, cadeia = certificado
    corpo = envelope(assinar(montar_consulta(cnpj, cpf, dt_ini, dt_fim), chave, cert))
    with arquivos_pem(chave, cert, cadeia) as (pem_cert, pem_chave):
        r = requests.post(
            URLS[ambiente], data=corpo, timeout=90, cert=(pem_cert, pem_chave),
            headers={"Content-Type": "text/xml;charset=UTF-8", "SOAPAction": f'"{SOAP_ACTION}"'},
        )
    if r.status_code != 200 and b"cdResposta" not in r.content:
        raise EsocialErro(f"eSocial respondeu HTTP {r.status_code}: {r.text[:300]}")
    res = interpretar_retorno(r.content)
    if res["codigo"] in PARAR_HOJE:
        raise EsocialPararHoje(f"{res['codigo']} {res['descricao']}")
    if res["codigo"] in ERRO_CERTIFICADO:
        raise EsocialErro(f"Certificado não autorizado: {res['codigo']} {res['descricao']}")
    if res["codigo"] not in SUCESSO and res["codigo"] != NADA_ENCONTRADO:
        raise EsocialErro(f"{res['codigo']} {res['descricao']}")
    return res
