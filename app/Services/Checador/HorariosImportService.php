<?php
namespace App\Services\Checador;

use App\Models\Comunicacion360\Checador\ChecadorHorarioPlantilla;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class HorariosImportService
{
    public function importar(Request $request): array
    {
        $request->validate([
            'archivo'    => ['required', 'file', 'mimes:xlsx,xls'],
            'id_portal'  => ['required', 'integer'],
            'id_cliente' => ['required', 'integer'],
        ]);

        $spreadsheet = IOFactory::load(
            $request->file('archivo')->getRealPath()
        );

        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray();

        $dataRows = array_slice($rows, 1);

        $mappedRows = [];
        $errors     = [];
        foreach ($dataRows as $index => $row) {
            if ($this->filaVacia($row)) {
                continue;
            }

            $mapped = $this->mapearFila($row, $index + 2);

            $validationErrors = $this->validarFila($mapped);

            if (! empty($validationErrors)) {
                $mapped['accion'] = 'error';

                $errors[] = [
                    'fila'    => $mapped['fila'],
                    'errores' => $validationErrors,
                ];

                $mappedRows[] = $mapped;
                continue;
            }

            $classificationErrors = $this->clasificarFila(
                $mapped,
                (int) $request->input('id_portal'),
                (int) $request->input('id_cliente')
            );

            if (! empty($classificationErrors)) {
                $mapped['accion'] = 'error';

                $errors[] = [
                    'fila'    => $mapped['fila'],
                    'errores' => $classificationErrors,
                ];
            }

            $mappedRows[] = $mapped;
        }
        $resumen = $this->generarResumen($mappedRows);
        return [
            'ok'                => empty($errors),

            'summary'           => $resumen,

            'total_filas_excel' => count($rows),
            'total_registros'   => count($mappedRows),
            'total_errores'     => count($errors),

            'errores'           => $errors,
            'registros'         => $mappedRows,
        ];
    }

    private function mapearFila(array $row, int $numeroFila): array
    {
        return [
            'fila'                   => $numeroFila,

            'codigo'                 => $this->texto($row[0] ?? null),
            'nombre'                 => $this->texto($row[1] ?? null),
            'descripcion'            => $this->texto($row[2] ?? null),
            'timezone_label'         => $this->texto($row[3] ?? null),

            'tolerancia_entrada_min' => $this->entero($row[4] ?? 0),
            'tolerancia_salida_min'  => $this->entero($row[5] ?? 0),

            'permite_descanso'       => $this->booleano($row[6] ?? null),
            'activo'                 => $this->booleano($row[7] ?? null),

            'detalles'               => [
                $this->mapearDia($row, 1, 8),
                $this->mapearDia($row, 2, 11),
                $this->mapearDia($row, 3, 14),
                $this->mapearDia($row, 4, 17),
                $this->mapearDia($row, 5, 20),
                $this->mapearDia($row, 6, 23),
                $this->mapearDia($row, 0, 26),
            ],
        ];
    }

    private function mapearDia(array $row, int $diaSemana, int $startIndex): array
    {
        return [
            'dia_semana'   => $diaSemana,
            'labora'       => $this->booleano($row[$startIndex] ?? null),
            'hora_entrada' => $this->hora($row[$startIndex + 1] ?? null),
            'hora_salida'  => $this->hora($row[$startIndex + 2] ?? null),
        ];
    }

    private function filaVacia(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function texto($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function entero($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }

    private function booleano($value): bool
    {
        $value = mb_strtolower(trim((string) $value));

        return in_array($value, [
            'sí',
            'si',
            'yes',
            'true',
            '1',
        ], true);
    }

    private function hora($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return substr($value, 0, 5);
        }

        return $value;
    }

    private function validarFila(array &$row): array
    {
        $errors = [];

        if (! $row['nombre']) {
            $errors[] = 'El nombre del horario es obligatorio.';
        }

        if ($row['tolerancia_entrada_min'] < 0) {
            $errors[] = 'La tolerancia de entrada no puede ser negativa.';
        }

        if ($row['tolerancia_salida_min'] < 0) {
            $errors[] = 'La tolerancia de salida no puede ser negativa.';
        }

        $timezoneCodigo = $this->buscarTimezoneCodigo($row['timezone_label']);

        if (! $timezoneCodigo) {
            $errors[] = 'La zona horaria no es válida.';
        } else {
            $row['timezone'] = $timezoneCodigo;
        }

        foreach ($row['detalles'] as $detalle) {
            if (! $detalle['labora']) {
                continue;
            }

            if (! $detalle['hora_entrada'] || ! $detalle['hora_salida']) {
                $errors[] = "El día {$detalle['dia_semana']} labora, pero no tiene entrada o salida.";
                continue;
            }

            if (! $this->horaValida($detalle['hora_entrada'])) {
                $errors[] = "El día {$detalle['dia_semana']} tiene hora de entrada inválida.";
            }

            if (! $this->horaValida($detalle['hora_salida'])) {
                $errors[] = "El día {$detalle['dia_semana']} tiene hora de salida inválida.";
            }
        }

        return $errors;
    }

    private function buscarTimezoneCodigo(?string $label): ?string
    {
        if (! $label) {
            return null;
        }

        $label = trim($label);

        $registro = DB::connection('portal_main')
            ->table('checador_timezones')
            ->where('activo', 1)
            ->where(function ($query) use ($label) {
                $query->where('label_es', $label)
                    ->orWhere('label_en', $label)
                    ->orWhere('codigo', $label);
            })
            ->first();

        return $registro->codigo ?? null;
    }

    private function horaValida(?string $hora): bool
    {
        if (! $hora) {
            return false;
        }

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) === 1;
    }

    private function clasificarFila(array &$row, int $idPortal, int $idCliente): array
    {
        $errors = [];

        if (empty($row['codigo'])) {
            $row['accion'] = 'crear';
            return $errors;
        }

        $horario = ChecadorHorarioPlantilla::with('detalles')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('codigo', $row['codigo'])
            ->first();

        if (! $horario) {
            $errors[] = "El código {$row['codigo']} no existe para este cliente.";
            return $errors;
        }

        $row['id_horario'] = $horario->id;

        if ($this->horarioSinCambios($horario, $row)) {
            $row['accion'] = 'sin_cambios';
            return $errors;
        }

        $row['accion'] = 'actualizar';

        return $errors;
    }

    private function horarioSinCambios(ChecadorHorarioPlantilla $horario, array $row): bool
    {
        if ((string) $horario->nombre !== (string) $row['nombre']) {
            return false;
        }

        if ((string) ($horario->descripcion ?? '') !== (string) ($row['descripcion'] ?? '')) {
            return false;
        }

        if ((string) $horario->timezone !== (string) $row['timezone']) {
            return false;
        }

        if ((int) $horario->tolerancia_entrada_min !== (int) $row['tolerancia_entrada_min']) {
            return false;
        }

        if ((int) $horario->tolerancia_salida_min !== (int) $row['tolerancia_salida_min']) {
            return false;
        }

        if ((int) $horario->permite_descanso !== (int) $row['permite_descanso']) {
            return false;
        }

        if ((int) $horario->activo !== (int) $row['activo']) {
            return false;
        }

        return $this->detallesSinCambios($horario, $row['detalles']);
    }
    private function detallesSinCambios(ChecadorHorarioPlantilla $horario, array $detallesExcel): bool
    {
        $detallesActuales = $horario->detalles->keyBy('dia_semana');

        foreach ($detallesExcel as $detalleExcel) {
            $diaSemana     = (int) $detalleExcel['dia_semana'];
            $detalleActual = $detallesActuales->get($diaSemana);

            $actualLabora = $detalleActual ? (int) $detalleActual->labora : 0;
            $excelLabora  = $detalleExcel['labora'] ? 1 : 0;

            if ($actualLabora !== $excelLabora) {
                return false;
            }

            $actualEntrada = $detalleActual && $detalleActual->hora_entrada
                ? substr($detalleActual->hora_entrada, 0, 5)
                : null;

            $actualSalida = $detalleActual && $detalleActual->hora_salida
                ? substr($detalleActual->hora_salida, 0, 5)
                : null;

            $excelEntrada = $detalleExcel['labora']
                ? $detalleExcel['hora_entrada']
                : null;

            $excelSalida = $detalleExcel['labora']
                ? $detalleExcel['hora_salida']
                : null;

            if ($actualEntrada !== $excelEntrada) {
                return false;
            }

            if ($actualSalida !== $excelSalida) {
                return false;
            }
        }

        return true;
    }

    private function generarResumen(array $rows): array
    {
        $summary = [
            'crear'       => 0,
            'actualizar'  => 0,
            'sin_cambios' => 0,
            'errores'     => 0,
        ];

        foreach ($rows as $row) {

            $accion = $row['accion'] ?? 'errores';

            switch ($accion) {

                case 'crear':
                    $summary['crear']++;
                    break;

                case 'actualizar':
                    $summary['actualizar']++;
                    break;

                case 'sin_cambios':
                    $summary['sin_cambios']++;
                    break;

                default:
                    $summary['errores']++;
                    break;
            }
        }

        $summary['total'] = count($rows);

        return $summary;
    }
}
