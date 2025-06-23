<?php
namespace App\Imports;

use App\Models\Empleado;
use App\Models\LaboralesEmpleado;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class EmpleadosLaboralesImport implements OnEachRow, WithHeadingRow
{
    protected $idCliente;

    public function __construct($idCliente)
    {
        $this->idCliente = $idCliente;
    }
    protected $mapTipoContrato = [
        'Indefinido'                    => 'Indefinido',
        '1 mes de prueba'               => '1 mes de prueba',
        '3 meses de prueba'             => '3 meses de prueba',
        'Contrato por obra determinada' => 'Contrato por obra determinada',
        'Contrato por temporada'        => 'Contrato por temporada',
        'Contrato a tiempo parcial'     => 'Contrato a tiempo parcial',
        'Contrato a tiempo completo'    => 'Contrato a tiempo completo',
        'Por honorarios'                => 'Por honorarios',
        'Contratación directa'          => 'Contratación directa',
        'Contrato de prácticas'         => 'Contrato de prácticas',
        'Contrato de aprendizaje'       => 'Contrato de aprendizaje',
        'Contrato de interinidad'       => 'Contrato de interinidad',
        'Contrato temporal'             => 'Contrato temporal',
        'Contrato eventual'             => 'Contrato eventual',
        'Otro'                          => 'Otro',
    ];

    protected $mapTipoRegimen = [
        '0'  => 'Ninguno',
        '1'  => 'Asimilados Acciones',
        '2'  => 'Asimilados Comisionistas',
        '3'  => 'Asimilados Honorarios',
        '4'  => 'Integrantes Soc. Civiles',
        '5'  => 'Miembros Consejos',
        '6'  => 'Miembros Coop. Producción',
        '7'  => 'Otros Asimilados',
        '8'  => 'Indemnización o Separación',
        '9'  => 'Jubilados',
        '10' => 'Jubilados o Pensionados',
        '11' => 'Otro Régimen',
        '12' => 'Pensionados',
        '13' => 'Sueldos y Salarios',
    ];

    protected $mapTipoJornada = [
        'ninguno'  => 'ninguno',
        'diurna'   => 'diurna',
        'mixta'    => 'mixta',
        'nocturna' => 'nocturna',
        'otra'     => 'otra',
    ];

    protected $periodicidades = [
        '01' => 'Diario',
        '02' => 'Semanal',
        '03' => 'Quincenal',
        '04' => 'Mensual',
        '05' => 'Bimestral',
        '06' => 'Unidad obra',
        '07' => 'Comisión',
        '08' => 'Precio alzado',
        '09' => 'Otra Periodicidad',
    ];



    public function onRow(Row $row)
    {

        static $validatedHeaders = false;

        $rowArray = $row->toArray();

        // Validación de cabeceras (solo en la primera fila)
        if (! $validatedHeaders) {
            $requiredHeaders = [
                'id',
                'tipo_contrato',
                'tipo_regimen',
                'tipo_jornada',
                'periodicidad_pago',
                // Agrega los campos obligatorios que necesites validar
            ];

            $headersFromFile = array_map(
                fn($val) => mb_strtolower(trim($val)),
                array_keys($rowArray)
            );

            $missingHeaders = array_filter($requiredHeaders, fn($header) => ! in_array($header, $headersFromFile));

            if (! empty($missingHeaders)) {
                $missing = implode(', ', $missingHeaders);
                throw new \Exception(
                    "El archivo seleccionado no es válido para  actualizar Informacion Laboral. Faltan campos clave. \n " .
                    "Por favor, tenga cuidado y asegúrese de cargar el archivo correcto con el formato esperado."
                );
            }

            $validatedHeaders = true;
        }

        $row   = $row->toArray();
        $clean = fn($val) => (trim((string) $val) === '' || trim((string) $val) === '--') ? null : trim((string) $val);

        $empleadoId = $clean($row['id'] ?? null);
        if (! $empleadoId) {
            return;
        }

        $empleado = Empleado::find($empleadoId);

        if (! $empleado || $empleado->id_cliente != $this->idCliente) {
            throw new \Exception(
                "No fue posible actualizar los datos. El archivo contiene información de empleados que no pertenecen a esta sucursal. " .
                "Asegúrate de usar el archivo correcto que corresponde al sucursal donde  intentas  actualizar la informacion."
            );
        }

        // Limpieza de valores
        $tipoContratoRaw     = $clean($row['tipo_contrato'] ?? null);
        $tipoRegimenRaw      = $clean($row['tipo_regimen'] ?? null);
        $tipoJornadaRaw      = strtolower($clean($row['tipo_jornada'] ?? ''));
        $periodicidadPagoRaw = $clean($row['periodicidad_pago'] ?? null);
        // Mapeo: obtener claves
        $tipoContrato     = $this->mapTipoContrato[$tipoContratoRaw] ?? null;
        $tipoRegimen      = array_search($tipoRegimenRaw, $this->mapTipoRegimen, true) ?? null;
        $tipoJornada      = array_search($tipoJornadaRaw, $this->mapTipoJornada, true) ?? null;
        $periodicidadPago = array_search($periodicidadPagoRaw, $this->periodicidades, true) ?? null;

        // Este log muestra todas las claves de la fila importada
        $diasDescanso = [];

        foreach ([
            'lunes'     => 'Lunes',
            'martes'    => 'Martes',
            'miercoles' => 'Miércoles',
            'jueves'    => 'Jueves',
            'viernes'   => 'Viernes',
            'sabado'    => 'Sábado',
            'domingo'   => 'Domingo',
        ] as $campo => $nombreDia) {
            $valor = strtolower($clean($row['descanso_' . $campo] ?? 'no'));

            if ($valor === 'sí' || $valor === 'si') {
                $diasDescanso[] = $nombreDia;
            }
        }

        LaboralesEmpleado::updateOrCreate(
            ['id_empleado' => $empleadoId],
            [
                'tipo_contrato'          => $tipoContrato,
                'tipo_regimen'           => $tipoRegimen,
                'tipo_jornada'           => $tipoJornada,
                'periodicidad_pago'      => $periodicidadPago,
                'otro_tipo_contrato'     => $clean($row['otro_tipo_contrato'] ?? null),
                'horas_dia'              => $clean($row['horas_dia'] ?? null),
                'grupo_nomina'           => $clean($row['grupo_nomina'] ?? null),
                'vacaciones_disponibles' => $clean($row['vacaciones_disponibles'] ?? null),
                'sueldo_diario'          => $clean($row['sueldo_diario'] ?? null),
                'pago_dia_festivo'       => $clean($row['pago_dia_festivo'] ?? null),
                'pago_hora_extra'        => $clean($row['pago_hora_extra'] ?? null),
                'dias_aguinaldo'         => $clean($row['dias_aguinaldo'] ?? null),
                'prima_vacacional'       => $clean($row['prima_vacacional'] ?? null),
                'prestamo_pendiente'     => $clean($row['prestamo_pendiente'] ?? null),
                'descuento_ausencia'     => $clean($row['descuento_ausencia'] ?? null),
                'dias_descanso'          => json_encode($diasDescanso),
                // Días de descanso
            ]
        );
    }
}
