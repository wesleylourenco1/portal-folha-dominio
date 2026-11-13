<?php

namespace App\Support;

/**
 * Campos do cadastro de empregado. A ordem é a do formulário de revisão.
 * origem "doc" = extraído dos documentos pela IA; "rh" = preenchido pelo RH.
 */
class CamposAdmissao
{
    public const CAMPOS = [
        ['Dados pessoais', 'nome', 'Nome completo', 'doc'],
        ['Dados pessoais', 'data_nascimento', 'Data de nascimento', 'doc'],
        ['Dados pessoais', 'sexo', 'Sexo (M/F)', 'doc'],
        ['Dados pessoais', 'estado_civil', 'Estado civil', 'doc'],
        ['Dados pessoais', 'nacionalidade', 'Nacionalidade', 'doc'],
        ['Dados pessoais', 'naturalidade_cidade', 'Naturalidade (cidade)', 'doc'],
        ['Dados pessoais', 'naturalidade_uf', 'Naturalidade (UF)', 'doc'],
        ['Dados pessoais', 'nome_mae', 'Nome da mãe', 'doc'],
        ['Dados pessoais', 'nome_pai', 'Nome do pai', 'doc'],
        ['Dados pessoais', 'raca_cor', 'Raça/cor', 'rh'],
        ['Dados pessoais', 'grau_instrucao', 'Grau de instrução', 'doc'],
        ['Documentos', 'cpf', 'CPF', 'doc'],
        ['Documentos', 'pis', 'PIS/PASEP/NIS', 'doc'],
        ['Documentos', 'rg_numero', 'RG número', 'doc'],
        ['Documentos', 'rg_orgao', 'RG órgão emissor', 'doc'],
        ['Documentos', 'rg_uf', 'RG UF', 'doc'],
        ['Documentos', 'rg_data_emissao', 'RG data de emissão', 'doc'],
        ['Documentos', 'ctps_numero', 'CTPS número', 'doc'],
        ['Documentos', 'ctps_serie', 'CTPS série', 'doc'],
        ['Documentos', 'ctps_uf', 'CTPS UF', 'doc'],
        ['Documentos', 'ctps_data_emissao', 'CTPS data de emissão', 'doc'],
        ['Documentos', 'titulo_eleitor', 'Título de eleitor', 'doc'],
        ['Documentos', 'titulo_zona', 'Título zona', 'doc'],
        ['Documentos', 'titulo_secao', 'Título seção', 'doc'],
        ['Documentos', 'cnh_numero', 'CNH número', 'doc'],
        ['Documentos', 'cnh_categoria', 'CNH categoria', 'doc'],
        ['Documentos', 'cnh_validade', 'CNH validade', 'doc'],
        ['Documentos', 'reservista', 'Certificado de reservista', 'doc'],
        ['Endereço', 'cep', 'CEP', 'doc'],
        ['Endereço', 'logradouro', 'Logradouro', 'doc'],
        ['Endereço', 'numero', 'Número', 'doc'],
        ['Endereço', 'complemento', 'Complemento', 'doc'],
        ['Endereço', 'bairro', 'Bairro', 'doc'],
        ['Endereço', 'cidade', 'Cidade', 'doc'],
        ['Endereço', 'uf', 'UF', 'doc'],
        ['Endereço', 'telefone', 'Telefone', 'rh'],
        ['Endereço', 'email', 'E-mail', 'rh'],
        ['Contrato', 'empresa_codigo', 'Código da empresa na Domínio', 'rh'],
        ['Contrato', 'data_admissao', 'Data de admissão', 'rh'],
        ['Contrato', 'cargo_codigo', 'Código do cargo na Domínio', 'rh'],
        ['Contrato', 'cargo', 'Cargo', 'rh'],
        ['Contrato', 'cbo', 'CBO', 'rh'],
        ['Contrato', 'departamento', 'Departamento', 'rh'],
        ['Contrato', 'salario', 'Salário (R$)', 'rh'],
        ['Contrato', 'tipo_salario', 'Tipo de salário (mensal/hora)', 'rh'],
        ['Contrato', 'horas_mes', 'Horas/mês', 'rh'],
        ['Contrato', 'horario', 'Horário de trabalho', 'rh'],
        ['Contrato', 'vinculo', 'Vínculo / categoria eSocial', 'rh'],
        ['Contrato', 'experiencia_dias', 'Contrato de experiência (dias)', 'rh'],
        ['Contrato', 'experiencia_prorrogacao_dias', 'Prorrogação da experiência (dias)', 'rh'],
        ['Contrato', 'vale_transporte', 'Vale-transporte (S/N)', 'rh'],
        ['Contrato', 'banco', 'Banco', 'doc'],
        ['Contrato', 'agencia', 'Agência', 'doc'],
        ['Contrato', 'conta', 'Conta', 'doc'],
        ['Contrato', 'observacoes', 'Observações', 'rh'],
    ];

    public static function chaves(?string $origem = null): array
    {
        return array_values(array_map(fn ($c) => $c[1],
            array_filter(self::CAMPOS, fn ($c) => $origem === null || $c[3] === $origem)));
    }

    public static function rotulos(): array
    {
        return array_column(self::CAMPOS, 2, 1);
    }
}
