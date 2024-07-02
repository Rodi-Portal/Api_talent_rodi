<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoSalud extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'candidato_salud';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'diabetes',
        'hipertension',
        'cancer',
        'cardiopatias',
        'pulmonares',
        'renales',
        'psiquiatricas',
        'tipo_sangre',
        'cuales_antecedentes_familiares',
        'tabaco',
        'tabaco_cantidad',
        'tabaco_frecuencia',
        'alcohol',
        'alcohol_cantidad',
        'alcohol_frecuencia',
        'droga',
        'droga_tipo',
        'droga_frecuencia',
        'alimentacion',
        'internamientos',
        'internamientos_hace_cuanto',
        'internamientos_porque',
        'transfusiones',
        'transfusiones_hace_cuanto',
        'transfusiones_porque',
        'fracturas',
        'esguinces',
        'luxaciones',
        'traumatismo',
        'hernia',
        'lesiones_columna',
        'alergias',
        'enfermedad_cronica',
        'enfermedad_actual',
        'accidentes',
        'intervencion_quirurgica',
        'medicamento_prescrito',
        'color_piel',
        'particularidades',
        'peso',
        'estatura',
        'composicion_fisica',
        'imc',
        'grasa',
        'musculo',
        'edad_metabolica',
        'presion',
        'frecuencia_cardiaca',
        'glucosa',
        'grasa_visceral',
        'calorias',
        'practica_deporte',
        'deporte',
        'deporte_frecuencia',
        'vision_izquierda',
        'vision_derecha',
        'lentes',
        'color_ojos',
        'vision_color',
        'descripcion',
        'telefono_emergencia',
        'contacto_emergencia',
        'archivo_examen_medico',
        'conclusion',
        'status',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    public function getById($id_candidato)
    {
        return $this->where('id_candidato', $id_candidato)->first();
    }
}
