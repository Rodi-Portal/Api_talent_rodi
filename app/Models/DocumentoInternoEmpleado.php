<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentoInternoEmpleado extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'documento_interno_empleado';

    public $timestamps = false;

    protected $fillable = [
        'id_documento_interno',
        'id_empleado',
        'id_usuario_asigno',
        'eliminado',
        'creacion',
        'edicion',
    ];

    protected $casts = [
        'id_documento_interno' => 'integer',
        'id_empleado'          => 'integer',
        'id_usuario_asigno'    => 'integer',
        'eliminado'            => 'boolean',
        'creacion'             => 'datetime',
        'edicion'              => 'datetime',
    ];

    protected static function booted()
    {
        static::addGlobalScope('no_eliminado', function (Builder $query) {
            $query->where(
                $query->qualifyColumn('eliminado'),
                0
            );
        });
    }

    public function documento()
    {
        return $this->belongsTo(
            DocumentoInterno::class,
            'id_documento_interno'
        );
    }

    public function empleado()
    {
        return $this->belongsTo(
            Empleado::class,
            'id_empleado',
            'id'
        );
    }
}