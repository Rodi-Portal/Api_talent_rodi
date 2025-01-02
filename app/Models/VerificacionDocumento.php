<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionDocumento extends Model
{
    protected $connection = 'rodi_main';
    protected $table = 'verificacion_documento';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'licencia',
        'licencia_institucion',
        'ine',
        'ine_ano',
        'ine_vertical',
        'ine_institucion',
        'penales',
        'penales_institucion',
        'domicilio',
        'fecha_domicilio',
        'militar',
        'militar_fecha',
        'pasaporte',
        'pasaporte_fecha',
        'forma_migratoria',
        'forma_migratoria_fecha',
        'imss',
        'imss_institucion',
        'rfc',
        'rfc_institucion',
        'curp',
        'curp_institucion',
        'carta_recomendacion',
        'carta_recomendacion_institucion',
        'licencia_manejo',
        'licencia_manejo_fecha',
        'motor_vehicle_records',
        'constancia_fiscal',
        'constancia_fiscal_fecha',
        'acta_constitutiva',
        'acta_constitutiva_fecha',
        'cedula',
        'cedula_fecha',
        'comentarios',
    ];

    /**
     * Relación con el modelo Candidato
     */
    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato');
    }

    // Método para obtener la fila según el id_candidato
    public function getByCandidatoId($id_candidato)
    {
        return $this->where('id_candidato', $id_candidato)->first();
    }

    // Otros métodos y relaciones del modelo pueden ir aquí
}
