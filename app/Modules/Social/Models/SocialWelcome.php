<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Una persona a la que ya se saludó.
 *
 * @property string $conversation_id
 */
final class SocialWelcome extends Model
{
    use BelongsToCompany;

    /** Se escribe una vez y no se toca: no hay nada que actualizar en «ya saludamos a este». */
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'conversation_id', 'participant_name', 'platform'];
}
