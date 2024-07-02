<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoHabitacion extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_habitacion'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'tipo_propiedad',
        'calidad_construccion',
        'estado_vivienda',
        'superficie',
        'num_piso',
        'tipo_piso',
        'tiempo_residencia',
        'tipo_zona',
        'id_tipo_nivel_zona',
        'id_tipo_vivienda',
        'recamaras',
        'banios',
        'sala',
        'comedor',
        'cocina',
        'patio',
        'cochera',
        'cuarto_servicio',
        'jardin',
        'distribucion',
        'calidad_mobiliario',
        'mobiliario',
        'tamanio_vivienda',
        'id_tipo_condiciones',
        'mantenimiento_inmueble',
        'estufa',
        'refrigerador',
        'lavadora',
        'plancha_ropa',
        'tv',
        'dvd',
        'microondas',
        'equipo_musica',
        'computadora',
        'licuadora',
        'secadora_ropa',
        'tel_fijo',
        'ventilador',
        'tostadora',
        'observacion',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    // Método para obtener una fila por el campo id_candidato
    public function tipoVivienda()
    {
        return $this->belongsTo(TipoVivienda::class, 'id_tipo_vivienda');
    }

    public function tipoNivelZona()
    {
        return $this->belongsTo(TipoNivelZona::class, 'id_tipo_nivel_zona');
    }

    public function tipoCondiciones()
    {
        return $this->belongsTo(TipoCondiciones::class, 'id_tipo_condiciones');
    }

    public function getById($id_candidato)
    {
        return $this->select('candidato_habitacion.*', 'tipo_vivienda.nombre as vivienda', 'tipo_nivel_zona.nombre as zona', 'tipo_condiciones.nombre as condiciones')
            ->leftJoin('tipo_vivienda', 'tipo_vivienda.id', '=', 'candidato_habitacion.id_tipo_vivienda')
            ->leftJoin('tipo_nivel_zona', 'tipo_nivel_zona.id', '=', 'candidato_habitacion.id_tipo_nivel_zona')
            ->leftJoin('tipo_condiciones', 'tipo_condiciones.id', '=', 'candidato_habitacion.id_tipo_condiciones')
            ->where('candidato_habitacion.id_candidato', $id_candidato)
            ->first();
    }
}
