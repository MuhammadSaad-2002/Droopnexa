<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'first_name', 'last_name', 'email', 'username', 'password', 'role', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'username',
        'password',
        'role',
        'status',
    ];

    public function customerProfile()
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function orderRequests()
    {
        return $this->hasMany(OrderRequest::class, 'customer_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class, 'customer_id');
    }

    public function withdrawalRequests()
    {
        return $this->hasMany(WithdrawalRequest::class, 'customer_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSupport(): bool
    {
        return $this->role === 'support';
    }

    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isSupport();
    }

    public function hasStaffPermission(string $permission): bool
    {
        return $this->staffPermissions()[$permission] ?? false;
    }

    /**
     * @return array<string, bool>
     */
    public function staffPermissions(): array
    {
        $isStaff = $this->isStaff();
        $isAdmin = $this->isAdmin();

        return [
            'view_dashboard' => $isStaff,
            'manage_requests' => $isStaff,
            'manage_orders' => $isStaff,
            'view_customers' => $isStaff,
            'view_wallet' => $isStaff,
            'view_profile' => $isStaff,
            'manage_products' => $isAdmin,
            'manage_withdrawals' => $isStaff,
            'manage_settings' => $isAdmin,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
