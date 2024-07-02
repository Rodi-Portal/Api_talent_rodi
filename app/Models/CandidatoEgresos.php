<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CandidatoEgresos extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_egresos';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'sueldo',
        'aportacion',
        'bienes',
        'deudas',
        'renta',
        'alimentos',
        'luz',
        'telefono',
        'gas',
        'gasolina',
        'agua',
        'educacion',
        'vestimenta',
        'medicamento',
        'diversion',
        'cable',
        'internet',
        'tarjeta_credito',
        'celular',
        'infonavit',
        'servicios',
        'transporte',
        'transporte_diario',
        'otros',
        'unico_solvente',
        'solvencia',
        'tiene_credito_banco',
        'credito_banco_importe',
        'credito_banco_saldo',
        'credito_banco_estatus',
        'tiene_credito_infonavit',
        'credito_infonavit_importe',
        'credito_infonavit_saldo',
        'credito_infonavit_estatus',
        'tiene_otro_credito',
        'credito_otro_importe',
        'credito_otro_saldo',
        'credito_otro_estatus',
        'tiene_casa',
        'casa_ubicacion',
        'casa_valor',
        'casa_titular',
        'tiene_automovil',
        'automovil',
        'automovil_valor',
        'automovil_titular',
        'observacion',
    ];

    public function getById($id)
    {
        return $this->select('candidato_egresos.*', 'candidato.muebles', 'candidato.adeudo_muebles', 'candidato.comentario', 'candidato.ingresos', 'candidato.ingresos_extra')
                    ->join('candidato', 'candidato.id', '=', 'candidato_egresos.id_candidato')
                    ->where('candidato_egresos.id_candidato', $id)
                    ->first();
    }
}
