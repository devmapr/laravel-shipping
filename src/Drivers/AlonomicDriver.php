<?php

declare(strict_types=1);

namespace Planx\Shipping\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Planx\Shipping\Contracts\ShippingDriver;
use Throwable;

class AlonomicDriver implements ShippingDriver
{
    /** @var array<string,mixed> */
    private array $config;

    private Client $guzzle;

    private string $baseUrl = '';

    private ?string $token = null;

    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->guzzle = new Client();
        $this->baseUrl = mb_rtrim((string) ($config['base_url'] ?? ''), '/');

        $email = (string) ($config['email'] ?? '');
        $password = (string) ($config['password'] ?? '');
        if ($email && $password) {
            $this->login($email, $password);
        }
    }

    public function createParcel(array $payload): array
    {

        try {
            $response = $this->guzzle->post($this->baseUrl . '/api/v1/parcels', [
                'headers' => $this->authHeaders(),
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : ['error' => 'Invalid response format'];
        } catch (GuzzleException $e) {

            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function updateParcel(int|string $id, array $payload): array
    {
        try {
            $response = $this->guzzle->put($this->baseUrl . "/api/v1/parcels/{$id}", [
                'headers' => $this->authHeaders(),
                'json' => $payload,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : ['error' => 'Invalid response format'];
        } catch (GuzzleException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getParcel(int|string $id): array
    {
        try {
            $response = $this->guzzle->get($this->baseUrl . "/api/v1/parcels/{$id}", [
                'headers' => $this->authHeaders(),
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : ['error' => 'Invalid response format'];
        } catch (GuzzleException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function deleteParcel(int|string $id): array
    {
        try {
            $response = $this->guzzle->delete($this->baseUrl . "/api/v1/parcels/{$id}", [
                'headers' => $this->authHeaders(),
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : ['error' => 'Invalid response format'];
        } catch (GuzzleException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getDays(): array
    {
        try {
            $response = $this->guzzle->get($this->baseUrl . '/api/v1/days', [
                'headers' => $this->authHeaders(),
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : ['error' => 'Invalid response format'];
        } catch (GuzzleException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getDaysIndex(): array
    {
        try {
            $response = $this->guzzle->get($this->baseUrl . '/api/v1/days/index', [
                'headers' => $this->authHeaders(),
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : ['error' => 'Invalid response format'];
        } catch (GuzzleException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function login(string $email, string $password): ?string
    {
        try {
            $response = $this->guzzle->post($this->baseUrl . '/api/v1/login', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'email' => $email,
                    'password' => $password,
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            $this->token = $data['data']['token'] ?? null;

            return $this->token;
        } catch (GuzzleException $e) {
            Log::error('Alonomic login failed: ' . $e->getMessage());

            return null;
        } catch (Throwable $e) {
            Log::error('Alonomic login error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];
        if ($this->token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        return $headers;
    }
}
