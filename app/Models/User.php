<?php

namespace App\Models;

use App\Support\SafeEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\BankAccount;
use App\Models\AffiliateWalletLog;
use App\Models\AffiliateProfile;
use App\Models\ClientUserVerification;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $appends = [
        'ref_code',
    ];

    public const ACTIVE_STATUSES = ['active'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'full_name',
        'avatar',
        'zalo_id',
        'phone',
        'address',
        'status',
        'gps_location',
        'gps_address',
        'role',
        'password',
        'total_tickets_purchased',
        'total_tickets_used',
        'total_wins',
        'total_orders',
        'last_active_at',
        'last_login_at',
        // Profile fields
        'bio',
        'gender',
        'birthday',
        'personal_full_name',
        'personal_identity_number',
        'personal_address',
        // Affiliate fields
        'referrer_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
        'total_tickets_purchased' => 'integer',
        'total_tickets_used' => 'integer',
        'total_wins' => 'integer',
        'total_orders' => 'integer',
        'birthday' => 'date',
        'last_active_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function vouchers()
    {
        return $this->belongsToMany(Voucher::class, 'user_vouchers')
            ->withTimestamps()
            ->withPivot('used_at');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function roomsWon()
    {
        return $this->hasMany(Room::class, 'winner_id');
    }

    public function roleBindings()
    {
        return $this->hasMany(UserRoleBinding::class, 'user_id');
    }

    public function legacyRoles()
    {
        return $this->belongsToMany(Role::class, 'user_role_bindings', 'user_id', 'role_id')
            ->withPivot(['entity_type', 'entity_id'])
            ->withTimestamps();
    }

    public function bankAccount()
    {
        return $this->hasOne(BankAccount::class, 'user_id', 'id');
    }

    public function walletLogs()
    {
        return $this->hasMany(AffiliateWalletLog::class, 'user_id', 'id');
    }

    public function affiliateProfile()
    {
        return $this->hasOne(AffiliateProfile::class, 'user_id', 'id');
    }

    public function clientVerification()
    {
        return $this->hasOne(ClientUserVerification::class, 'user_id', 'id');
    }

    public function specialistProfile()
    {
        return $this->hasOne(Specialist::class, 'user_id', 'id');
    }

    public function storePreferences()
    {
        return $this->hasMany(UserStorePreference::class);
    }

    public function productFavorites()
    {
        return $this->hasMany(UserProductFavorite::class, 'user_id');
    }

    public function favoriteProducts()
    {
        return $this->belongsToMany(Product::class, 'user_product_favorites', 'user_id', 'product_id')
            ->withTimestamps();
    }

    public function storeStaffPermissions()
    {
        return $this->hasMany(StoreStaffPermission::class, 'user_id');
    }

    public function isActiveForAuth(): bool
    {
        $status = strtolower(trim((string) ($this->status ?? 'active')));

        return $status === '' || in_array($status, self::ACTIVE_STATUSES, true);
    }

    public function assignedStores()
    {
        return $this->hasMany(Store::class, 'sale_id');
    }

    public function routeNotificationForMail($notification = null): ?string
    {
        return SafeEmail::isValid($this->email)
            ? SafeEmail::normalize($this->email)
            : null;
    }

    public static function resolveReferrerId($ref)
    {
        if (empty($ref)) {
            return null;
        }

        $profile = \App\Models\AffiliateProfile::where('affiliate_code', $ref)->first();
        if ($profile) {
            return $profile->user_id;
        }

        if (is_numeric($ref)) {
            $user = self::find($ref);
            if ($user) {
                return $user->id;
            }
        }

        return null;
    }

    public function getRefCodeAttribute()
    {
        if ($this->relationLoaded('affiliateProfile')) {
            return $this->affiliateProfile?->affiliate_code ?? ('GT' . $this->id);
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('affiliate_profiles')) {
            $profile = \App\Models\AffiliateProfile::where('user_id', $this->id)->first();
            return $profile?->affiliate_code ?? ('GT' . $this->id);
        }

        return 'GT' . $this->id;
    }
}
