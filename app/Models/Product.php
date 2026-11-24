<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_name',
        'category',
        'unit_price',
        'sku',
        'description',
        'legacy_sku',
        'status',
        'inv_stock',
        'inv_stock_synced_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'inv_stock' => 'integer',
        'inv_stock_synced_at' => 'datetime',
    ];

    /**
     * May SKU ba ito na galing INV (SKU-0001)? Ang lumang SKU ng CRM ay hindi kilala ng INV.
     */
    public function hasInventorySku(): bool
    {
        return \App\Services\Integration\MasterCodes::isSku($this->sku);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }
}
