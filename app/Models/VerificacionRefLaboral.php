<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionRefLaboral extends Model
{
    protected $connection = 'rodi_main'; // Asigna la conexión de base de datos
    protected $table = 'verificacion_ref_laboral'; // El nombre de la tabla
    public $timestamps = false; // Si la tabla no tiene columnas created_at y updated_at

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'numero_referencia',
        'empresa',
        'direccion',
        'fecha_entrada',
        'fecha_salida',
        'fecha_entrada_txt',
        'fecha_salida_txt',
        'telefono',
        'puesto1',
        'puesto2',
        'salario1',
        'salario2',
        'salario1_txt',
        'salario2_txt',
        'jefe_nombre',
        'jefe_correo',
        'jefe_puesto',
        'causa_separacion',
        'notas',
        'demanda',
        'responsabilidad',
        'iniciativa',
        'eficiencia',
        'disciplina',
        'puntualidad',
        'limpieza',
        'estabilidad',
        'emocional',
        'honestidad',
        'rendimiento',
        'actitud',
        'recontratacion',
        'motivo_recontratacion',
        'cualidades',
        'mejoras',
    ];

    /**
     * Relación con el modelo Candidato
     */
    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato');
    }

    /**
     * Relación con el modelo Usuario
     */
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    // Otros métodos y relaciones del modelo pueden ir aquí
}
