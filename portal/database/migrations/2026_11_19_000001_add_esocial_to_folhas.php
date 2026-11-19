<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folhas', function (Blueprint $t) {
            $t->string('esocial_status')->nullable();          // na_fila, transmitido, pendente, erro
            $t->text('esocial_mensagem')->nullable();
            $t->text('esocial_empregados')->nullable();        // criptografado (CPF)
            $t->timestamp('esocial_transmitido_em')->nullable();
            $t->string('esocial_transmitido_por')->nullable();
            $t->timestamp('esocial_conferido_em')->nullable();
            $t->timestamp('esocial_fechado_em')->nullable();      // S-1299 validado (Analítico INSS emitido)
            $t->unsignedTinyInteger('esocial_tentativas')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('folhas', function (Blueprint $t) {
            $t->dropColumn(['esocial_status', 'esocial_mensagem', 'esocial_empregados', 'esocial_transmitido_em',
                'esocial_transmitido_por', 'esocial_conferido_em', 'esocial_fechado_em', 'esocial_tentativas']);
        });
    }
};
