<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoDocumento extends Model
{
    protected $connection = 'rodi_main';
    protected $table = 'candidato_documento';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_candidato',
        'id_tipo_documento',
        'archivo',
        'numero_clave',
        'institucion',
        'eliminado'
    ];



    // Relación con Candidato
    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato', 'id');
    }

    // Relación con TipoDocumentacion
    public function tipoDocumento()
    {
        return $this->belongsTo(TipoDocumentacion::class, 'id_tipo_documento', 'id');
    }


    public static function getDocumentacion($id_candidato)
    {
        return self::select('candidato_documento.id', 'candidato_documento.id_tipo_documento', 'candidato_documento.archivo', 'tipo_documentacion.nombre as tipo')
            ->leftJoin('tipo_documentacion', 'tipo_documentacion.id', '=', 'candidato_documento.id_tipo_documento')
            ->where('candidato_documento.id_candidato', $id_candidato)
            ->where('candidato_documento.eliminado', 0)
            ->orderBy('candidato_documento.id_tipo_documento', 'ASC')
            ->get();
    }
}