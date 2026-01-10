<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\SecretRepositoryInterface;
use App\Repositories\SecretRepository;

/**
 * REPOSITORY SERVICE PROVIDER
 * 
 * PURPOSE: Binds interfaces to concrete implementations
 * 
 * WHY THIS EXISTS:
 * When service says "I need SecretRepositoryInterface",
 * Laravel needs to know which concrete class to provide.
 * This provider tells Laravel: "When someone asks for the interface,
 * give them the concrete implementation."
 * 
 * DEPENDENCY INJECTION FLOW:
 * 1. SecretService constructor needs SecretRepositoryInterface
 * 2. Laravel's IoC container checks bindings
 * 3. Finds: Interface → SecretRepository mapping (registered here)
 * 4. Creates SecretRepository instance
 * 5. Injects into SecretService
 * 
 * WITHOUT THIS PROVIDER:
 * Error: "Target [SecretRepositoryInterface] is not instantiable"
 * 
 * WITH THIS PROVIDER:
 * Laravel knows: Interface = SecretRepository class
 * 
 * ANALOGY:
 * Interface = "I need a car"
 * This provider = "When someone needs a car, give them a Tesla"
 * Concrete class = Tesla (the actual implementation)
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * REGISTER SERVICES
     * 
     * PURPOSE: Register bindings in the IoC container
     * 
     * WHEN THIS RUNS:
     * - During application bootstrap
     * - Before any requests are handled
     * - Registers all bindings for dependency injection
     * 
     * METHOD EXPLANATION:
     * $this->app->bind(Interface, Implementation)
     * 
     * WHAT IT DOES:
     * "Whenever someone requests the Interface,
     *  create and return an instance of Implementation"
     * 
     * EXAMPLE FLOW:
     * 
     * 1. Service Constructor:
     *    public function __construct(SecretRepositoryInterface $repo)
     * 
     * 2. Laravel Sees Type Hint:
     *    "I need SecretRepositoryInterface"
     * 
     * 3. Laravel Checks Container:
     *    "What's bound to SecretRepositoryInterface?"
     * 
     * 4. Finds This Binding:
     *    SecretRepositoryInterface → SecretRepository
     * 
     * 5. Laravel Creates Instance:
     *    $repo = new SecretRepository();
     * 
     * 6. Injects Into Constructor:
     *    new SecretService($repo)
     * 
     * WHY bind() NOT singleton():
     * - bind(): New instance every time
     * - singleton(): Same instance every time
     * - For repositories, new instances are fine
     * - Each instance is stateless anyway
     * 
     * COULD USE singleton() IF:
     * - Repository has expensive initialization
     * - Need to share state across requests (rare)
     * - Want performance optimization
     * 
     * Example with singleton:
     * $this->app->singleton(
     *     SecretRepositoryInterface::class,
     *     SecretRepository::class
     * );
     */
    public function register(): void
    {
        /**
         * BIND INTERFACE TO IMPLEMENTATION
         * 
         * First parameter: The interface (contract)
         * Second parameter: The concrete class
         * 
         * RESULT:
         * Anywhere in the app, when you type-hint:
         * SecretRepositoryInterface $repository
         * 
         * Laravel automatically provides:
         * new SecretRepository()
         * 
         * BENEFITS:
         * 1. TESTABILITY: Can mock interface in tests
         * 2. FLEXIBILITY: Can swap implementations
         * 3. LOOSE COUPLING: Services don't depend on concrete classes
         * 
         * EXAMPLE SWAP:
         * Want to use Redis instead of MySQL?
         * 
         * Step 1: Create RedisSecretRepository implements SecretRepositoryInterface
         * Step 2: Change binding here to RedisSecretRepository
         * Step 3: Service code doesn't change at all!
         * 
         * Old:
         * SecretRepositoryInterface → SecretRepository (MySQL)
         * 
         * New:
         * SecretRepositoryInterface → RedisSecretRepository (Redis)
         * 
         * SecretService still works without any changes!
         */
        $this->app->bind(
            SecretRepositoryInterface::class,  // When this is requested
            SecretRepository::class             // Provide this
        );
    }

    /**
     * BOOTSTRAP SERVICES
     * 
     * PURPOSE: Perform actions after all services registered
     * 
     * WHEN THIS RUNS:
     * - After register() method of all providers
     * - Before handling requests
     * - Used for setup that depends on other services
     * 
     * COMMON USES:
     * - Event listeners
     * - Route model bindings
     * - View composers
     * - Observing models
     * 
     * NOT NEEDED HERE:
     * - Repository binding is done in register()
     * - No additional bootstrap needed
     * 
     * EXAMPLE IF NEEDED:
     * public function boot(): void
     * {
     *     // Register model events
     *     Secret::observe(SecretObserver::class);
     *     
     *     // Register route model binding
     *     Route::bind('secret', function ($uuid) {
     *         return app(SecretRepositoryInterface::class)
     *             ->findByUuid($uuid) ?? abort(404);
     *     });
     * }
     */
    public function boot(): void
    {
        // No bootstrap actions needed for this provider
    }
}

/**
 * ========================================
 * REGISTERING THIS PROVIDER
 * ========================================
 * 
 * AUTOMATIC (Laravel 11+):
 * Laravel auto-discovers providers in app/Providers/
 * 
 * MANUAL (Laravel 10 or if auto-discovery disabled):
 * Add to config/app.php:
 * 
 * 'providers' => [
 *     // ... other providers
 *     App\Providers\RepositoryServiceProvider::class,
 * ],
 * 
 * ========================================
 * TESTING THE BINDING
 * ========================================
 * 
 * You can test if binding works:
 * 
 * // In tinker or test
 * $repo = app(App\Repositories\Contracts\SecretRepositoryInterface::class);
 * 
 * // Should output: App\Repositories\SecretRepository
 * echo get_class($repo);
 * 
 * ========================================
 * REAL-WORLD USAGE
 * ========================================
 * 
 * SCENARIO 1: Service Layer
 * 
 * class SecretService
 * {
 *     public function __construct(
 *         private SecretRepositoryInterface $repo  // Laravel injects automatically
 *     ) {}
 * }
 * 
 * Laravel automatically:
 * 1. Sees SecretRepositoryInterface type hint
 * 2. Checks this provider's bindings
 * 3. Creates SecretRepository instance
 * 4. Injects it
 * 
 * ========================================
 * 
 * SCENARIO 2: Testing
 * 
 * // In test file
 * $mockRepo = Mockery::mock(SecretRepositoryInterface::class);
 * $this->app->instance(SecretRepositoryInterface::class, $mockRepo);
 * 
 * // Now when service is created, it gets the mock
 * $service = app(SecretService::class);
 * // $service uses $mockRepo instead of real repository
 * 
 * ========================================
 * 
 * SCENARIO 3: Multiple Implementations
 * 
 * What if you need different implementations for different environments?
 * 
 * public function register(): void
 * {
 *     if (config('database.default') === 'redis') {
 *         $this->app->bind(
 *             SecretRepositoryInterface::class,
 *             RedisSecretRepository::class
 *         );
 *     } else {
 *         $this->app->bind(
 *             SecretRepositoryInterface::class,
 *             SecretRepository::class
 *         );
 *     }
 * }
 * 
 * Or for testing:
 * 
 * public function register(): void
 * {
 *     $class = $this->app->environment('testing')
 *         ? InMemorySecretRepository::class
 *         : SecretRepository::class;
 *     
 *     $this->app->bind(
 *         SecretRepositoryInterface::class,
 *         $class
 *     );
 * }
 */