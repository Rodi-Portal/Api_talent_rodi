<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentoInterno extends Model
{
      protected $connection = 'portal_main';
    protected $table = 'documentos_internos';

    public $timestamps = false;

    protected $fillable = [
        'id_informacion_interna',
        'id_usuario',
        'nombre',
        'typo',
        'size',
        'storage_path',
        'fecha_vencimiento',
        'dias_antes',
        'eliminado',
        'creacion',
        'edicion',
    ];

    protected $casts = [
        'creacion'          => 'datetime',
        'edicion'           => 'datetime',
        'fecha_vencimiento' => 'date',
        'dias_antes'        => 'integer',
        'eliminado'         => 'boolean',
    ];

    // 👉 Global scope para que por default NO traiga eliminados
    protected static function booted()
    {
        static::addGlobalScope('no_eliminado', function (Builder $query) {
            $query->where('eliminado', 0);
        });
    }

    public function informacionInterna()
    {
        return $this->belongsTo(ClienteInformacionInterna::class, 'id_informacion_interna');
    }

    // (Opcional) si tienes modelo UsuarioPortal
    public function usuario()
    {
        return $this->belongsTo(UsuarioPortal::class, 'id_usuario');
    }
}
