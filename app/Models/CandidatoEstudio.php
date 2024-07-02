<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoEstudio extends Model
{
    protected $connection = 'rodi_main';
    protected $table = 'candidato_estudios';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'primaria_periodo',
        'primaria_escuela',
        'primaria_ciudad',
        'primaria_certificado',
        'primaria_validada',
        'primaria_promedio',
        'secundaria_periodo',
        'secundaria_escuela',
        'secundaria_ciudad',
        'secundaria_certificado',
        'secundaria_validada',
        'secundaria_promedio',
        'preparatoria_periodo',
        'preparatoria_escuela',
        'preparatoria_ciudad',
        'preparatoria_certificado',
        'preparatoria_validada',
        'preparatoria_promedio',
        'licenciatura_periodo',
        'licenciatura_escuela',
        'licenciatura_ciudad',
        'licenciatura_certificado',
        'licenciatura_validada',
        'licenciatura_promedio',
        'actual_periodo',
        'actual_escuela',
        'actual_ciudad',
        'actual_certificado',
        'actual_validada',
        'actual_promedio',
        'comercial_periodo',
        'comercial_escuela',
        'comercial_ciudad',
        'comercial_certificado',
        'comercial_validada',
        'comercial_promedio',
        'posgrado_periodo',
        'posgrado_escuela',
        'posgrado_ciudad',
        'posgrado_certificado',
        'posgrado_validada',
        'posgrado_promedio',
        'cedula_profesional',
        'otros_certificados',
        'comentarios',
        'carrera_inactivo',
    ];

    // Método para obtener la fila según el id_candidato
    public function getByCandidatoId($id_candidato)
    {
        return $this->where('id_candidato', $id_candidato)->first();
    }

    // Otros métodos y relaciones del modelo pueden ir aquí
}
