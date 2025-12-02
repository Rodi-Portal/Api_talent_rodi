<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClienteInformacionInterna extends Model
{
      protected $connection = 'portal_main';
    protected $table = 'clientes_informacion_interna';

    // Si tus campos de fechas son "creacion" y "edicion" en vez de created_at/updated_at:
    public $timestamps = false;

    protected $fillable = [
        'id_portal',
        'id_cliente',
        'nombre',
        'descripcion',
        'creacion',
        'edicion',
        'eliminado',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion'  => 'datetime',
    ];

    // Relación con documentos
    public function documentos()
    {
        return $this->hasMany(DocumentoInterno::class, 'id_informacion_interna');
    }

    // (Opcional) si tienes modelo Portal
    public function portal()
    {
        return $this->belongsTo(Portal::class, 'id_portal');
    }

    // (Opcional) si tienes modelo Cliente
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }
}
