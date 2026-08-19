<?php

declare(strict_types=1);

namespace App\Modules\Help\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Una pregunta hecha al asistente.
 *
 * Lleva `BelongsToCompany` —y por tanto el ámbito por empresa— porque el tope diario se cuenta por
 * empresa: sin el ámbito, una consulta descuidada contaría las preguntas de todas y le cerraría el
 * asistente a quien no ha preguntado nada.
 *
 * @property int $company_id
 * @property string $question
 * @property string $answered_by
 */
final class AssistantQuestion extends Model
{
    use BelongsToCompany;

    /** No hay `updated_at`: una pregunta no se edita, se hace una vez. */
    public const UPDATED_AT = null;

    /** El manual no cubría lo que preguntaron. Es el valor que hay que vigilar. */
    public const SIN_RESPUESTA = 'nada';

    /**
     * Un saludo, un «gracias» o un «¿qué sabes hacer?».
     *
     * Se distingue de las demás para que no ensucie la lista de huecos del manual: cien «hola» no
     * significan que falte un artículo sobre saludar.
     */
    public const SOCIAL = 'social';

    /** La redactó el proveedor de IA a partir del manual. */
    public const CON_IA = 'ia';

    /** Sin proveedor que redacte: se enseñó el artículo, que responde igual. */
    public const CON_ARTICULO = 'articulo';

    /** Existe, pero el plan de esa empresa no lo incluye. */
    public const FUERA_DE_PLAN = 'fuera_de_plan';

    /** Su empresa lo tiene, pero ese usuario no puede hacerlo. */
    public const SIN_PERMISO = 'sin_permiso';

    protected $fillable = ['company_id', 'user_id', 'question', 'answered_by', 'article_slug'];
}
