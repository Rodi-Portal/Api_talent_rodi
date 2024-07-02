<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoRefLaboral extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_ref_laboral';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_candidato',
        'numero_referencia',
        'empresa',
        'direccion',
        'tipo_empresa',
        'sindicato_puesto',
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
        'detalle_separacion',
        'reingresar',
    ];

    /**
     * Get Candidato Ref Laboral by Candidato ID
     *
     * @param int $id
     * @return mixed
     */
    public function getById($id)
    {
        return $this->where('id_candidato', $id)->first();
    }
}