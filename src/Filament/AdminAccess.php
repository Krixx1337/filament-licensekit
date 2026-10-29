<?php

namespace LicenseKit\Filament;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;

/**
 * Two roles for the admin panel:
 * - licensekit.admin: everything.
 * - licensekit.support: look up customers and fix their licenses (suspend, pause, add time, reset device).
 *
 * Optionally, licensekit.product (user, Product) limits staff to some products. Staff limited this way
 * see only those products' licenses, keys and batches, and none of the screens that cover every product.
 *
 * Hosts redefine any gate in their own service provider; by default every panel user is an admin of every product.
 */
class AdminAccess
{
    public const ADMIN = 'licensekit.admin';

    public const SUPPORT = 'licensekit.support';

    public const PRODUCT = 'licensekit.product';

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && Gate::forUser($user)->allows(self::ADMIN);
    }

    public static function isSupport(): bool
    {
        $user = self::user();

        return $user !== null && (Gate::forUser($user)->allows(self::SUPPORT) || Gate::forUser($user)->allows(self::ADMIN));
    }

    /** Admins of every product; only they reach screens that span all products, such as connections, bans and team access. */
    public static function isOwner(): bool
    {
        return self::isAdmin() && ! self::isScoped();
    }

    public static function isScoped(): bool
    {
        return self::productIds() !== null;
    }

    /**
     * Products the current staff member may see, or null when every product is allowed.
     *
     * @return list<int>|null
     */
    public static function productIds(): ?array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }
        if (! Gate::has(self::PRODUCT)) {
            return null;
        }
        $products = Product::query()->get();
        $allowed = $products->filter(fn (Product $product): bool => Gate::forUser($user)->allows(self::PRODUCT, $product));

        return $allowed->count() === $products->count() ? null : array_values($allowed->map(fn (Product $product): int => (int) $product->id)->all());
    }

    public static function allowsProduct(int|string|null $productId): bool
    {
        $ids = self::productIds();

        return $ids === null || ($productId !== null && in_array((int) $productId, $ids, true));
    }

    public static function allowsRecord(Model $record): bool
    {
        return self::isScoped() === false || self::allowsProduct(match (true) {
            $record instanceof Product => $record->id,
            $record instanceof License, $record instanceof LicensePlan => $record->product_id,
            $record instanceof Batch => $record->plan->product_id,
            $record instanceof LicenseKey => $record->batch->plan->product_id,
            default => null,
        });
    }

    /**
     * Limits a query to the allowed products.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  string|null  $through  Relation leading to a model with product_id, such as "batch.plan".
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, string $column = 'product_id', ?string $through = null): Builder
    {
        $ids = self::productIds();
        if ($ids === null) {
            return $query;
        }

        return $through === null
            ? $query->whereIn($query->qualifyColumn($column), $ids)
            : $query->whereHas($through, fn (Builder $related) => $related->whereIn($related->qualifyColumn($column), $ids));
    }

    /**
     * Products the current staff member may pick, by name.
     *
     * @param  Builder<Product>|null  $query
     * @return array<int, string>
     */
    public static function productOptions(?Builder $query = null): array
    {
        return self::scope($query ?? Product::query(), 'id')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function actor(): Model
    {
        $user = self::user() ?? abort(403);
        auth()->shouldUse(Filament::getAuthGuard());

        return $user;
    }

    private static function user(): ?Model
    {
        $panel = Filament::getCurrentPanel();
        $user = Filament::auth()->user();

        return $panel !== null && $panel->hasPlugin('licensekit') && $user instanceof Model ? $user : null;
    }
}
