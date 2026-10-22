<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/** Infers roles from Laravel's conventional directory layout — app/Http/Controllers and friends. */
final readonly class LaravelPathRoleRule implements ClassificationRule
{
    /** The directory observers live in, which EloquentConventionRule defers to. */
    public const OBSERVER_FRAGMENT = '/Observers/';

    /** The model events Eloquent calls an observer's methods for. */
    public const OBSERVER_METHODS = [
        'retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting',
        'deleted', 'trashed', 'forceDeleting', 'forceDeleted', 'restoring', 'restored', 'replicating',
    ];

    private const PATH_ROLES = [
        '/Http/Controllers/' => 'laravel.controller',
        // A module keeps its own `Middleware` directory beside `Http/Middleware`.
        '/Middleware/' => 'laravel.middleware',
        '/Console/Commands/' => 'laravel.command',
        '/Jobs/' => 'laravel.job',
        '/Events/' => 'laravel.event',
        '/Listeners/' => 'laravel.listener',
        '/Providers/' => 'laravel.provider',
        '/Policies/' => 'laravel.policy',
        '/Models/' => 'laravel.model',
        self::OBSERVER_FRAGMENT => 'laravel.observer',
        '/Repositories/' => 'laravel.repository',
        // Run by `artisan migrate`, `db:seed` and model factories, which find
        // them by directory; nothing references them by name.
        '/database/migrations/' => 'laravel.migration',
        '/database/seeders/' => 'laravel.seeder',
        '/database/seeds/' => 'laravel.seeder',
        '/database/factories/' => 'laravel.factory',
    ];

    /**
     * The role the directory convention gives a file, by the first fragment of its path that matches.
     *
     * Other rules ask this to see whether the directory already claims a node.
     */
    public static function roleForPath(string $relativePath): ?string
    {
        $fragment = self::fragmentFor($relativePath);

        return $fragment === null ? null : self::PATH_ROLES[$fragment];
    }

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'laravel.paths.v1';
    }

    /** Class role => the methods Laravel itself calls on such a class. */
    private const ENTRY_METHODS = [
        'laravel.middleware' => ['handle', 'terminate'],
        'laravel.job' => ['handle', 'failed', 'middleware', 'retryUntil', 'backoff', '__invoke'],
        'laravel.listener' => ['handle', 'failed', 'shouldQueue', '__invoke'],
        'laravel.command' => ['handle', '__invoke'],
        // The Gate calls these by ability name: `authorize('view', $device)`.
        'laravel.observer' => self::OBSERVER_METHODS,
        'laravel.policy' => ['before', 'after', 'viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'],
    ];

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        if ($node->kind === 'method') {
            return $this->entryMethod($node);
        }
        if ($node->kind !== 'class') {
            return [];
        }
        $fragment = self::fragmentFor($node->evidence->relativePath);

        return $fragment === null ? [] : [new ClassificationFact(
            $node->localId,
            self::PATH_ROLES[$fragment],
            $this->id(),
            Origin::FrameworkConvention,
            Confidence::Probable,
            $node->evidence,
            ['matched_path' => $fragment],
        )];
    }

    /**
     * The method the framework calls on a middleware, job, listener, command or policy.
     *
     * The class is a convention; its entry method is named by nothing in the
     * project either, so it gets a convention role of its own.
     *
     * @return list<ClassificationFact>
     */
    private function entryMethod(NodeFact $node): array
    {
        $fragment = self::fragmentFor($node->evidence->relativePath);
        if ($fragment === null) {
            return [];
        }
        $role = self::PATH_ROLES[$fragment];
        if (!in_array($node->displayName, self::ENTRY_METHODS[$role] ?? [], true)) {
            return [];
        }

        return [new ClassificationFact(
            $node->localId,
            'laravel.entry_method',
            $this->id(),
            Origin::FrameworkConvention,
            Confidence::Probable,
            $node->evidence,
            ['matched_path' => $fragment, 'class_role' => $role],
        )];
    }

    /** The first directory fragment of {@see PATH_ROLES} found in the path; the order of that list decides. */
    private static function fragmentFor(string $relativePath): ?string
    {
        $path = '/' . ltrim($relativePath, '/');
        foreach (array_keys(self::PATH_ROLES) as $fragment) {
            if (str_contains($path, $fragment)) {
                return $fragment;
            }
        }

        return null;
    }
}
