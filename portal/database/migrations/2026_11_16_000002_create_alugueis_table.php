<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alugueis', function (Blueprint $t) {
            $t->id();
            $t->string('competencia', 7)->unique();        // MM/AAAA do aluguel
            $t->string('data_nota', 10);                   // DD/MM/AAAA (emissão = entrada)
            $t->string('vencimento', 10)->nullable();
            $t->json('itens');                             // [{tipo, descricao, valor}]
            $t->decimal('irrf_imobiliaria', 12, 2)->default(0);
            $t->decimal('valor_boleto', 12, 2)->nullable();      // saldo cobrado no e-mail
            $t->decimal('perc_administracao', 5, 2)->default(10);
            $t->json('calculo')->nullable();
            $t->string('status')->default('rascunho');      // rascunho | na_fila | lancado | erro
            $t->text('mensagem')->nullable();
            $t->text('observacoes')->nullable();
            $t->string('atualizado_por')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alugueis');
    }
};
