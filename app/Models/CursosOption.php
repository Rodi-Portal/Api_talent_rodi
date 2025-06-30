<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CursosOption extends Model
{
    use HasFactory;

    protected $table = 'cursos_options'; // Especifica el nombre de la tabla
    protected $connection = 'portal_main';
    public $timestamps = false;
   
    protected $fillable = [
        'name',
        'type',
        'id_portal',
    ];

    // Definir la relación con el modelo Portal
    public function portal()
    {
        return $this->belongsTo(Portal::class, 'id_portal');
    }
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }
}