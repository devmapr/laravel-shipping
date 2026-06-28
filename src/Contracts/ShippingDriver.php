<?php

declare(strict_types=1);

namespace Planx\Shipping\Contracts;

interface ShippingDriver
{
    /**
     * Create a parcel/shipment at the provider.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function createParcel(array $payload): array;

    /**
     * Update a parcel by ID.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function updateParcel(int|string $id, array $payload): array;

    /**
     * Get a parcel by ID.
     *
     * @return array<string,mixed>
     */
    public function getParcel(int|string $id): array;

    /**
     * Delete a parcel by ID.
     *
     * @return array<string,mixed>
     */
    public function deleteParcel(int|string $id): array;

    /**
     * Get available working days.
     *
     * @return array<string,mixed>
     */
    public function getDays(): array;

    /**
     * Optional: Get next 7 days structure when available.
     *
     * @return array<string,mixed>
     */
    public function getDaysIndex(): array;
}
