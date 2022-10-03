<?php

namespace Etsy\Models;

use App\Services\Etsy;
use App\Services\ProcessPhoto;
use App\Support\Breadcrumb;
use App\Support\MetaObject;
use App\Support\Pivots\FavoriteShopItem;
use App\Support\Pivots\WishlistItem;
use App\Support\Traits\ElasticquentEntity;
use App\Support\Traits\HasMetaTags;
use Carbon\Carbon;
use Elasticquent\ElasticquentInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Class ShopItem
 *
 * @package Etsy\Models
 *
 * @property int                        $id
 * @property int                        $shop_id
 * @property int                        $category_id
 * @property string                     $name
 * @property string                     $original_name
 * @property string                     $slug
 * @property string                     $url - The external URL
 * @property int                        $photo_id
 * @property string                     $description
 * @property int|null                   $etsy_id
 * @property int                        $weight
 * @property Carbon                     $created_at
 * @property Carbon                     $updated_at
 * @property Carbon                     $deleted_at
 *
 * @property string                     $tracked_url
 * @property string                     $internal_url
 * @property string                     $domain
 * @property HtmlString                 $description_html
 *
 * @property Shop                       shop
 * @property ShopCategory               category
 * @property Photo                      photo
 * @property Collection|ShopItemStats[] stats
 * @property Collection|User[]          favoritedByUsers
 */
class ShopItem extends Model
{
    use SoftDeletes;

    protected $table = 'shop_items';

    protected $fillable = [
        'shop_id',
        'name',
        'original_name',
        'url',
        'photo_id',
        'description',
        'etsy_id',
        'weight',
    ];

    protected $metaTitleBase = 'original_name';

    protected $metaDescriptionBase = 'description';

    public $timestamps = true;

    protected array $reserved_slugs = ['stats', 'to'];

    /**
     * @return BelongsTo
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'category_id');
    }

    /**
     * @return HasMany
     */
    public function stats(): HasMany
    {
        return $this->hasMany(ShopItemStats::class);
    }

    /**
     * @return MorphToMany
     */
    public function wishlists(): MorphToMany
    {
        return $this->morphToMany(Wishlist::class, 'entity', 'wishlist_items')
            ->using(WishlistItem::class)
            ->withPivot([
                'weight',
                'added_at',
            ]);
    }

    /**
     * @return BelongsToMany
     */
    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorite_shop_items', 'shop_item_id', 'user_id')
            ->using(FavoriteShopItem::class)
            ->withPivot([
                'shop_id',
                'favorited_at',
            ]);
    }

    /**
     * @return array
     */
    public function statsColumns(): array
    {
        return [
            'shop_id'      => $this->shop_id,
            'shop_item_id' => $this->id,
        ];
    }

    /**
     * @return string
     */
    public function getTrackedUrlAttribute(): string
    {
        return $this->internal_url . '/to?website&url=' . urlencode($this->url);
    }

    /**
     * @return string
     */
    public function getInternalUrlAttribute(): string
    {
        return $this->shop->url . '/' . $this->slug;
    }

    /**
     * @return string
     */
    public function getButtonTextAttribute(): string
    {
        if ($this->domain === 'Barnes & Noble') {
            return "Buy at {$this->domain}";
        }

        return "Buy on {$this->domain}";
    }

    /**
     * @return string|null
     */
    public function getDomainAttribute(): ?string
    {
        $domain = parse_domain($this->url);

        if ($domain === 'prf.hn') {
            return 'Chewy.com';
        } else if ($domain === 'barnesandnoble.com') {
            return 'Barnes & Noble';
        }

        return $domain === null ? null : ucwords($domain);
    }

    /**
     * @return string
     */
    public function getButtonClassAttribute(): string
    {
        return match ($this->domain) {
            'Amazon.com' => 'amazon',
            'Barnes & Noble' => 'bn',
            'Chewy.com' => 'chewy',
            'Etsy.com' => 'etsy',
            default => 'primary',
        };
    }

    /**
     * @return HtmlString
     */
    public function getDescriptionHtmlAttribute(): HtmlString
    {
        return new HtmlString(nl2br(e($this->description)));
    }

    /**
     * @return bool
     */
    public function isSponsored(): bool
    {
        return Str::contains($this->url, 'prf.hn');
    }

    /**
     * Get photo from Etsy
     */
    public function getPhotoFromEtsy()
    {
        if ($this->photo_id) {
            return;
        }

        if (! $this->etsy_id) {
            return;
        }

        $details = (new Etsy())->getListingDetails($this);

        if (count($details['images']) === 0) {
            return;
        }

        foreach ($details['images'] as $image) {
            $url = $image['url_fullxfull'] ?? null;

            if (! $url) {
                continue;
            }

            $photo = (new ProcessPhoto('shops', Auth::user()))
                ->fromUrl($url)
                ->setEntity($this->shop)
                ->run();

            $photo->etsy_id = $image['listing_image_id'] ?? null;
            $photo->save();

            $this->photo_id = $photo->id;
            $this->save();
            break;
        }
    }

    /**
     * Simplified array for Elasticache
     * This is not *just* fields to be indexed, but anything we want it to return
     *
     * Note: id is automatically included
     */
    public function getIndexDocumentData()
    {
        $searchable = trim("{$this->shop->name} {$this->name} {$this->category?->name}");

        return [
            'searchable'  => str_replace('&', ' and ', $searchable),
            'name'        => $this->name,
            'shop_id'     => $this->shop_id,
            'category_id' => $this->category_id,
        ];
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->internal_url;
    }

    /**
     * @return string
     */
    public function canonicalUrl(): ?string
    {
        return url($this->internal_url);
    }
}
