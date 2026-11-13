<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folhas', function (Blueprint $t) {
            $t->string('contaazul_id')->nullable();          // id do evento financeiro (conta a pagar) criado
            $t->decimal('contaazul_valor', 12, 2)->nullable();
            $t->string('contaazul_vencimento', 10)->nullable();
            $t->timestamp('contaazul_enviado_em')->nullable();
            $t->string('contaazul_enviado_por')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('folhas', function (Blueprint $t) {
            $t->dropColumn(['contaazul_id', 'contaazul_valor', 'contaazul_vencimento', 'contaazul_enviado_em', 'contaazul_enviado_por']);
        });
    }
};
