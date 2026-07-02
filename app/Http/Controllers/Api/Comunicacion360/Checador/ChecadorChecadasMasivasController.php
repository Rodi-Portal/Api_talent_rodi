<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Services\Checador\ChecadaRegistroService;
use App\Services\Checador\ChecadorHorarioResolverService;
use App\Support\ChecadorImportConfig;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChecadorChecadasMasivasController extends Controller
{

    private const CACHE_PREFIX = 'checadas_masivas_preview_';
    public function exportarPlantilla(
        Request $request,
        ChecadorHorarioResolverService $horarioResolver
    ) {
        $locale    = strtolower($request->input('locale', 'es'));
        $idPortal  = (int) $request->id_portal;
        $idCliente = (int) $request->id_cliente;
        $empleados = $request->input('empleados', []);

        $inicio = Carbon::parse($request->fecha_inicio)->startOfDay();
        $fin    = Carbon::parse($request->fecha_fin)->startOfDay();

        $empleadosInfo = DB::connection('portal_main')
            ->table('empleados')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->whereIn('id', $empleados)
            ->select('id', 'id_empleado', 'nombre', 'paterno', 'materno')
            ->get()
            ->keyBy('id');

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
        $spreadsheet   = new Spreadsheet();
        $sheet         = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Checadas');

        $headers = $this->headers($locale);

        $t = $this->titulos($locale);

        $sheet->setCellValue('A1', strtoupper($nombrePortal));
        $sheet->setCellValue('A2', $t['client'] . ': ' . $nombreCliente);
        $sheet->setCellValue('A3', $t['template']);
        $sheet->setCellValue('A4', $t['period'] . ': ' . $inicio->format('d/m/Y') . ' ' . $t['to'] . ' ' . $fin->format('d/m/Y'));
        $sheet->setCellValue('A5', $t['generated'] . ': ' . now()->format('d/m/Y H:i'));

        $sheet->mergeCells('A1:J1');
        $sheet->mergeCells('A2:J2');
        $sheet->mergeCells('A3:J3');
        $sheet->mergeCells('A4:J4');

        $sheet->fromArray($headers, null, 'A7');

        $sheet->getStyle('A1:J1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A2:J2')->getFont()->setBold(true)->setSize(13);

        $sheet->getStyle('A7:J7')->getFont()->setBold(true);
        $sheet->getStyle('A7:J7')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFD9EAF7');

        $sheet->getStyle('A7:J7')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

       // $sheet->freezePane('A8');
        $sheet->setAutoFilter('A7:J7');

        $row = 8;

        foreach ($empleados as $idEmpleado) {
            $empleadoInfo = $empleadosInfo->get((int) $idEmpleado);

            $codigoEmpleado = $empleadoInfo->id_empleado ?? null;

            $nombreEmpleado = trim(implode(' ', array_filter([
                $empleadoInfo->nombre ?? null,
                $empleadoInfo->paterno ?? null,
                $empleadoInfo->materno ?? null,
            ])));

            $fecha = $inicio->copy();

            while ($fecha->lte($fin)) {
                $resultado = $horarioResolver->resolver(
                    $idPortal,
                    $idCliente,
                    (int) $idEmpleado,
                    $fecha
                );

                if (! $resultado['ok']) {
                    $fecha->addDay();
                    continue;
                }

                $sheet->fromArray([
                    $codigoEmpleado,
                    $nombreEmpleado,
                    $fecha->toDateString(),
                    $resultado['horario']->hora_entrada,
                    'in',
                    'work',
                    null,
                    (int) $idEmpleado,
                    $idPortal,
                    $idCliente,
                ], null, 'A' . $row);

                $row++;

                $sheet->fromArray([
                    $codigoEmpleado,
                    $nombreEmpleado,
                    $fecha->toDateString(),
                    $resultado['horario']->hora_salida,
                    'out',
                    'work',
                    null,
                    (int) $idEmpleado,
                    $idPortal,
                    $idCliente,
                ], null, 'A' . $row);

                $row++;
                $fecha->addDay();
            }
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $lastRow = $row - 1;

        if ($lastRow >= 8) {
            $sheet->getStyle("A7:J{$lastRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);

            $sheet->getStyle("C8:C{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('yyyy-mm-dd');

            $sheet->getStyle("D8:D{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('hh:mm');

            for ($i = 8; $i <= $lastRow; $i++) {
                $tipoValidation = $sheet->getCell("E{$i}")->getDataValidation();
                $tipoValidation->setType(DataValidation::TYPE_LIST);
                $tipoValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $tipoValidation->setAllowBlank(false);
                $tipoValidation->setShowDropDown(true);
                $tipoValidation->setFormula1('"in,out"');

                $claseValidation = $sheet->getCell("F{$i}")->getDataValidation();
                $claseValidation->setType(DataValidation::TYPE_LIST);
                $claseValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $claseValidation->setAllowBlank(false);
                $claseValidation->setShowDropDown(true);
                $claseValidation->setFormula1('"work,meal,break,personal,transfer,other"');
            }
        }

        $sheet->getColumnDimension('H')->setVisible(false);
        $sheet->getColumnDimension('I')->setVisible(false);
        $sheet->getColumnDimension('J')->setVisible(false);

        $fileName = 'plantilla_checadas_masivas_' . now()->format('Ymd_His') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    public function importarPreview(
        Request $request,
        ChecadorHorarioResolverService $horarioResolver
    ) {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls',
        ]);

        $archivo = $request->file('archivo');

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($archivo->getRealPath());
        $sheet       = $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray(null, true, true, false);

        // Ajusta según tu Excel
        $filaInicioDatos = 8;

        $headers = [
            'codigo_empleado',
            'empleado',
            'fecha',
            'hora',
            'tipo',
            'clase',
            'observacion_admin',
            'id_empleado',
            'id_portal',
            'id_cliente',
        ];

        $tiposValidos  = ['in', 'out'];
        $clasesValidas = ['work', 'meal', 'break', 'personal', 'transfer', 'other'];

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

            // Ignorar filas completamente vacías
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

            $idEmpleado = (int) ($data['id_empleado'] ?? 0);
            $idPortal   = (int) ($data['id_portal'] ?? 0);
            $idCliente  = (int) ($data['id_cliente'] ?? 0);

            $fecha = trim((string) ($data['fecha'] ?? ''));
            $hora  = trim((string) ($data['hora'] ?? ''));
            $tipo  = trim((string) ($data['tipo'] ?? ''));
            $clase = trim((string) ($data['clase'] ?? ''));

            $checkTime = null;

            if ($fecha && $hora) {
                $checkTime = $fecha . ' ' . $hora;
            }

            /*
            |--------------------------------------------------------------------------
            | Validaciones básicas
            |--------------------------------------------------------------------------
            */

            if (! $idEmpleado) {
                $errores[] = 'id_empleado requerido.';
            }

            if (! $idPortal) {
                $errores[] = 'id_portal requerido.';
            }

            if (! $idCliente) {
                $errores[] = 'id_cliente requerido.';
            }

            if (! $fecha) {
                $errores[] = 'fecha requerida.';
            }

            if (! $hora) {
                $errores[] = 'hora requerida.';
            }

            if (! in_array($tipo, $tiposValidos, true)) {
                $errores[] = 'tipo inválido. Valores permitidos: in, out.';
            }

            if (! in_array($clase, $clasesValidas, true)) {
                $errores[] = 'clase inválida.';
            }

            try {
                if ($checkTime) {
                    $checkTimeCarbon = \Carbon\Carbon::parse($checkTime);
                    $fechaCalculada  = $checkTimeCarbon->toDateString();

                    if ($fechaCalculada !== $fecha) {
                        $warnings[] = 'La fecha no coincide exactamente con check_time calculado.';
                    }
                } else {
                    $checkTimeCarbon = null;
                }
            } catch (\Throwable $e) {
                $checkTimeCarbon = null;
                $errores[]       = 'Fecha u hora inválida.';
            }

            /*
            |--------------------------------------------------------------------------
            | Validar empleado
            |--------------------------------------------------------------------------
            */

            $empleado = null;

            if ($idEmpleado && $idPortal && $idCliente) {
                $empleado = DB::connection('portal_main')
                    ->table('empleados')
                    ->where('id', $idEmpleado)
                    ->where('id_portal', $idPortal)
                    ->where('id_cliente', $idCliente)
                    ->first();

                if (! $empleado) {
                    $errores[] = 'El empleado no existe o no pertenece al portal/cliente indicado.';
                }
            }

            $asignacion      = null;
            $horario         = null;
            $detalleHorario  = null;
            $horarioResuelto = null;

            if ($empleado && $fecha) {
                $horarioResuelto = $horarioResolver->resolver(
                    $idPortal,
                    $idCliente,
                    $idEmpleado,
                    Carbon::parse($fecha)
                );

                if (! $horarioResuelto['ok']) {
                    $errores[] = $horarioResuelto['code'] === 'checker_assignment_not_found'
                        ? 'No existe asignación activa para el empleado en esa fecha.'
                        : 'No existe horario laboral válido para esa fecha.';
                } else {
                    $asignacion     = $horarioResuelto['asignacion'];
                    $detalleHorario = $horarioResuelto['horario'];

                    $horario = DB::connection('portal_main')
                        ->table('checador_horario_plantillas')
                        ->where('id', $asignacion->id_plantilla_horario)
                        ->first();
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Validar duplicado exacto
            |--------------------------------------------------------------------------
            */

            $duplicado = null;

            if ($checkTimeCarbon && $idEmpleado && $idPortal && $idCliente && $tipo && $clase) {
                $duplicado = DB::connection('portal_main')
                    ->table('checadas')
                    ->where('id_portal', $idPortal)
                    ->where('id_cliente', $idCliente)
                    ->where('id_empleado', $idEmpleado)
                    ->where('check_time', $checkTimeCarbon->format('Y-m-d H:i:s'))
                    ->where('tipo', $tipo)
                    ->where('clase', $clase)
                    ->first();

                if ($duplicado) {
                    $warnings[] = 'Ya existe una checada idéntica. No se insertaría nuevamente.';
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Acción sugerida
            |--------------------------------------------------------------------------
            */

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
                    'codigo_empleado'      => $data['codigo_empleado'] ?? null,
                    'empleado'             => $data['empleado'] ?? null,
                    'id_empleado'          => $idEmpleado,
                    'id_portal'            => $idPortal,
                    'id_cliente'           => $idCliente,
                    'id_asignacion'        => $asignacion->id ?? null,
                    'id_plantilla_horario' => $asignacion->id_plantilla_horario ?? null,
                    'fecha'                => $fecha,
                    'hora'                 => $hora,
                    'check_time'           => $checkTimeCarbon
                        ? $checkTimeCarbon->format('Y-m-d H:i:s')
                        : $checkTime,
                    'tipo'                 => $tipo,
                    'clase'                => $clase,
                    'observacion_admin'    => $data['observacion_admin'] ?? null,
                    'horario'              => [
                        'nombre'          => $horario->nombre ?? null,
                        'timezone'        => $horario->timezone ?? null,
                        'hora_entrada'    => $detalleHorario->hora_entrada ?? null,
                        'hora_salida'     => $detalleHorario->hora_salida ?? null,
                        'descanso_inicio' => $detalleHorario->descanso_inicio ?? null,
                        'descanso_fin'    => $detalleHorario->descanso_fin ?? null,
                    ],
                    'duplicado'            => $duplicado ? [
                        'id'         => $duplicado->id,
                        'check_time' => $duplicado->check_time,
                        'tipo'       => $duplicado->tipo,
                        'clase'      => $duplicado->clase,
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
            'message'         => 'Preview generado correctamente.',
            'preview_token'   => $previewToken,
            'expires_in_min'  => ChecadorImportConfig::CACHE_MINUTES,
            'resumen'         => $resumen,
            'puede_confirmar' => $resumen['errores'] === 0,
            'preview'         => $preview,
        ]);
    }

    public function importarConfirmar(
        Request $request,
        ChecadaRegistroService $registroService
    ) {
        $request->validate([
            'preview_token' => 'required|string',
        ]);

        $locale = strtolower($request->input('locale', 'es'));

        $previewToken = $request->input('preview_token');
        $cacheKey     = self::CACHE_PREFIX . $previewToken;

        $cache = Cache::get($cacheKey);

        if (! $cache) {
            return response()->json([
                'status'  => false,
                'message' => $locale === 'en'
                    ? 'The preview expired or does not exist. Generate the preview again.'
                    : 'El preview expiró o no existe. Genera el preview nuevamente.',
            ], 422);
        }

        if (! ($cache['puede_confirmar'] ?? false)) {
            return response()->json([
                'status'  => false,
                'message' => $locale === 'en'
                    ? 'The preview contains errors and cannot be confirmed.'
                    : 'El preview contiene errores y no puede confirmarse.',
                'resumen' => $cache['resumen'] ?? null,
            ], 422);
        }

        $insertadas      = 0;
        $omitidas        = 0;
        $errores         = [];
        $detalleOmitidas = [];

        DB::connection('portal_main')->transaction(function () use (
            $cache,
            $registroService,
            $locale, &$insertadas, &$omitidas, &$errores, &$detalleOmitidas) {
            foreach ($cache['preview'] as $item) {
                $dataPreview = $item['data'] ?? [];

                if (($item['accion'] ?? null) !== 'insertar') {
                    $omitidas++;

                    $detalleOmitidas[] = [
                        'fila'     => $item['fila'] ?? null,
                        'empleado' => $dataPreview['empleado'] ?? null,
                        'fecha'    => $dataPreview['fecha'] ?? null,
                        'hora'     => $dataPreview['hora'] ?? null,
                        'tipo'     => $dataPreview['tipo'] ?? null,
                        'clase'    => $dataPreview['clase'] ?? null,
                        'motivo'   => $this->motivoOmitida($item, $locale),
                    ];

                    continue;
                }

                if (! in_array(($item['status'] ?? null), ['ok', 'advertida'], true)) {
                    $omitidas++;

                    $detalleOmitidas[] = [
                        'fila'     => $item['fila'] ?? null,
                        'empleado' => $dataPreview['empleado'] ?? null,
                        'fecha'    => $dataPreview['fecha'] ?? null,
                        'hora'     => $dataPreview['hora'] ?? null,
                        'tipo'     => $dataPreview['tipo'] ?? null,
                        'clase'    => $dataPreview['clase'] ?? null,
                        'motivo'   => $this->motivoOmitida($item, $locale),
                    ];

                    continue;
                }

                $data = [
                    'id_portal'         => $dataPreview['id_portal'],
                    'id_cliente'        => $dataPreview['id_cliente'],
                    'id_empleado'       => $dataPreview['id_empleado'],
                    'check_time'        => $dataPreview['check_time'],
                    'tipo'              => $dataPreview['tipo'],
                    'clase'             => $dataPreview['clase'],
                    'dispositivo'       => 'panel_admin',
                    'origen'            => 'excel',
                    'metodo_validacion' => 'importacion_excel',
                    'timezone'          => $dataPreview['horario']['timezone'] ?? null,
                ];

                $resultadoValidacion = [
                    'id_asignacion'      => $dataPreview['id_asignacion'],
                    'estatus_validacion' => ($item['status'] === 'advertida')
                        ? 'advertida'
                        : 'valida',
                    'motivo'             => 'importacion_excel',
                ];

                $metadata = [
                    'importacion'       => [
                        'version' => 1,
                        'modulo'  => 'comunicacion360',
                        'fila'    => $item['fila'],
                        'fuente'  => 'excel_masivo_checadas',
                    ],
                    'observacion_admin' => $dataPreview['observacion_admin'] ?? null,
                ];

                try {
                    $registroService->insertar($data, $resultadoValidacion, $metadata);
                    $insertadas++;
                } catch (\Throwable $e) {
                    $errores[] = [
                        'fila'     => $item['fila'] ?? null,
                        'empleado' => $dataPreview['empleado'] ?? null,
                        'fecha'    => $dataPreview['fecha'] ?? null,
                        'hora'     => $dataPreview['hora'] ?? null,
                        'error'    => $e->getMessage(),
                    ];
                }
            }
        });

        Cache::forget($cacheKey);

        return response()->json([
            'status'           => count($errores) === 0,
            'message'          => count($errores) === 0
                ? ($locale === 'en'
                    ? 'Import confirmed successfully.'
                    : 'Importación confirmada correctamente.')
                : ($locale === 'en'
                    ? 'Import completed with errors.'
                    : 'Importación finalizada con errores.'),
            'insertadas'       => $insertadas,
            'omitidas'         => $omitidas,
            'errores'          => $errores,
            'detalle_omitidas' => $detalleOmitidas,
        ]);
    }

    private function motivoOmitida(array $item, string $locale = 'es'): string
    {
        $accion   = $item['accion'] ?? null;
        $warnings = $item['warnings'] ?? [];
        $errores  = $item['errores'] ?? [];

        if ($accion === 'omitir_duplicado') {
            return $locale === 'en'
                ? 'An identical attendance record already exists.'
                : 'Ya existe una checada idéntica.';
        }

        if (! empty($errores)) {
            return implode(' ', $errores);
        }

        if (! empty($warnings)) {
            return implode(' ', $warnings);
        }

        return $locale === 'en'
            ? 'The row was skipped because it was not eligible for import.'
            : 'La fila fue omitida porque no era elegible para importación.';
    }
    private function headers(string $locale): array
    {
        if ($locale === 'en') {
            return [
                'Employee Code',
                'Employee',
                'Date',
                'Time',
                'Type',
                'Class',
                'Import Comment',
                'Employee ID',
                'Portal ID',
                'Client ID',
            ];
        }

        return [
            'Código',
            'Empleado',
            'Fecha',
            'Hora',
            'Tipo',
            'Clase',
            'Comentario Importación',
            'ID Empleado',
            'ID Portal',
            'ID Cliente',
        ];
    }

    private function titulos(string $locale): array
    {
        if ($locale === 'en') {
            return [
                'client'    => 'Branch/Project',
                'template'  => 'Attendance Bulk Import Template',
                'period'    => 'Period',
                'generated' => 'Generated',
                'to'        => 'to',
            ];
        }

        return [
            'client'    => 'Sucursal/Proyecto',
            'template'  => 'Plantilla de Importación Masiva de Checadas',
            'period'    => 'Periodo',
            'generated' => 'Generado',
            'to'        => 'al',
        ];
    }
}
