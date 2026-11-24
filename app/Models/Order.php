<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'user_id',
        'branch_id',
        'order_date',
        'total_amount',
        'payment_method',
        'order_status',
        'cashier_employee_no',
        'stock_sync_status',
    ];

    protected $attributes = [
        'order_status' => 'Pending',
        'stock_sync_status' => 'not_sent',
        'total_amount' => 0,
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'total_amount' => 'decimal:2'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_date)) {
                $order->order_date = now();
            }
        });

        static::created(function ($order) {
            $order->transaction_number = sprintf('TXN-%s-%06d', $order->order_date->format('Ymd'), $order->id);
            $order->saveQuietly();
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}
