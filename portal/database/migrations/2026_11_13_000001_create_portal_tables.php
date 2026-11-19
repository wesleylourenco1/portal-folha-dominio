<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurações do portal (chaves de API, SMTP...). Segredos ficam criptografados com a APP_KEY.
        Schema::create('configuracoes', function (Blueprint $t) {
            $t->string('chave')->primary();
            $t->text('valor')->nullable();
            $t->boolean('segredo')->default(false);
            $t->timestamps();
        });

        // Funções (cargos) — sincronizadas com a Domínio a cada folha importada.
        Schema::create('funcoes', function (Blueprint $t) {
            $t->id();
            $t->string('codigo')->nullable()->unique();
            $t->string('nome');
            $t->string('cbo', 10)->nullable();
            $t->decimal('salario', 12, 2)->nullable();
            $t->string('tipo_salario', 20)->default('Mensal');
            $t->unsignedSmallInteger('horas_mes')->default(220);
            $t->string('horario')->nullable();
            $t->string('departamento')->nullable();
            $t->timestamps();
        });

        // Funcionários ativos — espelho da Domínio + dados do escritório (PIX, VT, VR).
        Schema::create('funcionarios', function (Blueprint $t) {
            $t->id();
            $t->string('codigo')->unique();
            $t->string('nome');
            $t->string('cpf', 14)->nullable();
            $t->string('cargo_codigo')->nullable();
            $t->string('cargo')->nullable();
            $t->string('cbo', 10)->nullable();
            $t->string('admissao', 10)->nullable();
            $t->decimal('salario', 12, 2)->nullable();
            $t->string('situacao')->nullable();
            $t->boolean('ativo')->default(true);
            $t->string('local')->nullable();
            $t->text('chave_pix')->nullable();           // criptografado (cast encrypted)
            $t->decimal('vale_transporte', 10, 2)->nullable();
            $t->decimal('vr_mes', 10, 2)->nullable();
            $t->decimal('desconto_vr', 10, 2)->nullable();
            $t->boolean('comissionado')->default(false);
            $t->string('origem')->nullable();
            $t->timestamp('visto_em')->nullable();
            $t->timestamps();
        });

        // Admissões (documentos + dados extraídos pela IA + revisão do RH).
        Schema::create('admissoes', function (Blueprint $t) {
            $t->id();
            $t->string('status', 30)->default('extraindo')->index();
            $t->longText('dados')->nullable();           // criptografado (dados pessoais)
            $t->longText('extracao')->nullable();        // criptografado
            $t->text('mensagem')->nullable();
            $t->text('log')->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('admissao_documentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admissao_id')->constrained('admissoes')->cascadeOnDelete();
            $t->string('nome_original');
            $t->string('caminho');
            $t->string('mime', 100)->nullable();
            $t->timestamps();
        });

        // Folhas e adiantamentos importados do Extrato Mensal da Domínio.
        Schema::create('folhas', function (Blueprint $t) {
            $t->id();
            $t->string('competencia', 7);
            $t->string('empresa_codigo', 20);
            $t->string('empresa_nome')->nullable();
            $t->string('cnpj', 18)->nullable();
            $t->string('calculo', 50);
            $t->longText('dados');                        // criptografado (salários, CPFs)
            $t->string('origem')->nullable();
            $t->string('importado_por')->nullable();
            $t->timestamps();
            $t->unique(['competencia', 'empresa_codigo', 'calculo']);
        });

        // Lançamentos mensais preenchidos pelo RH.
        Schema::create('lancamentos', function (Blueprint $t) {
            $t->id();
            $t->string('competencia', 7);
            $t->string('funcionario_codigo');
            $t->json('dados');
            $t->string('atualizado_por')->nullable();
            $t->timestamps();
            $t->unique(['competencia', 'funcionario_codigo']);
        });

        Schema::create('lancamento_competencias', function (Blueprint $t) {
            $t->string('competencia', 7)->primary();
            $t->string('status', 20)->default('aberta');
            $t->string('por')->nullable();
            $t->timestamp('em')->nullable();
        });

        // Fila de tarefas para o agente que roda na máquina da Domínio.
        Schema::create('tarefas_agente', function (Blueprint $t) {
            $t->id();
            $t->string('tipo', 50)->index();              // cadastrar_admissao | gerar_adiantamento
            $t->string('status', 20)->default('pendente')->index();
            $t->longText('payload')->nullable();          // criptografado
            $t->json('resultado')->nullable();
            $t->text('mensagem')->nullable();
            $t->string('solicitado_por')->nullable();
            $t->timestamp('iniciada_em')->nullable();
            $t->timestamp('concluida_em')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['tarefas_agente', 'lancamento_competencias', 'lancamentos', 'folhas', 'admissao_documentos',
            'admissoes', 'funcionarios', 'funcoes', 'configuracoes'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
