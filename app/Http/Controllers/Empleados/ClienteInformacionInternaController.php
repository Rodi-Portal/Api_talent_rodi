<?php

namespace App\Http\Controllers\Empleados;
use App\Http\Controllers\Controller;

use App\Models\ClienteInformacionInterna;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ClienteInformacionInternaController extends Controller
{
    public function index(Request $request)
    {
        $query = ClienteInformacionInterna::query();

        if ($request->filled('id_portal')) {
            $query->where('id_portal', $request->id_portal);
        }

        if ($request->filled('id_cliente')) {
            $query->where('id_cliente', $request->id_cliente);
        }

        $info = $query->with('documentos')->orderBy('nombre')->get();

        return response()->json($info);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id_portal'   => 'required|integer',
            'id_cliente'  => 'required|integer',
            'nombre'      => 'required|string|max:150',
            'descripcion' => 'nullable|string',
        ]);

        $now = Carbon::now();

        $data['creacion'] = $now;
        $data['edicion']  = $now;

        $info = ClienteInformacionInterna::create($data);

        return response()->json($info, 201);
    }

    public function update(Request $request, ClienteInformacionInterna $informacion)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:150',
            'descripcion' => 'nullable|string',
        ]);

        $data['edicion'] = now();

        $informacion->update($data);

        return response()->json($informacion);
    }

    public function destroy(ClienteInformacionInterna $informacion)
    {
        $informacion->delete();

        return response()->json(['ok' => true]);
    }
}
