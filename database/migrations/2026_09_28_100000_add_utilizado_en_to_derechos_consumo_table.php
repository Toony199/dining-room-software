<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuándo se usó el derecho y quién lo validó (§16).
     *
     * El estado dice que se consumió, pero no cuándo: hace falta para responder "ya comiste hoy a
     * las 13:40" en el checador, y para revisar después una reclamación. Quién lo validó es lo
     * mismo que se guarda del cobrador en un pago: la operación la hizo alguien.
     */
    public function up(): void
    {
        Schema::table('derechos_consumo', function (Blueprint $table) {
            $table->timestamp('utilizado_en')->nullable()->after('estado');
            $table->foreignId('validado_por')->nullable()->after('utilizado_en')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('derechos_consumo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validado_por');
            $table->dropColumn('utilizado_en');
        });
    }
};
