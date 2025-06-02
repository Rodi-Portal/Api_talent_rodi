<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EmpleadosGeneralExport implements FromCollection, WithHeadings, WithStyles, WithEvents
{
    protected $empleados;
    protected $camposExtraNombres = [];

    public function __construct($empleados)
    {
        $this->empleados = $empleados;

        // Obtener todos los nombres únicos de campos extra (para las columnas dinámicas)
        foreach ($empleados as $empleado) {
            if (isset($empleado->camposExtra)) {
                foreach ($empleado->camposExtra as $campo) {
                    if (! in_array($campo->nombre, $this->camposExtraNombres)) {
                        $this->camposExtraNombres[] = $campo->nombre;
                    }
                }
            }
        }
    }

    public function collection()
    {
        $result = [];

        foreach ($this->empleados as $empleado) {
            // Datos fijos del empleado
            $row = [
                'ID'               => $empleado->id,
                'ID Empleado'      => $empleado->id_empleado,
                'Nombre'           => $empleado->nombre,
                'Paterno'          => $empleado->paterno,
                'Materno'          => $empleado->materno,
                'Teléfono'         => $empleado->telefono,
                'Correo'           => $empleado->correo,
                'RFC'              => $empleado->rfc,
                'CURP'             => $empleado->curp,
                'NSS'              => $empleado->nss,
                'Departamento'     => $empleado->departamento,
                'Puesto'           => $empleado->puesto,
                'Fecha Nacimiento' => $empleado->fecha_nacimiento,
                // Domicilio
                'Pais'             => $empleado->domicilioEmpleado->pais ?? '',
                'Estado'           => $empleado->domicilioEmpleado->estado ?? '',
                'Ciudad'           => $empleado->domicilioEmpleado->ciudad ?? '',
                'Colonia'          => $empleado->domicilioEmpleado->colonia ?? '',
                'Calle'            => $empleado->domicilioEmpleado->calle ?? '',
                'Num Int'          => $empleado->domicilioEmpleado->num_int ?? '',
                'Num Ext'          => $empleado->domicilioEmpleado->num_ext ?? '',
                'CP'               => $empleado->domicilioEmpleado->cp ?? '',
            ];

            // Campos extra dinámicos: rellenar las columnas por nombre con su valor o vacío
            foreach ($this->camposExtraNombres as $nombreCampo) {
                $row[$nombreCampo] = ''; // valor default vacío

                foreach ($empleado->camposExtra as $campoExtra) {
                    if ($campoExtra->nombre === $nombreCampo) {
                        $row[$nombreCampo] = $campoExtra->valor;
                        break; // si hay más de uno con el mismo nombre, toma el primero
                    }
                }
            }

            $result[] = (object) $row;
        }

        return collect($result);
    }

    public function headings(): array
    {
        // Encabezados fijos
        $headings = [
            'ID',
            'ID Empleado',
            'Nombre',
            'Paterno',
            'Materno',
            'Teléfono',
            'Correo',
            'RFC',
            'CURP',
            'NSS',
            'Departamento',
            'Puesto',
            'Fecha Nacimiento',
            'Pais',
            'Estado',
            'Ciudad',
            'Colonia',
            'Calle',
            'Num Int',
            'Num Ext',
            'CP',
        ];

        // Agregar encabezados de campos extra dinámicos
        return array_merge($headings, $this->camposExtraNombres);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [ // Estilo para fila 1 (encabezados)
                'font'      => [
                    'bold'  => true,
                    'color' => ['argb' => 'FFFFFFFF'], // blanco
                    'size'  => 12,
                ],
                'fill'      => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF0070C0'], // azul
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                    'wrapText'   => true,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet         = $event->sheet->getDelegate();
                $highestColumn = $sheet->getHighestColumn();
                $highestRow    = $sheet->getHighestRow();
                $sheet->freezePane('D2');

                $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

                // Auto ajustar ancho de columnas para todas las columnas usadas
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
                }
                // 🔒 Forzar "Fecha Nacimiento" como texto
                $headingRow = $event->sheet->getDelegate()->rangeToArray('A1:' . $highestColumn . '1')[0];
                foreach ($headingRow as $colIndex => $colName) {
                    if (trim(strtolower($colName)) === 'fecha nacimiento') {
                        $columnLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                        $range        = $columnLetter . '2:' . $columnLetter . $highestRow;
                        $sheet->getStyle($range)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
                        break;
                    }
                }
                $sheet->getColumnDimension('A')->setVisible(false);

                // Opcional: poner '--' en celdas vacías
                for ($row = 2; $row <= $highestRow; $row++) {
                    for ($col = 1; $col <= $highestColumnIndex; $col++) {
                        $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
                        if (trim($cell->getValue()) === '') {
                            $cell->setValue('--');
                        }
                    }
                }
            },
        ];
    }
}
