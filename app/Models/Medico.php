<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Medico extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'medico';
    public $timestamps = false;

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
        'deporte',
        'deporte_cual',
        'alimentacion',
        'quirurgicos',
        'quirurgicos_hace_cuanto',
        'quirurgicos_cual',
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
        'alergias_cual',
        'hipotiroidismo',
        'hipertiroidismo',
        'peso',
        'estatura',
        'imc',
        'grasa',
        'musculo',
        'edad_metabolica',
        'presion',
        'frecuencia_cardiaca',
        'glucosa',
        'grasa_visceral',
        'calorias',
        'vision_izquierda',
        'vision_derecha',
        'lentes',
        'vision_color',
        'descripcion',
        'sangre',
        'telefono_emergencia',
        'avisar_a',
        'imagen_historia_clinica',
        'archivo_examen_medico',
        'conclusion',
        'status'
    ];

    // Relaciones
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id');
    }

    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato', 'id');
    }
}