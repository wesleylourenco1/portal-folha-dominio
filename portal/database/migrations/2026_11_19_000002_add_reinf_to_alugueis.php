<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alugueis', function (Blueprint $t) {
            $t->string('reinf_status')->nullable();            // na_fila, aguardando, transmitido, pendente, erro
            $t->text('reinf_mensagem')->nullable();
            $t->json('reinf_eventos')->nullable();
            $t->timestamp('reinf_enviado_em')->nullable();
            $t->string('reinf_enviado_por')->nullable();
            $t->timestamp('reinf_conferido_em')->nullable();
            $t->unsignedTinyInteger('reinf_tentativas')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('alugueis', function (Blueprint $t) {
            $t->dropColumn(['reinf_status', 'reinf_mensagem', 'reinf_eventos', 'reinf_enviado_em', 'reinf_enviado_por',
                'reinf_conferido_em', 'reinf_tentativas']);
        });
    }
};
