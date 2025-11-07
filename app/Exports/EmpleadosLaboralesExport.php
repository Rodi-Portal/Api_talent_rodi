<?php
namespace App\Exports;

use App\Services\SatCatalogosService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EmpleadosLaboralesExport implements FromCollection, WithHeadings, WithStyles, WithEvents
{
    protected $empleados;
    /** @var SatCatalogosService */
    protected $sat;

    public function __construct($empleados)
    {
        $this->empleados = $empleados;
        // ⚠️ Resolver servicio del contenedor; no necesitas cambiar tu controlador
        $this->sat = app(SatCatalogosService::class);
    }

    public function collection()
    {
        // Catálogos SAT (clave => descripción)
        $contratos      = $this->sat->contratos();
        $regimenes      = $this->sat->regimenes();
        $jornadas       = $this->sat->jornadas();
        $periodicidades = $this->sat->periodicidades();

        // helper para mostrar '--' si viene vacío
        $val = function ($v) {return ($v === null || $v === '') ? '--' : $v;};

        return collect($this->empleados)->map(function ($item) use ($val, $contratos, $regimenes, $jornadas, $periodicidades) {
            // Descripciones SAT si hay clave; si no, legacy; si no, '--'
            $descContrato = $contratos[$item->tipo_contrato_sat ?? ''] ?? $item->tipo_contrato ?? '--';
            $descRegimen  = $regimenes[$item->tipo_regimen_sat ?? ''] ?? $item->tipo_regimen ?? '--';
            $descJornada  = $jornadas[$item->tipo_jornada_sat ?? ''] ?? $item->tipo_jornada ?? '--';
            $descPerio    = $periodicidades[$item->periodicidad_pago_sat ?? ''] ?? $item->periodicidad_pago ?? '--';

            // Días de descanso a columnas Sí/No
            $dias = is_array($tmp = json_decode($item->dias_descanso ?? '[]', true)) ? $tmp : [];
            $siNo = fn($d) => in_array($d, $dias) ? 'Sí' : 'No';

            // === DEVUELVE UN ARREGLO ORDENADO (match con headings) ===
            return [
                // A: ID (la tengo oculta en AfterSheet; quita la línea si quieres verla)
                $val($item->id ?? null),

                // B–AD: en el mismo orden de headings()
                $val($item->id_empleado ?? null),
                $val($item->nombre_completo ?? null),
                $val($descContrato),
                $val($item->otro_tipo_contrato ?? null),
                $val($descRegimen),
                $val($descJornada),
                $val($item->horas_dia ?? null),
                $val($item->grupo_nomina ?? null),
                $val($descPerio),
                $val($item->sindicato ?? null),
                $val($item->vacaciones_disponibles ?? null),
                $val($item->sueldo_diario ?? null),
                $val($item->sueldo_asimilado ?? null),
                $val($item->pago_dia_festivo ?? null),
                $val($item->pago_dia_festivo_a ?? null),
                $val($item->pago_hora_extra ?? null),
                $val($item->pago_hora_extra_a ?? null),
                $val($item->dias_aguinaldo ?? null),
                $val($item->prima_vacacional ?? null),
                $val($item->prestamo_pendiente ?? null),
                $val($item->descuento_ausencia ?? null),
                $val($item->descuento_ausencia_a ?? null),

                // Días de descanso (X–AD)
                $siNo('Lunes'),
                $siNo('Martes'),
                $siNo('Miércoles'),
                $siNo('Jueves'),
                $siNo('Viernes'),
                $siNo('Sábado'),
                $siNo('Domingo'),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'ID', 'ID Empleado', 'Nombre Completo', 'Tipo Contrato', 'Otro Tipo Contrato',
            'Tipo Régimen', 'Tipo Jornada', 'Horas Día', 'Grupo Nómina', 'Periodicidad Pago',
            'Pertenece Sindicato', 'Vacaciones Disponibles', 'Sueldo Diario', 'Sueldo Diario Asimilado',
            'Pago Día Festivo', 'Pago Día Festivo Asimilado', 'Pago Hora Extra', 'Pago Hora Extra Asimilado',
            'Días Aguinaldo', 'Prima Vacacional', 'Prestamo pendiente', 'Descuento Ausencia',
            'Descuento Ausencia Asimilado', 'Descanso - Lunes', 'Descanso - Martes', 'Descanso - Miércoles',
            'Descanso - Jueves', 'Descanso - Viernes', 'Descanso - Sábado', 'Descanso - Domingo',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 14],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0000FF']],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                // Carga catálogos dentro del closure (aquí sí existe $this)
                $contratos      = $this->sat->contratos();
                $regimenes      = $this->sat->regimenes();
                $jornadas       = $this->sat->jornadas();
                $periodicidades = $this->sat->periodicidades();

                $sheet         = $event->sheet->getDelegate();
                $spreadsheet   = $sheet->getParent();
                $highestColumn = $sheet->getHighestColumn();
                $highestRow    = $sheet->getHighestRow();
                $sheet->freezePane('D2');

                // Crea hoja oculta de listas
                if ($spreadsheet->sheetNameExists('listas')) {
                    $spreadsheet->removeSheetByIndex(
                        $spreadsheet->getIndex($spreadsheet->getSheetByName('listas'))
                    );
                }
                $listSheet = $spreadsheet->createSheet();
                $listSheet->setTitle('listas');
                $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

                $dropdowns = [
                    'tipo_contrato'     => array_values($contratos),
                    'tipo_regimen'      => array_values($regimenes),
                    'tipo_jornada'      => array_values($jornadas),
                    'periodicidad_pago' => array_values($periodicidades),
                    'sindicato'         => ['SI', 'NO'],
                ];

                // Escribir listas y nombrarlas
                $colIndex = 0;
                foreach ($dropdowns as $name => $values) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    foreach ($values as $i => $value) {
                        $listSheet->setCellValue("{$colLetter}" . ($i + 1), $value);
                    }
                    $spreadsheet->addNamedRange(new NamedRange(
                        $name,
                        $listSheet,
                        "\${$colLetter}\$1:\${$colLetter}\$" . count($values)
                    ));
                    $colIndex++;
                }

                // Validación para días de descanso
                $columnasDias = [
                    'Lunes'   => 'X', 'Martes'  => 'Y', 'Miércoles' => 'Z', 'Jueves' => 'AA',
                    'Viernes' => 'AB', 'Sábado' => 'AC', 'Domingo'  => 'AD',
                ];
                foreach ($columnasDias as $col) {
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $this->aplicarValidacionLista($sheet, $col, $row, '"Sí,No"');
                    }
                }

                // Validación para columnas mapeadas
                $columnMap = [
                    'D' => 'tipo_contrato',
                    'F' => 'tipo_regimen',
                    'G' => 'tipo_jornada',
                    'J' => 'periodicidad_pago',
                    'K' => 'sindicato',
                ];
                foreach ($columnMap as $col => $rangeName) {
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $this->aplicarValidacionLista($sheet, $col, $row, "={$rangeName}");
                    }
                }

                // Estilo encabezado
                $sheet->getStyle("A1:{$highestColumn}1")->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0070C0']],
                    'font'      => ['color' => ['rgb' => 'FFFFFF'], 'bold' => true, 'size' => 12],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => false,
                    ],
                ]);

                // Anchos
                foreach ($this->headings() as $i => $heading) {
                    $colLetter = Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->getColumnDimension($colLetter)->setWidth(strlen($heading) + 5);
                }
                $sheet->getColumnDimension('C')->setWidth(40);
                $sheet->getColumnDimension('A')->setVisible(false);

                // Altura automática
                for ($row = 2; $row <= $highestRow; $row++) {
                    $sheet->getRowDimension($row)->setRowHeight(-1);
                }
            },
        ];
    }

    private function aplicarValidacionLista($sheet, $col, $row, $formula)
    {
        $validation = $sheet->getCell("{$col}{$row}")->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowInputMessage(true)
            ->setShowErrorMessage(true)
            ->setShowDropDown(true)
            ->setFormula1($formula);
    }

    private function limpiarAcentos($cadena)
    {
        $originales  = ['Á', 'É', 'Í', 'Ó', 'Ú', 'á', 'é', 'í', 'ó', 'ú', 'Ñ', 'ñ'];
        $modificadas = ['A', 'E', 'I', 'O', 'U', 'a', 'e', 'i', 'o', 'u', 'N', 'n'];
        return str_replace($originales, $modificadas, $cadena);
    }
}
