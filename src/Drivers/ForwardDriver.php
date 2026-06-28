<?php

declare(strict_types=1);

namespace Planx\Shipping\Drivers;

use Planx\Shipping\Contracts\ShippingDriver;

class ForwardDriver implements ShippingDriver
{
    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function createParcel(array $payload): array
    {
        return ['message' => 'Use ForwardService via order context'];
    }

    public function updateParcel(int|string $id, array $payload): array
    {
        return ['message' => 'Not supported'];
    }

    public function getParcel(int|string $id): array
    {
        return ['message' => 'Not supported'];
    }

    public function deleteParcel(int|string $id): array
    {
        return ['message' => 'Not supported'];
    }

    public function getDays(): array
    {
        return [];
    }

    public function getDaysIndex(): array
    {
        return [];
    }
}
