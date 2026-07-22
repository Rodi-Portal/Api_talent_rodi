<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Model;

class RolAuth extends Model
{
    protected $connection = 'portal_main';
    protected $table = 'rol';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [
        'id',
    ];

    protected $casts = [
        'id'        => 'integer',
        'status'    => 'integer',
        'eliminado' => 'integer',
    ];
}