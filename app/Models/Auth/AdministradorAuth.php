<?php
namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class AdministradorAuth extends Authenticatable
{
    use HasApiTokens;

    protected $connection = 'portal_main';
    protected $table      = 'usuarios_portal';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [
        'id',
    ];

    protected $hidden = [
        'datosGenerales',
    ];

    protected $casts = [
        'id'                 => 'integer',
        'id_portal'          => 'integer',
        'id_datos_generales' => 'integer',
        'id_rol'             => 'integer',
        'status'             => 'integer',
        'eliminado'          => 'integer',
    ];

    public function datosGenerales(): BelongsTo
    {
        return $this->belongsTo(
            DatosGeneralesAuth::class,
            'id_datos_generales',
            'id'
        );
    }

    public function getAuthPassword()
    {
        return $this->datosGenerales?->password;
    }

    public function getCorreoAttribute(): ?string
    {
        return $this->datosGenerales?->correo;
    }

    public function getNombreAttribute(): ?string
    {
        return $this->datosGenerales?->nombre;
    }
    public function portal(): BelongsTo
    {
        return $this->belongsTo(
            PortalAuth::class,
            'id_portal',
            'id'
        );
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(
            RolAuth::class,
            'id_rol',
            'id'
        );
    }
}
