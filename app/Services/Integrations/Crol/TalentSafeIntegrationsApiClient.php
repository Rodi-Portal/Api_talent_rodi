<?php
namespace App\Services\Integrations\Crol;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;

class TalentSafeIntegrationsApiClient
{
    public function health(): Response
    {
        return $this->request(
            method: 'GET',
            path: '/api/internal/health',
        );
    }

    public function crolRegistrationCatalogs(): Response
    {
        return $this->request(
            method: 'GET',
            path: '/api/internal/crol/catalogos/registro',
        );
    }
    public function crolState(
        int $portalId,
    ): Response {
        return $this->request(
            method: 'GET',
            path: '/api/internal/crol/estado',
            query: [
                'portal_id' => $portalId,
            ],
        );
    }

    public function crolSucursales(
        int $portalId,
    ): Response {
        return $this->request(
            method: 'GET',
            path: '/api/internal/crol/sucursales',
            query: [
                'portal_id' => $portalId,
            ],
        );
    }
    /**
     * @throws JsonException
     */
    public function crolCrearSucursal(
        int $portalId,
        array $payload,
    ): Response {
        $payload['portal_id'] = $portalId;

        return $this->request(
            method: 'POST',
            path: '/api/internal/crol/sucursales',
            json: $payload,
        );
    }

    /**
     * @throws JsonException
     */
    public function crolActualizarSucursal(
        int $portalId,
        int $sucursalId,
        array $payload,
    ): Response {
        $payload['portal_id'] = $portalId;

        return $this->request(
            method: 'PUT',
            path: '/api/internal/crol/sucursales/' . $sucursalId,
            json: $payload,
        );
    }
    /**
     * @throws JsonException
     */
    public function crolGruposNomina(
        int $portalId,
    ): Response {
        return $this->request(
            method: 'GET',
            path: '/api/internal/crol/grupos-nomina',
            query: [
                'portal_id' => $portalId,
            ],
        );
    }

    /**
     * @throws JsonException
     */
    public function crolGrupoNominaCatalogos(
        int $portalId,
    ): Response {
        return $this->request(
            method: 'GET',
            path: '/api/internal/crol/grupos-nomina/catalogos',
            query: [
                'portal_id' => $portalId,
            ],
        );
    }

    /**
     * Consulta los centros de costo de nómina CROL.
     *
     * @throws JsonException
     */
    public function crolCentrosCostos(
        int $portalId,
    ): Response {
        return $this->request(
            method: 'GET',
            path: '/api/internal/crol/centros-costos',
            query: [
                'portal_id' => $portalId,
            ],
        );
    }

    /**
     * @throws JsonException
     */
    public function crolCrearGrupoNomina(
        int $portalId,
        int $clientId,
        array $payload,
    ): Response {
        $payload['portal_id'] = $portalId;
        $payload['client_id'] = $clientId;

        return $this->request(
            method: 'POST',
            path: '/api/internal/crol/grupos-nomina',
            json: $payload,
        );
    }

    public function crolObtenerGrupoNomina(
        int $portalId,
        int $clientId,
        int $grupoNominaId,
        int $sucursalNominaId,
    ): Response {
        return $this->request(
            method: 'GET',
            path: '/api/internal/crol/grupos-nomina/' . $grupoNominaId,
            query: [
                'portal_id'   => $portalId,
                'client_id'   => $clientId,
                'sucursal_id' => $sucursalNominaId,
            ],
        );
    }

    public function crolActualizarGrupoNomina(
        int $portalId,
        int $clientId,
        int $grupoNominaId,
        int $sucursalNominaId,
        array $payload,
    ): Response {
        return $this->request(
            method: 'PUT',
            path: '/api/internal/crol/grupos-nomina/' . $grupoNominaId,
            json: array_merge(
                $payload,
                [
                    'portal_id'   => $portalId,
                    'client_id'   => $clientId,
                    'sucursal_id' => $sucursalNominaId,
                ]
            ),
        );
    }

    public function crolOrganization(
        int $portalId,
        array $payload,
    ): Response {
        $payload['portal_id'] = $portalId;

        return $this->request(
            method: 'POST',
            path: '/api/internal/crol/organizacion',
            json: $payload,
        );
    }

    public function crolCertificate(
        int $portalId,
        string $cerBase64,
        string $keyBase64,
        string $keyPassword,
        bool $active = true,
    ): Response {
        return $this->request(
            method: 'POST',
            path: '/api/internal/crol/certificado',
            json: [
                'portal_id'    => $portalId,
                'cer_base64'   => $cerBase64,
                'key_base64'   => $keyBase64,
                'key_password' => $keyPassword,
                'active'       => $active,
            ],
        );
    }

    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
    ): Response {
        $baseUrl = rtrim(
            (string) config(
                'talentsafe_integrations.base_url'
            ),
            '/'
        );

        $client = (string) config(
            'talentsafe_integrations.client'
        );

        $secret = (string) config(
            'talentsafe_integrations.secret'
        );

        if (
            $baseUrl === '' ||
            $client === '' ||
            $secret === ''
        ) {
            throw new InvalidArgumentException(
                'La API de integraciones TalentSafe no está configurada.'
            );
        }

        $method = strtoupper($method);
        $path   = '/' . ltrim($path, '/');

        $timestamp = (string) time();

        $body = $json === null
            ? ''
            : json_encode(
            $json,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );

        $bodyHash = hash(
            'sha256',
            $body
        );

        $canonical = implode("\n", [
            $method,
            $path,
            $timestamp,
            $bodyHash,
        ]);

        $signature = base64_encode(
            hash_hmac(
                'sha256',
                $canonical,
                $secret,
                true
            )
        );

        $http = Http::acceptJson()
            ->connectTimeout(10)
            ->timeout(
                (int) config(
                    'talentsafe_integrations.timeout',
                    30
                )
            )
            ->withHeaders([
                'X-TS-Client'    => $client,
                'X-TS-Timestamp' => $timestamp,
                'X-TS-Signature' => $signature,
            ]);

        if ($body !== '') {
            $http = $http->withBody(
                $body,
                'application/json'
            );
        }

        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        return $http->send(
            $method,
            $baseUrl . $path,
            $options
        );
    }

}
