<?php

namespace App\Services;

use App\Models\Funcao;
use App\Models\Funcionario;

/** Mantém funcionários ativos e funções em dia a partir dos dados da Domínio. */
class FuncionarioSync
{
    private const CAMPOS = ['nome', 'cpf', 'cargo_codigo', 'cargo', 'cbo', 'admissao', 'salario', 'situacao'];

    public function salvar(array $f, string $origem, bool $ativo = true): Funcionario
    {
        $func = Funcionario::firstOrNew(['codigo' => (string) $f['codigo']]);
        foreach (self::CAMPOS as $c) {
            $novo = $f[$c] ?? null;
            if ($novo !== null && $novo !== '' && $novo !== 0 && $novo !== 0.0) {  // não apaga dado bom com vazio
                $func->{$c} = $novo;
            }
        }
        $func->nome ??= '(sem nome)';
        $func->ativo = $ativo;
        $func->origem = $origem;
        $func->visto_em = now();
        $func->save();

        return $func;
    }

    /** Todos os calculados no extrato estão ativos (exceto situação "Demitido"). */
    public function atualizarDeExtrato(array $extrato): int
    {
        $origem = trim(($extrato['calculo'] ?? '').' '.($extrato['competencia'] ?? ''));
        foreach ($extrato['funcionarios'] ?? [] as $f) {
            $this->salvar($f, $origem, ! str_contains(mb_strtolower($f['situacao'] ?? ''), 'demit'));
        }
        $this->sincronizarFuncoes();

        return count($extrato['funcionarios'] ?? []);
    }

    /** Lista completa da Domínio: quem não está nela fica inativo. */
    public function atualizarListaCompleta(array $lista, string $origem): array
    {
        $ativos = [];
        foreach ($lista as $f) {
            $ativo = ! str_contains(mb_strtolower($f['situacao'] ?? ''), 'demit');
            $this->salvar($f, $origem, $ativo);
            if ($ativo) {
                $ativos[] = (string) $f['codigo'];
            }
        }
        $inativados = Funcionario::ativos()->whereNotIn('codigo', $ativos)
            ->update(['ativo' => false, 'situacao' => 'Fora da relação da Domínio']);
        $this->sincronizarFuncoes();

        return ['ativos' => count($ativos), 'inativados' => $inativados];
    }

    /** Funções a partir dos ativos: código, nome, CBO e salário mais comum do cargo. */
    public function sincronizarFuncoes(): int
    {
        $porCargo = Funcionario::ativos()->whereNotNull('cargo_codigo')->get()->groupBy('cargo_codigo');
        foreach ($porCargo as $codigo => $lista) {
            $salarios = $lista->pluck('salario')->filter()->map(fn ($s) => round($s, 2))->countBy();
            $funcao = Funcao::firstOrNew(['codigo' => (string) $codigo]);
            $funcao->nome = $lista->first()->cargo ?: $funcao->nome ?: 'Cargo '.$codigo;
            $funcao->cbo = $lista->first()->cbo ?: $funcao->cbo;
            if ($salarios->isNotEmpty()) {
                $funcao->salario = $salarios->sortDesc()->keys()->first();
            }
            $funcao->save();
        }

        return $porCargo->count();
    }
}
