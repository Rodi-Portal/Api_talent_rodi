<?php
namespace App\Models\Auth;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class EmpleadoAuth extends Authenticatable
{
    use HasApiTokens;

    protected $table      = 'empleados';
    protected $connection = 'portal_main';
    protected $primaryKey = 'id';
    public $timestamps    = false;

    protected $fillable = [
        'password',
        'login_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
        'force_password_change',
        'activation_token',
        'activation_expires_at',
        'status',
        'eliminado',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'activation_token',
    ];

    protected $casts = [
        'locked_until'          => 'datetime',
        'last_login_at'         => 'datetime',
        'activation_expires_at' => 'datetime',
        'force_password_change' => 'boolean',
    ];
}
