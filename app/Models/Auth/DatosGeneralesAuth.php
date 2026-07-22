<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Model;

class DatosGeneralesAuth extends Model
{
    protected $connection = 'portal_main';
    protected $table = 'datos_generales';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [
        'id',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'id'           => 'integer',
        'verificacion' => 'integer',
    ];
}