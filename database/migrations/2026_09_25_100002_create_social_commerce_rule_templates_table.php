<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las plantillas de una regla: la principal más hasta 5 alternativas, por canal (privado/público).
 *
 * El tope de 6 por regla y canal es el de Zernio (`StoreAutomationRequest::MAX_VARIACIONES`), no
 * se repite aquí como restricción de base de datos porque ya lo valida el FormRequest antes de
 * llegar a guardar — igual que hace el resto del proyecto con sus topes de formulario.
 *
 * `body` guarda el texto CON las variables sin resolver (`{producto}`, `{precio}`, …): la versión
 * ya rellenada se genera al sincronizar con Zernio y no se persiste, para que cambiar el precio
 * del producto actualice la próxima sincronización sin tener que editar cada plantilla a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_rule_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('social_commerce_rules')->cascadeOnDelete();

            $table->string('channel', 10); // dm | public
            $table->text('body');

            // 0 = principal, 1-5 = alternativas. Decide el orden en el que se enseñan en el panel
            // y con el que se arma el array `dmMessageVariations`/`commentReplyVariations`.
            $table->unsignedTinyInteger('position')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'rule_id', 'channel', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_rule_templates');
    }
};
