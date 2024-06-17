<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Doping extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'doping';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'id_cliente',
        'id_subcliente',
        'id_proyecto',
        'id_antidoping_paquete',
        'fecha_doping',
        'id_tipo_identificacion',
        'ine',
        'foto',
        'razon',
        'medicamentos',
        'folio',
        'codigo_prueba',
        'laboratorio',
        'id_area',
        'comentarios',
        'fecha_resultado',
        'resultado',
        'qr_token',
        'foraneo',
        'status',
        'eliminado',
    ];

    // Relaciones
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function subcliente()
    {
        return $this->belongsTo(Subcliente::class, 'id_subcliente');
    }

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'id_proyecto');
    }

    public function antidopingPaquete()
    {
        return $this->belongsTo(AntidopingPaquete::class, 'id_antidoping_paquete');
    }

    public function tipoIdentificacion()
    {
        return $this->belongsTo(TipoIdentificacion::class, 'id_tipo_identificacion');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'id_area');
    }
}