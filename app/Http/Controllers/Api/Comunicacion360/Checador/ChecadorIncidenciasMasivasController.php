<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Support\ChecadorImportConfig;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChecadorIncidenciasMasivasController extends Controller
{
    private const CACHE_PREFIX = 'incidencias_masivas_preview_';
    public function exportarPlantilla(Request $request)
    {
        $locale    = strtolower($request->input('locale', 'es'));
        $idPortal  = (int) $request->id_portal;
        $idCliente = (int) $request->id_cliente;

        $portal = DB::connection('portal_main')
            ->table('portal')
            ->where('id', $idPortal)
            ->select('nombre')
            ->first();

        $cliente = DB::connection('portal_main')
            ->table('cliente')
            ->where('id', $idCliente)
            ->where('id_portal', $idPortal)
            ->select('nombre')
            ->first();

        $nombrePortal  = $portal->nombre ?? 'Portal';
        $nombreCliente = $cliente->nombre ?? 'Cliente';

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle($locale === 'en' ? 'Incidents' : 'Incidencias');

        $headers = $locale === 'en'
            ? [
            'Employee',
            'Employee Name',
            'Begin Date',
            'End Date',
            'Event Type',
            'Days',
            'Reason',
            'Approval Status',
        ]
            : [
            'Empleado',
            'Nombre empleado',
            'Fecha inicio',
            'Fecha fin',
            'Tipo de evento',
            'Días',
            'Motivo',
            'Estado de aprobación',
        ];

        $t = [
            'client'    => $locale === 'en' ? 'Client' : 'Cliente',
            'template'  => $locale === 'en'
                ? 'Mass incidents import template'
                : 'Plantilla de importación masiva de incidencias',
            'generated' => $locale === 'en' ? 'Generated' : 'Generado',
            'note'      => $locale === 'en'
                ? 'Employee must match the employee code configured in the system.'
                : 'Empleado debe coincidir con el código configurado en el sistema.',
        ];

        $sheet->setCellValue('A1', strtoupper($nombrePortal));
        $sheet->setCellValue('A2', $t['client'] . ': ' . $nombreCliente);
        $sheet->setCellValue('A3', $t['template']);
        $sheet->setCellValue('A4', $t['generated'] . ': ' . now()->format('d/m/Y H:i'));
        $sheet->setCellValue('A5', $t['note']);

        $sheet->mergeCells('A1:H1');
        $sheet->mergeCells('A2:H2');
        $sheet->mergeCells('A3:H3');
        $sheet->mergeCells('A4:H4');
        $sheet->mergeCells('A5:H5');

        $sheet->fromArray($headers, null, 'A7');

        $sheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A2:H2')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A5:H5')->getFont()->setItalic(true);

        $sheet->getStyle('A7:H7')->getFont()
            ->setBold(true)
            ->getColor()
            ->setARGB('FFFFFFFF');
        $sheet->getStyle('A7:H7')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FF1F4E78');

        $sheet->getStyle('A7:H7')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        //$sheet->freezePane('A8');
        $sheet->setAutoFilter('A7:H7');

        $eventos = DB::connection('portal_main')
            ->table('eventos_option')
            ->where(function ($query) use ($idPortal) {
                $query->whereNull('id_portal')
                    ->orWhere('id_portal', $idPortal);
            })
            ->orderByRaw('id_portal IS NOT NULL ASC')
            ->orderBy('name')
            ->get(['id', 'name', 'id_portal']);

        $eventosLista = $eventos
            ->map(function ($evento) use ($locale) {
                if (is_null($evento->id_portal)) {
                    return $this->traducirEventoParaExcel($evento->name, $locale);
                }

                return $evento->name;
            })
            ->filter()
            ->values()
            ->toArray();

        $statusLista = $locale === 'en'
            ? ['Not required', 'Pending', 'Approved', 'Rejected', 'Cancelled']
            : ['No requiere', 'Pendiente', 'Aprobado', 'Rechazado', 'Cancelado'];

        $rowStart = 8;
        $rowEnd   = 207;

        for ($row = $rowStart; $row <= $rowEnd; $row++) {
            $eventoValidation = $sheet->getCell("E{$row}")->getDataValidation();
            $eventoValidation->setType(DataValidation::TYPE_LIST);
            $eventoValidation->setErrorStyle(DataValidation::STYLE_INFORMATION);
            $eventoValidation->setAllowBlank(false);
            $eventoValidation->setShowDropDown(true);
            $eventoValidation->setFormula1('"' . implode(',', $eventosLista) . '"');

            $statusValidation = $sheet->getCell("H{$row}")->getDataValidation();
            $statusValidation->setType(DataValidation::TYPE_LIST);
            $statusValidation->setErrorStyle(DataValidation::STYLE_STOP);
            $statusValidation->setAllowBlank(false);
            $statusValidation->setShowDropDown(true);
            $statusValidation->setFormula1('"' . implode(',', $statusLista) . '"');
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheet->getStyle("A7:H{$rowEnd}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle("C{$rowStart}:D{$rowEnd}")
            ->getNumberFormat()
            ->setFormatCode('yyyy-mm-dd');

        $sheet->getStyle("F{$rowStart}:F{$rowEnd}")
            ->getNumberFormat()
            ->setFormatCode('0');

        $fileName = 'plantilla_incidencias_masivas_' . now()->format('Ymd_His') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }
    private function traducirEventoParaExcel(string $name, string $locale): string
    {
        if ($locale !== 'en') {
            return $name;
        }

        return match ($name) {
            'Vacaciones'        => 'Vacation',
            'Incapacidad'       => 'Disability',
            'Permiso'           => 'Permission',
            'Falta'             => 'Absence',
            'Retardo'           => 'Late arrival',
            'Salida anticipada' => 'Early departure',
            'Horas Extras'      => 'Overtime',
            'Día Festivo'       => 'Holiday',
            default             => $name,
        };
    }

    public function importarPreview(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls',
        ]);

        $locale    = strtolower($request->input('locale', 'es'));
        $idPortal  = (int) $request->input('id_portal');
        $idCliente = (int) $request->input('id_cliente');
        $idUsuario = (int) $request->input('id_usuario');

        if (! $idPortal || ! $idCliente) {
            return response()->json([
                'status'  => false,
                'message' => $locale === 'en'
                    ? 'Portal and client are required.'
                    : 'Portal y cliente son requeridos.',
            ], 422);
        }

        $archivo = $request->file('archivo');

        $spreadsheet = IOFactory::load($archivo->getRealPath());
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray(null, true, true, false);

        $filaInicioDatos = 8;

        $headers = [
            'codigo_empleado',
            'empleado',
            'fecha_inicio',
            'fecha_fin',
            'tipo_evento',
            'dias',
            'motivo',
            'estado_aprobacion',
        ];

        $preview = [];

        $resumen = [
            'total'      => 0,
            'ok'         => 0,
            'advertidas' => 0,
            'errores'    => 0,
        ];
        $filasProcesadas = 0;
        foreach ($rows as $index => $row) {
            $numeroFilaExcel = $index + 1;

            if ($numeroFilaExcel < $filaInicioDatos) {
                continue;
            }

            if (count(array_filter($row, fn($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $filasProcesadas++;

            if ($filasProcesadas > ChecadorImportConfig::MAX_IMPORT_ROWS) {
                return response()->json([
                    'status'  => false,
                    'message' => $locale === 'en'
                        ? 'The file exceeds the maximum limit of '
                    . ChecadorImportConfig::MAX_IMPORT_ROWS
                    . ' records per import.'
                        : 'El archivo supera el límite máximo de '
                    . ChecadorImportConfig::MAX_IMPORT_ROWS
                    . ' registros por importación.',
                ], 422);
            }
            $data = array_combine(
                $headers,
                array_pad($row, count($headers), null)
            );

            $errores  = [];
            $warnings = [];

            $codigoEmpleado = trim((string) ($data['codigo_empleado'] ?? ''));
            $nombreEmpleado = trim((string) ($data['empleado'] ?? ''));
            $tipoEventoRaw  = trim((string) ($data['tipo_evento'] ?? ''));
            $motivo         = trim((string) ($data['motivo'] ?? ''));
            $estadoRaw      = trim((string) ($data['estado_aprobacion'] ?? ''));

            $fechaInicio = $this->parseExcelDate($data['fecha_inicio'] ?? null);
            $fechaFin    = $this->parseExcelDate($data['fecha_fin'] ?? null);

            $dias = $data['dias'] ?? null;
            $dias = is_numeric($dias) ? (int) $dias : null;

            if (! $codigoEmpleado) {
                $errores[] = $locale === 'en'
                    ? 'Employee is required.'
                    : 'Empleado es requerido.';
            }

            if (! $fechaInicio) {
                $errores[] = $locale === 'en'
                    ? 'Begin Date is required or invalid.'
                    : 'Fecha inicio es requerida o inválida.';
            }

            if (! $fechaFin) {
                $errores[] = $locale === 'en'
                    ? 'End Date is required or invalid.'
                    : 'Fecha fin es requerida o inválida.';
            }

            if ($fechaInicio && $fechaFin && Carbon::parse($fechaFin)->lt(Carbon::parse($fechaInicio))) {
                $errores[] = $locale === 'en'
                    ? 'End Date cannot be before Begin Date.'
                    : 'Fecha fin no puede ser menor que Fecha inicio.';
            }

            if (! $tipoEventoRaw) {
                $errores[] = $locale === 'en'
                    ? 'Event Type is required.'
                    : 'Tipo de evento es requerido.';
            }

            if (! $dias || $dias < 1) {
                if ($fechaInicio && $fechaFin) {
                    $dias       = Carbon::parse($fechaInicio)->diffInDays(Carbon::parse($fechaFin)) + 1;
                    $warnings[] = $locale === 'en'
                        ? 'Days was empty or invalid and was calculated automatically.'
                        : 'Días venía vacío o inválido y fue calculado automáticamente.';
                } else {
                    $errores[] = $locale === 'en'
                        ? 'Days is required.'
                        : 'Días es requerido.';
                }
            }

            $estadoAprobacion = $this->normalizarEstadoAprobacion($estadoRaw);

            if (! $estadoAprobacion) {
                $errores[] = $locale === 'en'
                    ? 'Approval Status is invalid.'
                    : 'Estado de aprobación inválido.';
            }

            $empleado         = null;
            $nombreEmpleadoBd = null;

            if ($codigoEmpleado && $idPortal && $idCliente) {
                $empleado = DB::connection('portal_main')
                    ->table('empleados')
                    ->where('id_portal', $idPortal)
                    ->where('id_cliente', $idCliente)
                    ->where('id_empleado', $codigoEmpleado)
                    ->first();

                if ($empleado) {
                    $nombreEmpleadoBd = trim(implode(' ', array_filter([
                        $empleado->nombre ?? null,
                        $empleado->paterno ?? null,
                        $empleado->materno ?? null,
                    ])));
                }
                if (
                    $empleado &&
                    $nombreEmpleado &&
                    mb_strtolower(trim($nombreEmpleado)) !== mb_strtolower($nombreEmpleadoBd)
                ) {
                    $warnings[] = $locale === 'en'
                        ? "Employee name does not match. System: {$nombreEmpleadoBd}."
                        : "El nombre del empleado no coincide. En el sistema es: {$nombreEmpleadoBd}.";
                }
                if (! $empleado) {
                    $errores[] = $locale === 'en'
                        ? "Employee code {$codigoEmpleado} was not found."
                        : "No se encontró el empleado con código {$codigoEmpleado}.";
                }
            }

            $tipoEventoNormalizado = $this->normalizarEventoImportado($tipoEventoRaw);

            $eventoExistente = null;

            if ($tipoEventoNormalizado) {
                $eventoExistente = DB::connection('portal_main')
                    ->table('eventos_option')
                    ->where(function ($query) use ($idPortal) {
                        $query->whereNull('id_portal')
                            ->orWhere('id_portal', $idPortal);
                    })
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($tipoEventoNormalizado)])
                    ->first();

                if (! $eventoExistente) {
                    $warnings[] = $locale === 'en'
                        ? "Event type '{$tipoEventoRaw}' does not exist and will be created for this portal."
                        : "El tipo de evento '{$tipoEventoRaw}' no existe y se creará para este portal.";
                }
            }

            $duplicado = null;

            if ($empleado && $fechaInicio && $fechaFin && $tipoEventoNormalizado) {
                $idTipoDuplicado = $eventoExistente->id ?? null;

                if ($idTipoDuplicado) {
                    $duplicado = DB::connection('portal_main')
                        ->table('calendario_eventos')
                        ->where('id_portal', $idPortal)
                        ->where('id_cliente', $idCliente)
                        ->where('id_empleado', $empleado->id)
                        ->where('inicio', $fechaInicio)
                        ->where('fin', $fechaFin)
                        ->where('id_tipo', $idTipoDuplicado)
                        ->where('eliminado', 0)
                        ->first();

                    if ($duplicado) {
                        $warnings[] = $locale === 'en'
                            ? 'An identical event already exists. It will not be inserted again.'
                            : 'Ya existe una incidencia idéntica. No se insertará nuevamente.';
                    }
                }
            }

            $accion = 'insertar';

            if ($duplicado) {
                $accion = 'omitir_duplicado';
            }

            if (count($errores) > 0) {
                $status = 'error';
                $accion = 'no_procesar';
                $resumen['errores']++;
            } elseif (count($warnings) > 0) {
                $status = 'advertida';
                $resumen['advertidas']++;
            } else {
                $status = 'ok';
                $resumen['ok']++;
            }

            $resumen['total']++;

            $preview[] = [
                'fila'     => $numeroFilaExcel,
                'status'   => $status,
                'accion'   => $accion,
                'errores'  => $errores,
                'warnings' => $warnings,
                'data'     => [
                    'codigo_empleado'      => $codigoEmpleado,
                    'empleado'             => $nombreEmpleadoBd,
                    'empleado_excel'       => $nombreEmpleado,
                    'id_empleado'          => $empleado->id ?? null,
                    'id_portal'            => $idPortal,
                    'id_cliente'           => $idCliente,
                    'id_usuario'           => $idUsuario,
                    'fecha_inicio'         => $fechaInicio,
                    'fecha_fin'            => $fechaFin,
                    'dias_evento'          => $dias,
                    'tipo_evento_original' => $tipoEventoRaw,
                    'tipo_evento'          => $tipoEventoNormalizado,
                    'id_tipo'              => $eventoExistente->id ?? null,
                    'tipo_evento_nuevo'    => ! $eventoExistente,
                    'descripcion'          => $motivo,
                    'estado_aprobacion'    => $estadoAprobacion,
                    'requiere_aprobacion'  => $estadoAprobacion === 'pendiente' ? 1 : 0,
                    'duplicado'            => $duplicado ? [
                        'id'      => $duplicado->id,
                        'inicio'  => $duplicado->inicio,
                        'fin'     => $duplicado->fin,
                        'id_tipo' => $duplicado->id_tipo,
                    ] : null,
                ],
            ];
        }

        $previewToken = (string) Str::uuid();

        Cache::put(
            self::CACHE_PREFIX . $previewToken,
            [
                'resumen'         => $resumen,
                'preview'         => $preview,
                'puede_confirmar' => $resumen['errores'] === 0,
            ],
            now()->addMinutes(ChecadorImportConfig::CACHE_MINUTES)
        );

        return response()->json([
            'status'          => true,
            'message'         => $locale === 'en'
                ? 'Preview generated successfully.'
                : 'Preview generado correctamente.',
            'preview_token'   => $previewToken,
            'expires_in_min'  => ChecadorImportConfig::CACHE_MINUTES,
            'resumen'         => $resumen,
            'puede_confirmar' => $resumen['errores'] === 0,
            'preview'         => $preview,
        ]);
    }

    private function parseExcelDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)
                    ->format('Y-m-d');
            }

            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizarEstadoAprobacion(string $status): ?string
    {
        $status = mb_strtolower(trim($status));

        return match ($status) {
            'no requiere', 'not required', 'no_requiere' => 'no_requiere',
            'pendiente', 'pending'  => 'pendiente',
            'aprobado', 'approved'  => 'aprobado',
            'rechazado', 'rejected' => 'rechazado',
            'cancelado', 'cancelled', 'canceled'         => 'cancelado',
            default => null,
        };
    }

    private function normalizarEventoImportado(string $eventType): string
    {
        $eventType = trim($eventType);

        return match ($eventType) {
            'Vacation'        => 'Vacaciones',
            'Disability'      => 'Incapacidad',
            'Permission'      => 'Permiso',
            'Absence'         => 'Falta',
            'Late arrival'    => 'Retardo',
            'Early departure' => 'Salida anticipada',
            'Overtime'        => 'Horas Extras',
            'Holiday'         => 'Día Festivo',
            default           => $eventType,
        };
    }

    public function importarConfirmar(Request $request)
    {
        $request->validate([
            'preview_token' => 'required|string',
        ]);

        $locale = strtolower($request->input('locale', 'es'));

        $cacheKey = self::CACHE_PREFIX . $request->preview_token;
        $cached   = Cache::get($cacheKey);

        if (! $cached) {
            return response()->json([
                'status'  => false,
                'message' => $locale === 'en'
                    ? 'Preview token expired or invalid.'
                    : 'El token de vista previa expiró o no es válido.',
            ], 422);
        }

        if (! ($cached['puede_confirmar'] ?? false)) {
            return response()->json([
                'status'  => false,
                'message' => $locale === 'en'
                    ? 'The file has errors and cannot be confirmed.'
                    : 'El archivo tiene errores y no se puede confirmar.',
            ], 422);
        }

        $insertadas      = 0;
        $omitidas        = 0;
        $errores         = [];
        $detalleOmitidas = [];

        DB::connection('portal_main')->beginTransaction();

        try {
            foreach (($cached['preview'] ?? []) as $item) {
                $data = $item['data'] ?? [];

                if (($item['status'] ?? '') === 'error') {
                    $omitidas++;
                    continue;
                }

                if (($item['accion'] ?? '') === 'omitir_duplicado') {
                    $omitidas++;

                    $detalleOmitidas[] = [
                        'fila'         => $item['fila'] ?? null,
                        'empleado'     => $data['empleado'] ?? null,
                        'fecha_inicio' => $data['fecha_inicio'] ?? null,
                        'fecha_fin'    => $data['fecha_fin'] ?? null,
                        'tipo_evento'  => $data['tipo_evento'] ?? null,
                        'motivo'       => $locale === 'en'
                            ? 'An identical event already exists.'
                            : 'Ya existe una incidencia idéntica.',
                    ];

                    continue;
                }

                $idPortal   = (int) ($data['id_portal'] ?? 0);
                $idCliente  = (int) ($data['id_cliente'] ?? 0);
                $idUsuario  = (int) ($data['id_usuario'] ?? 0);
                $idEmpleado = (int) ($data['id_empleado'] ?? 0);

                $tipoEvento = trim((string) ($data['tipo_evento'] ?? ''));
                $idTipo     = (int) ($data['id_tipo'] ?? 0);

                if (! $idTipo && $tipoEvento) {
                    $evento = DB::connection('portal_main')
                        ->table('eventos_option')
                        ->where(function ($query) use ($idPortal) {
                            $query->whereNull('id_portal')
                                ->orWhere('id_portal', $idPortal);
                        })
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($tipoEvento)])
                        ->first();

                    if ($evento) {
                        $idTipo = (int) $evento->id;
                    } else {
                        $idTipo = DB::connection('portal_main')
                            ->table('eventos_option')
                            ->insertGetId([
                                'name'      => $tipoEvento,
                                'color'     => '#798929',
                                'id_portal' => $idPortal,
                                'creacion'  => now(),
                                'id_crol'   => 0,
                            ]);
                    }
                }

                if (! $idPortal || ! $idCliente || ! $idEmpleado || ! $idTipo) {
                    $omitidas++;
                    $errores[] = [
                        'fila'    => $item['fila'] ?? null,
                        'message' => $locale === 'en'
                            ? 'Missing required data to insert the event.'
                            : 'Faltan datos requeridos para insertar la incidencia.',
                    ];
                    continue;
                }

                $duplicado = DB::connection('portal_main')
                    ->table('calendario_eventos')
                    ->where('id_portal', $idPortal)
                    ->where('id_cliente', $idCliente)
                    ->where('id_empleado', $idEmpleado)
                    ->where('inicio', $data['fecha_inicio'])
                    ->where('fin', $data['fecha_fin'])
                    ->where('id_tipo', $idTipo)
                    ->where('eliminado', 0)
                    ->first();

                if ($duplicado) {
                    $omitidas++;

                    $detalleOmitidas[] = [
                        'fila'         => $item['fila'] ?? null,
                        'empleado'     => $data['empleado'] ?? null,
                        'fecha_inicio' => $data['fecha_inicio'] ?? null,
                        'fecha_fin'    => $data['fecha_fin'] ?? null,
                        'tipo_evento'  => $tipoEvento,
                        'motivo'       => $locale === 'en'
                            ? 'An identical event already exists.'
                            : 'Ya existe una incidencia idéntica.',
                    ];

                    continue;
                }

                DB::connection('portal_main')
                    ->table('calendario_eventos')
                    ->insert([
                        'id_usuario'           => $idUsuario ?: null,
                        'id_empleado'          => $idEmpleado,
                        'id_portal'            => $idPortal,
                        'id_cliente'           => $idCliente,
                        'inicio'               => $data['fecha_inicio'],
                        'fin'                  => $data['fecha_fin'],
                        'dias_evento'          => $data['dias_evento'] ?? null,
                        'descripcion'          => $data['descripcion'] ?? null,
                        'archivo'              => null,
                        'id_tipo'              => $idTipo,
                        'tipo_incapacidad_sat' => null,
                        'eliminado'            => 0,
                        'estado'               => 2,
                        'estado_aprobacion'    => $data['estado_aprobacion'] ?? 'no_requiere',
                        'origen_evento'        => 'integracion',
                        'requiere_aprobacion'  => (int) ($data['requiere_aprobacion'] ?? 0),
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ]);

                $insertadas++;
            }

            DB::connection('portal_main')->commit();
            Cache::forget($cacheKey);

            return response()->json([
                'status'           => true,
                'message'          => $locale === 'en'
                    ? 'Import confirmed successfully.'
                    : 'Importación confirmada correctamente.',
                'insertadas'       => $insertadas,
                'omitidas'         => $omitidas,
                'errores'          => $errores,
                'detalle_omitidas' => $detalleOmitidas,
            ]);
        } catch (\Throwable $e) {
            DB::connection('portal_main')->rollBack();

            return response()->json([
                'status'  => false,
                'message' => $locale === 'en'
                    ? 'An error occurred while confirming the import.'
                    : 'Ocurrió un error al confirmar la importación.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

}
