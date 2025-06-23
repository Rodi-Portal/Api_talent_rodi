<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class EmpleadosLaboralesExport implements FromCollection, WithHeadings, WithStyles, WithEvents
{
    protected $empleados;

    public function __construct($empleados)
    {
        $this->empleados = $empleados;
    }

    public function collection()
    {
        $mapTipoContrato = [
            'Indefinido' => 'Indefinido',
            '1 mes de prueba' => '1 mes de prueba',
            '3 meses de prueba' => '3 meses de prueba',
            'Contrato por obra determinada' => 'Contrato por obra determinada',
            'Contrato por temporada' => 'Contrato por temporada',
            'Contrato a tiempo parcial' => 'Contrato a tiempo parcial',
            'Contrato a tiempo completo' => 'Contrato a tiempo completo',
            'Por honorarios' => 'Por honorarios',
            'Contratación directa' => 'Contratación directa',
            'Contrato de prácticas' => 'Contrato de prácticas',
            'Contrato de aprendizaje' => 'Contrato de aprendizaje',
            'Contrato de interinidad' => 'Contrato de interinidad',
            'Contrato temporal' => 'Contrato temporal',
            'Contrato eventual' => 'Contrato eventual',
            'Otro' => 'Otro',
        ];

        $mapTipoRegimen = [
            '0' => 'Ninguno',
            '1' => 'Asimilados Acciones',
            '2' => 'Asimilados Comisionistas',
            '3' => 'Asimilados Honorarios',
            '4' => 'Integrantes Soc. Civiles',
            '5' => 'Miembros Consejos',
            '6' => 'Miembros Coop. Producción',
            '7' => 'Otros Asimilados',
            '8' => 'Indemnización o Separación',
            '9' => 'Jubilados',
            '10' => 'Jubilados o Pensionados',
            '11' => 'Otro Régimen',
            '12' => 'Pensionados',
            '13' => 'Sueldos y Salarios',
        ];

        $mapTipoJornada = [
            'ninguno' => 'Ninguna',
            'diurna' => 'Diurna',
            'mixta' => 'Mixta',
            'nocturna' => 'Nocturna',
            'otra' => 'Otra',
        ];

        $periodicidades = [
            '01' => 'Diurna',
            '02' => 'Semanal',
            '03' => 'Quincenal',
            '04' => 'Mensual',
            '05' => 'Bimestral',
            '06' => 'Unidad obra',
            '07' => 'Comisión',
            '08' => 'Precio alzado',
            '09' => 'Otra Periodicidad',
        ];

       

        return collect($this->empleados)->map(function ($item) use ($mapTipoContrato, $mapTipoRegimen, $mapTipoJornada, $periodicidades) {
            foreach ($item as $key => $value) {
                if (is_null($value) || $value === '') {
                    $item->$key = '--';
                }
            }

            $item->tipo_contrato = $mapTipoContrato[$item->tipo_contrato ?? ''] ?? $item->tipo_contrato;
            $item->tipo_regimen = $mapTipoRegimen[$item->tipo_regimen ?? ''] ?? $item->tipo_regimen;
            $item->tipo_jornada = $mapTipoJornada[$item->tipo_jornada ?? ''] ?? $item->tipo_jornada;
            $item->periodicidad_pago = $periodicidades[$item->periodicidad_pago ?? ''] ?? $item->periodicidad_pago;

            // Días de descanso a columnas
            $dias = is_array($temp = json_decode($item->dias_descanso, true)) ? $temp : [];
            $todos_los_dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
            foreach ($todos_los_dias as $dia) {
                $col = 'dia_descanso_' . strtolower($this->limpiarAcentos($dia));
                $item->$col = in_array($dia, $dias) ? 'Sí' : 'No';
            }
            unset($item->dias_descanso);

            return $item;
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'ID Empleado',
            'Nombre Completo',
            'Tipo Contrato',
            'Otro Tipo Contrato',
            'Tipo Régimen',
            'Tipo Jornada',
            'Horas Día',
            'Grupo Nómina',
            'Periodicidad Pago',
            'Vacaciones Disponibles',
            'Sueldo Diario',
            'Pago Día Festivo',
            'Pago Hora Extra',
            'Días Aguinaldo',
            'Prima Vacacional',
            'Prestamo pendiente',
            'Descuento Ausencia',
            'Descanso - Lunes',
            'Descanso - Martes',
            'Descanso - Miércoles',
            'Descanso - Jueves',
            'Descanso - Viernes',
            'Descanso - Sábado',
            'Descanso - Domingo',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['argb' => 'FFFFFFFF'],
                    'size' => 14,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF0000FF'],
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $spreadsheet = $sheet->getParent();
                $highestColumn = $sheet->getHighestColumn();
                $highestRow = $sheet->getHighestRow();
                $sheet->freezePane('D2');

                // Crear hoja oculta de listas
                if ($spreadsheet->sheetNameExists('listas')) {
                    $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($spreadsheet->getSheetByName('listas')));
                }
                $listSheet = $spreadsheet->createSheet();
                $listSheet->setTitle('listas');
                $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

                $dropdowns = [
                    'tipo_contrato' => [
                        'Indefinido', '1 mes de prueba', '3 meses de prueba',
                        'Contrato por obra determinada', 'Contrato por temporada',
                        'Contrato a tiempo parcial', 'Contrato a tiempo completo',
                        'Por honorarios', 'Contratación directa',
                        'Contrato de prácticas', 'Contrato de aprendizaje',
                        'Contrato indefinido', 'Contrato de interinidad',
                        'Contrato temporal', 'Contrato eventual', 'Otro',
                    ],
                    'tipo_regimen' => [
                        'Ninguno', 'Asimilados Acciones', 'Asimilados Comisionistas',
                        'Asimilados Honorarios', 'Integrantes Soc. Civiles',
                        'Miembros Consejos', 'Miembros Coop. Producción',
                        'Otros Asimilados', 'Indemnización o Separación',
                        'Jubilados', 'Jubilados o Pensionados', 'Otro Régimen',
                        'Pensionados', 'Sueldos y Salarios',
                    ],
                    'tipo_jornada' => ['Ninguna', 'Diurna', 'Mixta', 'Nocturna', 'Otra'],
                    'periodicidad_pago' => [
                        'Diurna', 'Semanal', 'Quincenal', 'Mensual', 'Bimestral',
                        'Unidad obra', 'Comisión', 'Precio alzado', 'Otra Periodicidad',
                    ],
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
                    'Lunes' => 'U', 'Martes' => 'V', 'Miércoles' => 'W',
                    'Jueves' => 'X', 'Viernes' => 'Y', 'Sábado' => 'Z', 'Domingo' => 'AA',
                ];
                foreach ($columnasDias as $dia => $col) {
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
                ];
                foreach ($columnMap as $col => $rangeName) {
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $this->aplicarValidacionLista($sheet, $col, $row, "={$rangeName}");
                    }
                }

                // Estilo encabezado
                $sheet->getStyle("A1:{$highestColumn}1")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '0070C0'],
                    ],
                    'font' => [
                        'color' => ['rgb' => 'FFFFFF'],
                        'bold' => true,
                        'size' => 12,
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => false,
                    ],
                ]);

                // Ajuste de ancho dinámico
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
        $originales = ['Á', 'É', 'Í', 'Ó', 'Ú', 'á', 'é', 'í', 'ó', 'ú', 'Ñ', 'ñ'];
        $modificadas = ['A', 'E', 'I', 'O', 'U', 'a', 'e', 'i', 'o', 'u', 'N', 'n'];
        return str_replace($originales, $modificadas, $cadena);
    }
}
