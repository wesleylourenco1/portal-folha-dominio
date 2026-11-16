<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funcionarios', function (Blueprint $t) {
            $t->string('esocial_situacao')->nullable();          // recebido | sem_eventos | aguardando | erro
            $t->boolean('esocial_admissao_ok')->default(false);  // admissão (S-2200) com recibo no eSocial
            $t->string('esocial_recibo_admissao')->nullable();
            $t->string('esocial_recibo')->nullable();            // último recibo visto
            $t->unsignedInteger('esocial_eventos')->nullable();  // eventos aceitos na janela consultada
            $t->string('esocial_ultimo_evento')->nullable();     // data/hora de recepção do último evento
            $t->text('esocial_mensagem')->nullable();
            $t->timestamp('esocial_consultado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('funcionarios', function (Blueprint $t) {
            $t->dropColumn(['esocial_situacao', 'esocial_admissao_ok', 'esocial_recibo_admissao', 'esocial_recibo',
                'esocial_eventos', 'esocial_ultimo_evento', 'esocial_mensagem', 'esocial_consultado_em']);
        });
    }
};
