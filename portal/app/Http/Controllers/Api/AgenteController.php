<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admissao;
use App\Models\Configuracao;
use App\Models\Folha;
use App\Models\TarefaAgente;
use App\Services\DominioTxtLancamentos;
use App\Services\FolhaService;
use App\Services\FuncionarioSync;
use App\Services\Notificador;
use App\Support\Br;
use Illuminate\Http\Request;

/**
 * API usada pelo agente que roda na máquina (ou VM) da Domínio.
 * O agente só faz chamadas de saída para o portal: busca tarefas, devolve resultados e envia PDFs.
 */
class AgenteController extends Controller
{
    /** Próxima tarefa pendente (ou 204 se não houver). Também cria a do adiantamento automático, se chegou o dia. */
    public function proxima()
    {
        $this->agendarAdiantamentoAutomatico();
        $tarefa = TarefaAgente::proxima();
        if (! $tarefa) {
            return response()->noContent();
        }
        $payload = $tarefa->payload ?? [];
        if ($tarefa->tipo === 'cadastrar_admissao' && ($adm = Admissao::find($payload['admissao_id'] ?? 0))) {
            $adm->update(['status' => 'cadastrando']);
            $adm->registrar('Robô iniciou o cadastro na Domínio');
            $payload['dados'] = $adm->dados;
        }
        if ($tarefa->tipo === 'importar_lancamentos') {
            // o arquivo é gerado na hora da entrega, com os lançamentos atuais da competência
            $r = app(DominioTxtLancamentos::class)->gerar($payload['competencia']);
            $payload += [
                'arquivo' => 'lancamentos_dominio_'.str_replace('/', '', $payload['competencia']).'.txt',
                'txt_base64' => base64_encode($r['conteudo']),
                'linhas' => $r['linhas'],
                'avisos' => $r['avisos'],
            ];
        }

        return response()->json(['id' => $tarefa->id, 'tipo' => $tarefa->tipo, 'payload' => $payload]);
    }

    public function concluir(Request $request, TarefaAgente $tarefa)
    {
        $dados = $request->validate([
            'sucesso' => 'required|boolean',
            'mensagem' => 'nullable|string|max:5000',
            'resultado' => 'nullable|array',
        ]);
        $tarefa->update([
            'status' => $dados['sucesso'] ? 'concluida' : 'erro',
            'mensagem' => $dados['mensagem'] ?? null,
            'resultado' => $dados['resultado'] ?? null,
            'concluida_em' => now(),
        ]);

        if ($tarefa->tipo === 'cadastrar_admissao' && ($adm = Admissao::find($tarefa->payload['admissao_id'] ?? 0))) {
            $adm->update($dados['sucesso']
                ? ['status' => 'aguardando_esocial', 'mensagem' => 'Cadastrado na Domínio. Aguardando o retorno do eSocial.']
                : ['status' => 'erro_cadastro', 'mensagem' => 'Erro no cadastro: '.($dados['mensagem'] ?? '')]);
            $adm->registrar($dados['sucesso'] ? 'Cadastro concluído na Domínio' : 'ERRO: '.($dados['mensagem'] ?? ''));
        }
        if ($tarefa->tipo === 'gerar_adiantamento' && ! $dados['sucesso']) {
            Configuracao::salvar(['adiantamento_status' => 'ERRO '.now()->format('d/m/Y H:i').': '.($dados['mensagem'] ?? '')]);
        }

        return response()->json(['ok' => true]);
    }

    /** Extrato Mensal (+ Relação de Líquidos) gerado pela Domínio. */
    public function enviarFolha(Request $request, FolhaService $folhas, Notificador $notificador)
    {
        $request->validate([
            'extrato' => 'required|file|mimes:pdf|max:20480',
            'liquidos' => 'nullable|file|mimes:pdf|max:20480',
            'origem' => 'nullable|string|max:200',
        ]);
        $folha = $folhas->importar(
            $request->file('extrato')->getRealPath(), 'agente',
            $request->input('origem', 'Rotina automática da Domínio'),
            $request->file('liquidos')?->getRealPath(),
        );
        $d = $folha->dados;
        $aviso = $notificador->tentar(
            "{$folha->calculo} {$folha->competencia} pronto - ".count($d['funcionarios']).' funcionários',
            "A Domínio gerou o {$folha->calculo} de {$folha->competencia}.\n\nFuncionários: ".count($d['funcionarios'])
            ."\nLíquido total: R$ ".Br::moeda($d['total_liquido'])."\nConsignados: R$ ".Br::moeda($d['total_consignado'])
            .($folha->vencimento() ? "\nVencimento: ".$folha->vencimento() : '')."\n\nVeja no portal: Folha > #{$folha->id}."
        );

        return response()->json(['id' => $folha->id, 'competencia' => $folha->competencia, 'calculo' => $folha->calculo,
            'funcionarios' => count($d['funcionarios']), 'total_liquido' => $d['total_liquido'], 'aviso_email' => $aviso]);
    }

    /** Relação completa de empregados exportada da Domínio. */
    public function funcionarios(Request $request, FuncionarioSync $sync)
    {
        $dados = $request->validate([
            'funcionarios' => 'required|array',
            'funcionarios.*.codigo' => 'required',
            'funcionarios.*.nome' => 'required|string',
        ]);

        return response()->json($sync->atualizarListaCompleta($dados['funcionarios'], 'Relação de empregados da Domínio'));
    }

    private function agendarAdiantamentoAutomatico(): void
    {
        if (Configuracao::ler('adiantamento_automatico', 'nao') !== 'sim'
            || now()->day < (int) Configuracao::ler('adiantamento_dia_gerar', '15')
            || TarefaAgente::pendente('gerar_adiantamento')) {
            return;
        }
        $comp = now()->format('m/Y');
        $jaTem = Folha::where('competencia', $comp)->get()->contains(fn ($f) => $f->ehAdiantamento());
        $jaTentou = TarefaAgente::where('tipo', 'gerar_adiantamento')->where('created_at', '>=', now()->startOfMonth())->exists();
        if (! $jaTem && ! $jaTentou) {
            TarefaAgente::enfileirar('gerar_adiantamento', ['competencia' => $comp], 'agendamento automático');
        }
    }
}
