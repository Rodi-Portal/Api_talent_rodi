<?php
namespace App\Http\Requests\Comunicacion;

use Illuminate\Foundation\Http\FormRequest;

class RecordatorioRequest extends FormRequest
{
    public function authorize()
    {
        // Pon lógica de permisos si la necesitas; por ahora, true
        return true;
    }

    public function rules()
    {
        return [
            'nombre'            => 'required|string|max:160',
            'descripcion'       => 'nullable|string',
            'tipo'              => 'required|in:unico,mensual',
            'fecha_base'        => 'required|date_format:Y-m-d',
            'intervalo_meses'   => 'nullable|required_if:tipo,mensual|integer|min:1|max:12',
            'dias_anticipacion' => 'required|integer|min:0|max:60',
            'activo'            => 'required|in:0,1',
        ];
    }

    public function messages()
    {
        return [
            'nombre.required'             => 'El nombre es obligatorio.',
            'fecha_base.date_format'      => 'La fecha debe tener el formato YYYY-MM-DD.',
            'intervalo_meses.required_if' => 'El intervalo es requerido cuando el tipo es mensual.',
        ];
    }
    public function attributes()
    {
        return [
            'nombre'            => 'nombre del recordatorio',
            'fecha_base'        => 'fecha de recordatorio',
            'intervalo_meses'   => 'intervalo (meses)',
            'dias_anticipacion' => 'días de anticipación',
        ];
    }

}
