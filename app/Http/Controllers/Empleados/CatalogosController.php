<?php 
// app/Http/Controllers/CatalogosController.php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller; // Asegúrate de tener esta línea arriba para utilizar Log

use Illuminate\Http\Request;
use App\Models\Departamento;
use App\Models\PuestoEmpleado;

class CatalogosController extends Controller
{
    public function departamentos(Request $r) {
        $r->validate(['portal_id'=>'required|integer','cliente_id'=>'nullable|integer']);
        return Departamento::query()
            ->where('id_portal',  $r->portal_id)
            ->where('id_cliente', $r->cliente_id ?? 0)
            ->where('status', 1)
            ->orderBy('nombre')->get(['id','nombre']);
    }

    public function puestos(Request $r) {
        $r->validate(['portal_id'=>'required|integer','cliente_id'=>'nullable|integer']);
        return PuestoEmpleado::query()
            ->where('id_portal',  $r->portal_id)
            ->where('id_cliente', $r->cliente_id ?? 0)
            ->where('status', 1)
            ->orderBy('nombre')->get(['id','nombre']);
    }

    // Opcional: crear desde UI (evita duplicados por ámbito)
    public function crearDepartamento(Request $r) {
        $data = $r->validate([
            'portal_id'=>'required|integer','cliente_id'=>'nullable|integer',
            'nombre'=>'required|string|max:120'
        ]);
        $dep = Departamento::firstOrCreate(
            ['id_portal'=>$data['portal_id'], 'id_cliente'=>$data['cliente_id'] ?? 0, 'nombre'=>trim($data['nombre'])],
            ['status'=>1]
        );
        return response()->json(['ok'=>true,'data'=>$dep], 201);
    }

    public function crearPuesto(Request $r) {
        $data = $r->validate([
            'portal_id'=>'required|integer','cliente_id'=>'nullable|integer',
            'nombre'=>'required|string|max:120'
        ]);
        $p = PuestoEmpleado::firstOrCreate(
            ['id_portal'=>$data['portal_id'], 'id_cliente'=>$data['cliente_id'] ?? 0, 'nombre'=>trim($data['nombre'])],
            ['status'=>1]
        );
        return response()->json(['ok'=>true,'data'=>$p], 201);
    }
}
