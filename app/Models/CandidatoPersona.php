<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoPersona extends Model
{
    protected $table = 'candidato_persona';
    protected $primaryKey = 'id'; // Ajustar si la clave primaria no es 'id'
    public $timestamps = false; // Deshabilitar timestamps automáticos si no se utilizan
    protected $fillable = [
        'id_candidato', 'nombre', 'id_tipo_parentesco', 'edad', 'estado_civil', 'id_grado_estudio',
        'depende_candidato', 'misma_vivienda', 'familiar', 'ciudad', 'empresa', 'puesto', 'sueldo',
        'antiguedad', 'monto_aporta', 'muebles', 'adeudo', 'status', 'eliminado'
    ];

    /**
     * Función para obtener todos los datos de la persona asociada a un candidato específico.
     *
     * @param int $idCandidato
     * @return \Illuminate\Database\Eloquent\Collection|bool
     */
    public function getById($idCandidato)
    {
        return $this->where('id_candidato', $idCandidato)->get();
    }

    public function getPersonaByIdCandidato($id)
    {
        return $this->select('candidato_persona.*', 'tipo_parentesco.nombre as parentesco', 'tipo_escolaridad.nombre as escolaridad')
                    ->join('tipo_parentesco', 'tipo_parentesco.id', '=', 'candidato_persona.id_tipo_parentesco')
                    ->join('tipo_escolaridad', 'tipo_escolaridad.id', '=', 'candidato_persona.id_grado_estudio')
                    ->where('tipo_parentesco.eliminado', 0)
                    ->where('candidato_persona.id_candidato', $id)
                    ->orderByDesc('candidato_persona.id')
                    ->get();
    }
}
