<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Methods a framework base class calls on its subclasses by name.
 *
 * The base is vendor code, outside the graph, so the call that reaches the
 * hook is never seen: every FormRequest's `rules` and every migration's `up`
 * has an in-degree of zero however live it is. A method by one of these names,
 * on a type with the matching base among its ancestors, is fulfilling that
 * base's contract the same way an override of a scanned supertype is.
 */
final class FrameworkHookMethods
{
    /** Base type canonical name => the members it calls on a subtype. */
    private const HOOKS = [
        'Illuminate\\Foundation\\Http\\FormRequest' => ['authorize', 'rules', 'messages', 'attributes', 'withValidator', 'after', 'prepareForValidation', 'passedValidation', 'failedValidation', 'failedAuthorization'],
        'Illuminate\\Http\\Resources\\Json\\JsonResource' => ['toArray', 'with', 'withResponse', 'paginationInformation'],
        'Illuminate\\Http\\Resources\\Json\\ResourceCollection' => ['toArray', 'with', 'withResponse', 'paginationInformation'],
        'Illuminate\\Database\\Eloquent\\Model' => ['casts', 'boot', 'booted', 'newFactory', 'newCollection', 'newEloquentBuilder', 'getRouteKeyName', 'resolveRouteBinding', 'uniqueIds', 'broadcastOn', 'prunable'],
        'Illuminate\\Foundation\\Auth\\User' => ['casts', 'boot', 'booted', 'newFactory', 'newCollection', 'newEloquentBuilder', 'getRouteKeyName', 'resolveRouteBinding', 'uniqueIds', 'broadcastOn', 'prunable'],
        'Illuminate\\Database\\Eloquent\\Relations\\Pivot' => ['casts', 'boot', 'booted', 'newFactory'],
        'Illuminate\\Database\\Eloquent\\Factories\\Factory' => ['definition', 'configure'],
        'Illuminate\\Database\\Migrations\\Migration' => ['up', 'down'],
        'Illuminate\\Database\\Seeder' => ['run'],
        'Illuminate\\Support\\ServiceProvider' => ['register', 'boot', 'provides'],
        'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider' => ['register', 'boot', 'provides', 'shouldDiscoverEvents', 'discoverEventsWithin'],
        'Illuminate\\Foundation\\Support\\Providers\\RouteServiceProvider' => ['register', 'boot', 'map'],
        'Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider' => ['register', 'boot'],
        'Illuminate\\Console\\Command' => ['handle', '__invoke'],
        'PHPOpenSourceSaver\\JWTAuth\\Contracts\\JWTSubject' => ['getJWTIdentifier', 'getJWTCustomClaims'],
        'Tymon\\JWTAuth\\Contracts\\JWTSubject' => ['getJWTIdentifier', 'getJWTCustomClaims'],
        // Model traits: what each calls on the model that uses it.
        'Illuminate\\Database\\Eloquent\\Factories\\HasFactory' => ['newFactory'],
        'Illuminate\\Database\\Eloquent\\Concerns\\HasUuids' => ['newUniqueId', 'uniqueIds'],
        'Illuminate\\Database\\Eloquent\\Concerns\\HasUlids' => ['newUniqueId', 'uniqueIds'],
        'Illuminate\\Database\\Eloquent\\SoftDeletes' => ['getDeletedAtColumn'],
        'Illuminate\\Notifications\\Notifiable' => ['routeNotificationForMail', 'routeNotificationForDatabase', 'routeNotificationForBroadcast', 'receivesBroadcastNotificationsOn', 'preferredLocale'],
    ];

    /**
     * Whether `$type` (a canonical name) is a base whose hooks this list
     * knows, so that it hides no member of a subtype beyond them.
     */
    public static function isKnownBase(string $type): bool
    {
        return isset(self::HOOKS[ltrim($type, '\\')]);
    }

    /**
     * Whether one of `$ancestors` (canonical names) calls a member named `$name`.
     *
     * @param list<string> $ancestors
     */
    public static function isHook(array $ancestors, string $name): bool
    {
        foreach ($ancestors as $ancestor) {
            if (in_array($name, self::HOOKS[ltrim($ancestor, '\\')] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
