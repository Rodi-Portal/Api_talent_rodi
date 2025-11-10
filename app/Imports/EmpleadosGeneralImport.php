<?php
namespace App\Imports;

use App\Models\Empleado;
use App\Models\EmpleadoCampoExtra;
use App\Models\DomicilioEmpleado;
use App\Models\Departamento;
use App\Models\PuestoEmpleado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EmpleadosGeneralImport implements ToCollection, WithHeadingRow
{
    protected int|string|null $idCliente;

    public function __construct($idCliente)
    {
        $this->idCliente = $idCliente;
    }

    /**
     * Alias BD si el encabezado no coincide exactamente
     */
    protected array $aliasDb = [
        'fecha_ingreso' => 'fecha_ingreso',
    ];

    /**
     * Columnas fijas esperadas en la hoja (normalizadas por WithHeadingRow)
     * Recuerda: "Departamento (Selección)" => departamento_seleccion, idem "Otro".
     */
    protected array $columnasFijas = [
        'id_portal', 'id_cliente', // vienen en el Excel (útiles para validar), pero usamos las del empleado como fuente de verdad
        'id', 'id_empleado', 'nombre', 'paterno', 'materno',
        'telefono', 'correo', 'rfc', 'curp', 'nss',

        'departamento_seleccion', 'departamento_otro',
        'puesto_seleccion', 'puesto_otro',

        'fecha_nacimiento', 'fecha_ingreso',

        'pais', 'estado', 'ciudad', 'colonia', 'calle', 'num_int', 'num_ext', 'cp',
    ];

    protected function normalizarCampo(string $campo): string
    {
        $campo = trim($campo);
        $campo = mb_strtolower($campo, 'UTF-8');
        $campo = preg_replace('/\s+/', '_', $campo);
        $campo = preg_replace('/[^a-z0-9_]/', '', $campo);
        return $campo;
    }

    protected function esValorParaEliminar($valor): bool
    {
        if ($valor === null) return true;
        $str  = trim((string) $valor);
        $norm = strtoupper(preg_replace('/\s+/', '', $str));
        return in_array($norm, ['', '--', 'BORRAR'], true);
    }

    public function collection(Collection $rows)
    {
        // Validar encabezados mínimos
        $cabecerasObligatorias = ['id', 'nombre', 'paterno'];
        $primeraFila           = $rows->first();
        $cabecerasArchivo      = array_map(fn($c) => $this->normalizarCampo($c), array_keys($primeraFila?->toArray() ?? []));

        foreach ($cabecerasObligatorias as $campoEsperado) {
            $campoEsperadoNorm = $this->normalizarCampo($campoEsperado);
            if (!in_array($campoEsperadoNorm, $cabecerasArchivo, true)) {
                throw new \Exception(
                    "El archivo no es válido. Faltan columnas clave (por ejemplo: ID, Nombre, Paterno)."
                );
            }
        }

        // Validación previa: IDs pertenecen al cliente actual
        $idsInvalidos = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!$id) { $idsInvalidos[] = '[sin ID]'; continue; }
            $emp = Empleado::find($id);
            if (!$emp || ($this->idCliente !== null && (string)$emp->id_cliente !== (string)$this->idCliente)) {
                $idsInvalidos[] = $id;
            }
        }
        if ($idsInvalidos) {
            throw new \Exception("Hay empleados que no pertenecen a la sucursal/cliente activo. Verifique el archivo.");
        }

        // Import fila x fila (con transacción por fila para robustez)
        foreach ($rows as $row) {
            DB::connection('portal_main')->transaction(function () use ($row) {
                try {
                    // Normalizar fila
                    $filaNorm = [];
                    $original = [];
                    foreach ($row as $campo => $valor) {
                        $original[$campo] = $valor;
                        $filaNorm[$this->normalizarCampo($campo)] = $valor;
                    }

                    // ID
                    $empleadoId = $filaNorm['id'] ?? null;
                    if (!$empleadoId) return;

                    /** @var Empleado $empleado */
                    $empleado = Empleado::with('domicilioEmpleado')->find($empleadoId);
                    if (!$empleado) return;

                    // ===== Resolver Departamento/Puesto según (Selección/Otro)
                    // Tomamos ámbito del empleado (fuente de verdad)
                    $portalId  = (int)($empleado->id_portal ?? 0);
                    $clienteId = (int)($empleado->id_cliente ?? 0);

                    // Departamento
                    $depSelect = trim((string)($filaNorm['departamento_seleccion'] ?? ''));
                    $depOtro   = trim((string)($filaNorm['departamento_otro'] ?? ''));
                    $depNombreFinal = $this->resolverCatalogoNombre($depSelect, $depOtro);

                    // Puesto
                    $ptoSelect = trim((string)($filaNorm['puesto_seleccion'] ?? ''));
                    $ptoOtro   = trim((string)($filaNorm['puesto_otro'] ?? ''));
                    $ptoNombreFinal = $this->resolverCatalogoNombre($ptoSelect, $ptoOtro);

                    // Buscar/crear IDs
                    [$depId, $depNombre] = $this->buscarOCrearDepartamento($portalId, $clienteId, $depNombreFinal);
                    [$ptoId, $ptoNombre] = $this->buscarOCrearPuesto($portalId, $clienteId, $ptoNombreFinal);

                    // ===== Armar datos a actualizar del Empleado
                    $columnasFijasNorm = array_map([$this, 'normalizarCampo'], $this->columnasFijas);
                    $datosActualizar   = [];

                    foreach ($columnasFijasNorm as $campoFijo) {
                        if (in_array($campoFijo, ['id', 'id_portal', 'id_cliente', 'departamento_seleccion', 'departamento_otro', 'puesto_seleccion', 'puesto_otro'], true)) {
                            continue; // se manejan aparte
                        }
                        if (in_array($campoFijo, ['pais', 'estado', 'ciudad', 'colonia', 'calle', 'num_int', 'num_ext', 'cp'], true)) {
                            continue; // domicilio por relación
                        }

                        $columnaDb = $this->aliasDb[$campoFijo] ?? $campoFijo;

                        if ($campoFijo === 'fecha_nacimiento' || $campoFijo === 'fecha_ingreso') {
                            $fechaRaw = $filaNorm[$campoFijo] ?? null;
                            $datosActualizar[$columnaDb] = $this->parseFechaNullable($fechaRaw);
                            continue;
                        }

                        $valor = $filaNorm[$campoFijo] ?? null;
                        $datosActualizar[$columnaDb] = $this->esValorParaEliminar($valor) ? null : trim((string)$valor);
                    }

                    // Inyectar catálogo resuelto
                    if ($depNombre !== '') {
                        $datosActualizar['id_departamento'] = $depId ?: null;
                        $datosActualizar['departamento']    = $depNombre; // legacy visible
                    } else {
                        $datosActualizar['id_departamento'] = null;
                        $datosActualizar['departamento']    = null;
                    }

                    if ($ptoNombre !== '') {
                        $datosActualizar['id_puesto'] = $ptoId ?: null;
                        $datosActualizar['puesto']     = $ptoNombre; // legacy visible
                    } else {
                        $datosActualizar['id_puesto'] = null;
                        $datosActualizar['puesto']     = null;
                    }

                    // Actualizar empleado (revisa fillable)
                    if ($datosActualizar) {
                        $empleado->update($datosActualizar);
                    }

                    // ===== Domicilio (si existe relación)
                    if ($empleado->domicilioEmpleado) {
                        $datosDom = [];
                        foreach (['pais','estado','ciudad','colonia','calle','num_int','num_ext','cp'] as $c) {
                            $v = $filaNorm[$c] ?? null;
                            $datosDom[$c] = $this->esValorParaEliminar($v) ? null : trim((string)$v);
                        }
                        $empleado->domicilioEmpleado->update($datosDom);
                    }

                    // ===== Campos extra (solo los que vienen; no borres todo lo demás automáticamente)
                    $columnasFijasSet = array_flip($columnasFijasNorm);
                    foreach ($original as $nombreOriginal => $valorOriginal) {
                        $norm = $this->normalizarCampo($nombreOriginal);
                        if (isset($columnasFijasSet[$norm])) continue;

                        $valor = $this->esValorParaEliminar($valorOriginal) ? null : trim((string)$valorOriginal);

                        // Busca por nombre normalizado
                        $existente = EmpleadoCampoExtra::where('id_empleado', $empleado->id)->get()
                            ->first(function ($item) use ($nombreOriginal) {
                                return $this->normalizarCampo($item->nombre) === $this->normalizarCampo($nombreOriginal);
                            });

                        if ($valor === null) {
                            if ($existente) $existente->delete();
                        } else {
                            if ($existente) {
                                $existente->valor = $valor;
                                $existente->save();
                            } else {
                                EmpleadoCampoExtra::create([
                                    'id_empleado' => $empleado->id,
                                    'nombre'      => $nombreOriginal,
                                    'valor'       => $valor,
                                ]);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error("Error importando fila ID ".($row['id'] ?? 'N/A').": ".$e->getMessage());
                    throw $e; // para que la transacción de la fila haga rollback
                }
            });
        }
    }

    /**
     * Reglas: si selección es "Otro" o vacío, usa el texto de la columna (Otro).
     * Si selección trae un nombre válido (distinto de "Otro"), usa selección.
     */
    protected function resolverCatalogoNombre(string $seleccion, string $otro): string
    {
        $sel = trim($seleccion);
        $ot  = trim($otro);
        if ($sel === '' || strcasecmp($sel, 'otro') === 0) {
            return $ot; // puede ser vacío => se limpiará arriba
        }
        return $sel;
    }

    protected function buscarOCrearDepartamento(int $portalId, int $clienteId, string $nombre): array
    {
        $n = trim($nombre);
        if ($n === '') return [null, ''];

        $dep = Departamento::on('portal_main')->firstOrCreate(
            ['id_portal' => $portalId, 'id_cliente' => $clienteId, 'nombre' => $n],
            ['status' => 1]
        );

        return [$dep->id, $dep->nombre];
    }

    protected function buscarOCrearPuesto(int $portalId, int $clienteId, string $nombre): array
    {
        $n = trim($nombre);
        if ($n === '') return [null, ''];

        $pto = PuestoEmpleado::on('portal_main')->firstOrCreate(
            ['id_portal' => $portalId, 'id_cliente' => $clienteId, 'nombre' => $n],
            ['status' => 1]
        );

        return [$pto->id, $pto->nombre];
    }

    protected function parseFechaNullable($raw): ?string
    {
        if ($this->esValorParaEliminar($raw)) return null;

        try {
            if (is_numeric($raw)) {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($raw);
                return \Carbon\Carbon::instance($dt)->format('Y-m-d');
            }
            $formatos = ['Y-m-d','d/m/Y','d-m-Y','Y/m/d','m/d/Y','m-d-Y','d M Y','d-M-Y','d F Y','Ymd','d.m.Y','Y.m.d'];
            foreach ($formatos as $f) {
                try {
                    $fecha = \Carbon\Carbon::createFromFormat($f, trim((string)$raw));
                    if ($fecha) return $fecha->format('Y-m-d');
                } catch (\Throwable) {}
            }
            // último recurso: si viene ISO con tiempo
            $iso = \Carbon\Carbon::parse((string)$raw);
            return $iso?->format('Y-m-d');
        } catch (\Throwable $e) {
            Log::warning("Fecha inválida '{$raw}': ".$e->getMessage());
            return null;
        }
    }
}
