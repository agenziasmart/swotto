<?php

declare(strict_types=1);

namespace Swotto\Trait;

/**
 * PopTrait.
 *
 * Provides convenient methods for retrieving data from POP endpoints
 */
trait PopTrait
{
    /**
     * Get gender POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getGenderPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], ['public' => true]);

        return $this->fetchPop('open/gender', $query);
    }

    /**
     * Get user role POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getUserRolePop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], ['public' => true]);

        return $this->fetchPop('open/role', $query);
    }

    /**
     * Get me organization data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getMeOrganization(?array $query = []): array
    {
        return $this->fetchPop('me/organization', $query);
    }

    /**
     * Get country POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getCountryPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
          'columns' => 'code,name',
        ]);

        return $this->fetchPop('open/country', $query);
    }

    /**
     * Get system language POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getSysLanguagePop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
          'columns' => 'name,code',
        ]);

        return $this->fetchPop('open/language', $query);
    }

    /**
     * Get currency POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getCurrencyPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
          'columns' => 'code,name',
        ]);

        return $this->fetchPop('open/currency', $query);
    }

    /**
     * Get customer POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getCustomerPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
          'columns' => 'id,name',
        ]);

        return $this->fetchPop('customer', $query);
    }

    /**
     * Get template POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getTemplatePop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
          'columns' => 'uuid,name',
        ]);

        return $this->fetchPop('template', $query);
    }

    /**
     * Get incoterm POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getIncotermPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
          'columns' => 'uuid,name,code',
        ]);

        return $this->fetchPop('open/incoterm', $query);
    }

    /**
     * Get incoterm by code.
     *
     * @param string $code Incoterm code
     * @return array Incoterm data
     */
    public function getIncotermByCode(string $code): array
    {
        return $this->fetchPop('open/incoterm/findByCode', ['code' => $code]);
    }

    /**
     * Get carrier POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getCarrierPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
          'columns' => 'id,name',
        ]);

        return $this->fetchPop('carrier', $query);
    }

    /**
     * Get category POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getCategoryPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
        ]);

        return $this->fetchPop('category', $query);
    }

    /**
     * Get supplier POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getSupplierPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
          'columns' => 'id,name',
        ]);

        return $this->fetchPop('supplier', $query);
    }

    /**
     * Get warehouse POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getWarehousePop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
        ]);

        return $this->fetchPop('warehouse', $query);
    }

    /**
     * Get warehouse zone POP data by warehouse ID.
     *
     * @param int $id Warehouse ID
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getWarehouseZonePop(int $id, ?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
        ]);

        return $this->fetchPop("warehouse/{$id}/zone", $query);
    }

    /**
     * Get project POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getProjectPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
        ]);

        return $this->fetchPop('project/qpop', $query);
    }

    /**
     * Get product POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getProductPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
          'columns' => 'id,name',
        ]);

        return $this->fetchPop('product', $query);
    }

    /**
     * Get payment type POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getPaymentType(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
        ]);

        return $this->fetchPop('payment/type', $query);
    }

    /**
     * Get agreement POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getAgreementPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
        ]);

        return $this->fetchPop('agreement', $query);
    }

    /**
     * Get warehouse reason POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getWhsreasonPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
        ]);

        return $this->fetchPop('whsreason', $query);
    }

    /**
     * Get warehouse inbound POP data.
     *
     * @return array POP data
     */
    public function getWhsinboundPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'created_on',
        ]);

        return $this->fetchPop('whsinbound/qpop', $query);
    }

    /**
     * Get warehouse order POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getWhsorderPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'created_on',
        ]);

        return $this->fetchPop('whsorder', $query);
    }

    /**
     * Get family POP data.
     *
     * @param array|null $query Additional query parameters
     * @return array POP data
     */
    public function getFamilyPop(?array $query = []): array
    {
        $query = \array_merge($query ?? [], [
          'limit' => 0,
          'orderby' => 'name',
        ]);

        return $this->fetchPop('family', $query);
    }

    /**
     * Get ship type POP data.
     *
     * @return array POP data
     */
    public function getShiptypePop(): array
    {
        return [
          ['id' => 1, 'name' => 'Vettore'],
          ['id' => 2, 'name' => 'Mittente'],
          ['id' => 3, 'name' => 'Destinatario'],
        ];
    }
}
