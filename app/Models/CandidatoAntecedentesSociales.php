<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoAntecedentesSociales extends Model
{
    protected $table = 'candidato_antecedentes_sociales';
    protected $primaryKey = 'id'; // Ajustar si la clave primaria no es 'id'
    public $timestamps = false; // Deshabilitar timestamps automáticos si no se utilizan
    protected $fillable = [
        'id_usuario', 'id_candidato', 'sindical', 'sindical_nombre', 'sindical_corporacion', 'sindical_cargo',
        'partido', 'partido_nombre', 'partido_cargo', 'club', 'deporte', 'religion', 'religion_frecuencia',
        'bebidas', 'bebidas_frecuencia', 'fumar', 'fumar_frecuencia', 'cirugia', 'enfermedades',
        'corto_plazo', 'mediano_plazo', 'tiene_familiar_penal', 'familiar_nombre_penal',
        'familiar_parentesco_penal', 'familiar_motivo_penal', 'tiene_persona_empresa',
        'persona_nombre_empresa', 'persona_puesto_empresa', 'tiene_familiar_policia',
        'familiar_nombre_policia', 'familiar_corporacion_policia', 'tiene_otro_trabajo',
        'otro_trabajo_empresa', 'otro_trabajo_puesto', 'otro_trabajo_actividades', 'plan_residencia',
        'plan_viaje', 'plan_familia'
    ];

    /**
     * Función para obtener todos los datos de antecedentes sociales de un candidato específico.
     *
     * @param int $idCandidato
     * @return \Illuminate\Database\Eloquent\Collection|bool
     */
    public function getAntecedentesSocialesByIdCandidato($idCandidato)
    {
        return $this->where('id_candidato', $idCandidato)->get();
    }
}
