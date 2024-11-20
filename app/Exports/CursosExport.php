<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CursosExport implements FromCollection, WithHeadings, WithStyles
{
    private $data;
    private $empleados;
    private $cursos;
    private $clienteNombre;

    public function __construct($data, $clienteNombre)
    {
        $this->data = $data;
        $this->empleados = collect($data)->pluck('empleado')->unique();
        $this->cursos = collect($data)->pluck('curso')->unique();
        $this->clienteNombre = $clienteNombre;
    }

    public function collection()
    {
        $rows = [];

        // Agregar el encabezado con el nombre del cliente
        $rows[] = array_merge(['Empleado'], $this->cursos->toArray());

        // Agregar los datos de empleados y sus cursos
        foreach ($this->empleados as $empleado) {
            $row = [$empleado];
            foreach ($this->cursos as $curso) {
                $entry = collect($this->data)->first(function ($d) use ($empleado, $curso) {
                    return $d['empleado'] === $empleado && $d['curso'] === $curso;
                });
                $row[] = $entry['fecha_expiracion'] ?? '';
            }
            $rows[] = $row;
        }

        return collect($rows);
    }

    public function headings(): array
    {
        return ['Reporte Cursos: ' . $this->clienteNombre];
    }

    public function styles(Worksheet $sheet)
    {
        // Ajustar el ancho de las columnas
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->mergeCells('A1:' . $sheet->getHighestColumn() . '1');
        // Estilos del encabezado con cursos
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 18,
                'color' => ['argb' => 'FFFFFF'],
            ],
            
            'fill' => [
                'fillType' => 'solid',
                'color' => ['argb' => '4F81BD'], // Azul suave
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['argb' => '000000'],
                ],
            ],
        ]);

        // Estilo de la fila con el título
        $highestColumn = $sheet->getHighestColumn(); // Obtener la última columna con contenido
        $row = 2; // Número de fila a procesar
        
        foreach (range('A', $highestColumn) as $column) {
            $cellValue = $sheet->getCell($column . $row)->getValue(); // Obtener el valor de la celda
            if (!empty($cellValue)) { // Verificar si la celda no está vacía
                $sheet->getStyle($column . $row)->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 12,
                        'color' => ['argb' => 'FFFFFF'],
                    ],
                    'fill' => [
                        'fillType' => 'solid',
                        'color' => ['argb' => '32CD32'], // Color azul claro
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['argb' => '000000'],
                        ],
                    ],
                ]);
            }
        }
        
        
        $sheet->getStyle('A:A')->applyFromArray([
            'font' => [
                'bold' => true,  // Hace el texto en negrita
            ],
        ]);

        // Estilo dinámico para las celdas con los estados
        $rowCount = $sheet->getHighestRow();
        for ($row = 3; $row <= $rowCount; $row++) {
            for ($col = 2; $col <= count($this->cursos) + 1; $col++) {
                $cell = $sheet->getCellByColumnAndRow($col, $row);
                $value = $cell->getValue();

                if ($value) {
                    $estado = $this->determineEstado($value);
                    $color = $this->getEstadoColor($estado);

                    $sheet->getStyle($cell->getCoordinate())->applyFromArray([
                        'fill' => [
                            'fillType' => 'solid',
                            'color' => ['argb' => $color],
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                'color' => ['argb' => 'D3D3D3'],
                            ],
                        ],
                    ]);
                }
            }
        }

        return [];
    }

    private function determineEstado($fecha)
    {
        $hoy = \Carbon\Carbon::now();
        $fechaExpiracion = \Carbon\Carbon::parse($fecha);

        if ($fechaExpiracion->isPast()) {
            return 'Expirado';
        } elseif ($fechaExpiracion->diffInDays($hoy) <= 5) {
            return 'Por expirar';
        } else {
            return 'Vigente';
        }
    }

    private function getEstadoColor($estado)
    {
        switch ($estado) {
            case 'Expirado':
                return 'FF6F61'; // Rojo suave
            case 'Por expirar':
                return 'FFD700'; // Amarillo
            case 'Vigente':
                return 'D0F5A9'; // Verde claro
            default:
                return 'FFFFFF'; // Blanco
        }
    }
}
