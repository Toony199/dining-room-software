<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gafetes QR (§4). Token opaco único por emisión; un nuevo gafete desactiva el anterior (§4.3).
     * La regla "máx. 1 gafete ACTIVO por persona" se garantiza en la transacción de reposición.
     */
    public function up(): void
    {
        Schema::create('gafetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->restrictOnDelete();
            $table->string('qr_token', 40)->unique();          // ULID/UUID opaco, no el nº de empleado
            $table->enum('estado', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');
            $table->timestamp('emitido_en')->useCurrent();
            $table->timestamps();

            $table->index(['persona_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gafetes');
    }
};
