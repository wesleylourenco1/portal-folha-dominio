"""Testes offline do esocial.py (certificado de teste gerado na hora; nada é enviado ao eSocial).

Rodar:  .venv\\Scripts\\python.exe -m unittest test_esocial -v
"""
import unittest
from datetime import datetime, timedelta, timezone

from cryptography import x509
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
from lxml import etree
from signxml import XMLVerifier

import esocial


def certificado_teste():
    chave = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    nome = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "EMPRESA TESTE:11222333000181")])
    agora = datetime.now(timezone.utc)
    cert = (x509.CertificateBuilder().subject_name(nome).issuer_name(nome).public_key(chave.public_key())
            .serial_number(x509.random_serial_number()).not_valid_before(agora - timedelta(days=1))
            .not_valid_after(agora + timedelta(days=30)).sign(chave, hashes.SHA256()))
    return chave, cert, []


RETORNO = b"""<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>
<ConsultarIdentificadoresEventosTrabalhadorResponse xmlns="http://www.esocial.gov.br/servicos/empregador/consulta/identificadores-eventos/v1_0_0">
<ConsultarIdentificadoresEventosTrabalhadorResult>
<eSocial xmlns="http://www.esocial.gov.br/schema/consulta/identificadores-eventos/retorno/v1_0_0">
<retornoConsultaIdentificadoresEvts>
<status><cdResposta>201</cdResposta><descResposta>Consulta realizada com sucesso.</descResposta></status>
<retornoIdentificadoresEvts><qtdeTotEvtsConsulta>2</qtdeTotEvtsConsulta><dhUltimoEvtRetornado>2026-11-05T10:20:30</dhUltimoEvtRetornado>
<identificadoresEvts>
<identificadorEvt><id>ID1112223330000002026110410000000001</id><nrRec>1.2.0000000000000000001</nrRec></identificadorEvt>
<identificadorEvt><id>ID1112223330000002026110510000000002</id><nrRec>1.2.0000000000000000002</nrRec></identificadorEvt>
</identificadoresEvts></retornoIdentificadoresEvts>
</retornoConsultaIdentificadoresEvts></eSocial>
</ConsultarIdentificadoresEventosTrabalhadorResult></ConsultarIdentificadoresEventosTrabalhadorResponse></s:Body></s:Envelope>"""


class TesteEsocial(unittest.TestCase):
    def test_consulta_assinada_no_padrao_do_esocial(self):
        chave, cert, _ = certificado_teste()
        xml = esocial.montar_consulta("11.222.333/0001-81", "529.982.247-25", "2026-10-20T00:00:00", "2026-11-15T23:00:00")
        assinado = esocial.assinar(xml, chave, cert)
        # assinatura válida e cobrindo o documento inteiro (URI="")
        XMLVerifier().verify(assinado, x509_cert=cert)
        ns = {"t": esocial.NS_TRABALHADOR, "d": esocial.NS_DSIG}
        self.assertEqual(assinado.findtext(".//t:nrInsc", namespaces=ns), "11222333")   # raiz do CNPJ
        self.assertEqual(assinado.findtext(".//t:cpfTrab", namespaces=ns), "52998224725")
        ref = assinado.find(".//d:Reference", ns)
        self.assertEqual(ref.get("URI"), "")
        self.assertIn("rsa-sha256", assinado.find(".//d:SignatureMethod", ns).get("Algorithm"))
        self.assertIn("REC-xml-c14n-20010315", assinado.find(".//d:CanonicalizationMethod", ns).get("Algorithm"))
        # envelope SOAP 1.1 com o método certo
        env = esocial.envelope(assinado)
        corpo = etree.fromstring(env).find(".//{%s}consultaEventosTrabalhador" % esocial.NS_SERVICO)
        self.assertIsNotNone(corpo)

    def test_retorno_traz_recibos(self):
        r = esocial.interpretar_retorno(RETORNO)
        self.assertEqual(r["codigo"], "201")
        self.assertEqual(r["total"], 2)
        self.assertEqual([e["nrRec"] for e in r["eventos"]], ["1.2.0000000000000000001", "1.2.0000000000000000002"])
        self.assertEqual(r["ultimo"], "2026-11-05T10:20:30")


if __name__ == "__main__":
    unittest.main()
