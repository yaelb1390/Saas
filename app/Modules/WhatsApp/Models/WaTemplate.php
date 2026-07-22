<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de mensaje de WhatsApp.
 */
class WaTemplate extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'wa_templates';

    protected $fillable = [
        'company_id',
        'name',
        'body',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Renderiza la plantilla sustituyendo variables {{clave}} por sus valores.
     *
     * @param  array<string, string>  $variables
     */
    public function render(array $variables = []): string
    {
        $body = $this->body;

        foreach ($variables as $key => $value) {
            $body = str_replace('{{'.$key.'}}', $value, $body);
        }

        return $body;
    }
}
