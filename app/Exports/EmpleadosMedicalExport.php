<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
class EmpleadosMedicalExport implements FromCollection, WithHeadings, WithStyles, WithEvents
{
    protected $empleados;

    public function __construct($empleados)
    {
        $this->empleados = $empleados;
    }

    public function collection()
    {
        // Recorremos cada empleado y reemplazamos valores vacíos por "--"
        return collect($this->empleados)->map(function ($item) {
            foreach ($item as $key => $value) {
                if (is_null($value) || $value === '') {
                    $item->$key = '--';
                }
            }
            return $item;
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'ID Empleado',
            'Nombre Completo',
            'Peso',
            'Edad',
            'Alergias Medicamentos',
            'Alergias Alimentos',
            'Enfermedades Cronicas',
            'Cirugias',
            'Tipo Sangre',
            'Contacto Emergencia',
            'Medicamentos Frecuentes',
            'Lesiones',
            'Otros Padecimientos',
            'Otros Padecimientos 2',
        ];
    }

    // Estilos para encabezados (fila 1)
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [ // Primera fila (encabezados)
                'font' => [
                    'bold'  => true,
                    'color' => ['argb' => 'FFFFFFFF'], // blanco
                    'size'  => 14,
                ],
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF0000FF'], // azul
                ],
            ],
            // Si quieres, puedes agregar estilos para otras filas o columnas aquí
        ];
    }

    // Evento para autoajustar ancho de columnas
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet         = $event->sheet->getDelegate();
                $highestColumn = $sheet->getHighestColumn();
                $highestRow    = $sheet->getHighestRow();

                // 1. Ocultar columna A
                $sheet->getColumnDimension('A')->setVisible(false);

                // 2. Establecer auto ancho para columnas visibles (B en adelante)
                foreach (range('B', $highestColumn) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                // 3. Estilizar encabezados (fila 1)
                $sheet->getStyle("A1:{$highestColumn}1")->applyFromArray([
                    'fill'      => [
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '0070C0'], // Azul
                    ],
                    'font'      => [
                        'color' => ['rgb' => 'FFFFFF'], // Blanco
                        'bold'  => true,
                        'size'  => 12,
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => true,
                    ],
                ]);

                // 4. Reemplazar celdas vacías por "--"
                for ($row = 2; $row <= $highestRow; $row++) {
                    foreach (range('B', $highestColumn) as $col) {
                        $cell = $sheet->getCell("{$col}{$row}");
                        if (trim($cell->getValue()) === '') {
                            $cell->setValue('--');
                        }
                    }
                }

                // 5. Wrap text en columnas N y O (Otros Padecimientos)
                $sheet->getStyle("N2:N{$highestRow}")->getAlignment()->setWrapText(true);
                $sheet->getStyle("O2:O{$highestRow}")->getAlignment()->setWrapText(true);

                // 6. Ajustar altura automática en filas
                for ($row = 2; $row <= $highestRow; $row++) {
                    $sheet->getRowDimension($row)->setRowHeight(-1);
                }
            },
        ];
    }
}
