<?php

namespace Jamesmills\LaravelNotificationRateLimit\Tests;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Jamesmills\LaravelNotificationRateLimit\Events\NotificationRateLimitReached;
use Jamesmills\LaravelNotificationRateLimit\RateLimitChannelManager;
use TiMacDonald\Log\LogEntry;
use TiMacDonald\Log\LogFake;

class RateLimitTest extends TestCase
{
    use WithFaker;

    private $user;
    private $otherUser;
    private $customRateLimitKeyUser;
    private $anonymousEmailAddress;
    private $otherAnonymousEmailAddress;

    public function setUp(): void
    {
        parent::setUp();
        Config::set('laravel-notification-rate-limit.should_rate_limit_unique_notifications', false);
        Config::set('laravel-notification-rate-limit.rate_limit_seconds', 10);
        Config::set('mail.default', 'array');

        $this->user = new User(['id' => $this->faker->numberBetween(1, 10000), 'name' => $this->faker->name, 'email' => $this->faker->email]);
        $this->otherUser = new User(['id' => $this->faker->numberBetween(1, 10000), 'name' => $this->faker->name, 'email' => $this->faker->email]);
        $this->customRateLimitKeyUser = new UserWithCustomRateLimitKey([
            'id' => $this->faker->numberBetween(10001, 20000),
            'name' => $this->faker->name,
            'email' => $this->faker->email,
        ]);

        $this->anonymousEmailAddress = $this->faker->freeEmail();
        $this->otherAnonymousEmailAddress = $this->faker->companyEmail();
    }

    /** @test */
    public function it_can_send_a_notification()
    {
        Notification::fake();

        Notification::assertNothingSent();

        Notification::send([$this->user], new TestNotification());

        Notification::assertSentTo([$this->user], TestNotification::class);
        sleep(0.1);
    }

    public function it_can_send_an_anonymous_notification()
    {
        Notification::fake();

        Notification::assertNothingSent();

        Notification::route('mail', $this->anonymousEmailAddress)
            ->notify(new TestNotification());

        Notification::assertSentTo(
            new AnonymousNotifiable(),
            TestNotification::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['mail'] == $this->anonymousEmailAddress;
            }
        );
    }


    /** @test */
    public function it_will_skip_notifications_until_limit_expires()
    {
        Event::fake();
        Notification::fake();

        $this->app->singleton(ChannelManager::class, function ($app) {
            return new RateLimitChannelManager($app);
        });
        // Ensure we are starting clean
        Log::swap(new LogFake);
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );

        // Send first notification and expect it to succeed
        $this->user->notify(new TestNotification());
        Event::assertDispatched(NotificationSent::class);
        Event::assertNotDispatched(NotificationRateLimitReached::class);
        // Send second notification and expect it to be skipped
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
        $this->user->notify(new TestNotification());
        Event::assertDispatched(NotificationRateLimitReached::class);
        Log::assertLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
    }

    /** @test */
    public function it_will_skip_notifications_to_anonymous_users_until_limit_expires()
    {
        Event::fake();
        Notification::fake();

        $this->app->singleton(ChannelManager::class, function ($app) {
            return new RateLimitChannelManager($app);
        });
        // Ensure we are starting clean
        Log::swap(new LogFake);
        Log::assertNotLogged(function (LogEntry $log) { return $log->level == 'notice'; });

        // Send first notification and expect it to succeed
        Notification::route('mail', $this->anonymousEmailAddress)
            ->notify(new TestNotification());
        Event::assertDispatched(NotificationSent::class);
        Event::assertNotDispatched(NotificationRateLimitReached::class);

        // Send second notification and expect it to be skipped
        Log::assertNotLogged(function (LogEntry $log) { return $log->level == 'notice'; });
        Notification::route('mail', $this->anonymousEmailAddress)
            ->notify(new TestNotification());

        Event::assertDispatched(NotificationRateLimitReached::class);
        Log::assertLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
    }

    /** @test */
    public function it_does_not_get_confused_between_multiple_users()
    {
        Event::fake();
        Notification::fake();

        $this->app->singleton(ChannelManager::class, function ($app) {
            return new RateLimitChannelManager($app);
        });
        Config::set('laravel-notification-rate-limit.rate_limit_seconds', 10);

        // Ensure we are starting clean
        Log::swap(new LogFake);
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
        // Send first notification and expect it to succeed
        $this->user->notify(new TestNotification());
        Event::assertDispatched(NotificationSent::class);
        Event::assertNotDispatched(NotificationRateLimitReached::class);
        // Send a notification to another user and expect it to succeed
        $this->otherUser->notify(new TestNotification());
        Event::assertDispatched(NotificationSent::class);
        Event::assertNotDispatched(NotificationRateLimitReached::class);
        // Send a second notice to the first user and expect it to be skipped
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
        $this->user->notify(new TestNotification());
        Event::assertDispatched(NotificationRateLimitReached::class);
        Log::assertLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
    }

    /** @test */
    public function it_does_not_get_confused_between_multiple_anonymous_users()
    {
        Event::fake();
        Notification::fake();

        $this->app->singleton(ChannelManager::class, function ($app) {
            return new RateLimitChannelManager($app);
        });
        Config::set('laravel-notification-rate-limit.rate_limit_seconds', 10);

        // Ensure we are starting clean
        Log::swap(new LogFake);
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );

        // Send first notification and expect it to succeed
        Notification::route('mail', $this->anonymousEmailAddress)
            ->notify(new TestNotification());

        Event::assertDispatched(NotificationSent::class);
        Event::assertNotDispatched(NotificationRateLimitReached::class);

        // Send a notification to another user and expect it to succeed
        Notification::route('mail', $this->otherAnonymousEmailAddress)
            ->notify(new TestNotification());

        Event::assertDispatched(NotificationSent::class);
        Event::assertNotDispatched(NotificationRateLimitReached::class);
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );

        // Send a second notice to the first user and expect it to be skipped
        Notification::route('mail', $this->anonymousEmailAddress)
            ->notify(new TestNotification());

        Event::assertDispatched(NotificationRateLimitReached::class);
        Log::assertLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
    }

    /** @test */
    public function it_will_resume_notifications_after_expiration()
    {
        Event::fake();
        Notification::fake();

        Config::set('laravel-notification-rate-limit.rate_limit_seconds', 0.1);

        $this->app->singleton(ChannelManager::class, function ($app) {
            return new RateLimitChannelManager($app);
        });
        // Ensure we are starting clean.
        Log::swap(new LogFake);
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
        // Send first notification and expect it to succeed.
        $this->user->notify(new TestNotification());
        Event::assertDispatched(NotificationSent::class);
        Event::assertNotDispatched(NotificationRateLimitReached::class);
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
        // Wait until the rate limiter has expired
        sleep(0.1);
        // Send another notification and expect it to succeed.
        Event::assertDispatched(NotificationSent::class);
        Event::assertNotDispatched(NotificationRateLimitReached::class);
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );
    }

    /** @test */
    public function it_will_utilize_custom_rate_limit_keys()
    {
        Event::fake();
        Notification::fake();

        $this->app->singleton(ChannelManager::class, function ($app) {
            return new RateLimitChannelManager($app);
        });
        // Ensure we are starting clean.
        Log::swap(new LogFake);
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );

        // Send notification and expect it to succeed.
        $this->customRateLimitKeyUser->notify(new TestNotification());
        Event::assertDispatched(NotificationSent::class);
        Event::assertNotDispatched(NotificationRateLimitReached::class);
        Log::assertNotLogged(
            fn (LogEntry $log) => $log->level === 'notice'
        );

        // Send a second notification and expect it to fail. Verify that
        // the cache key in use included the 'customKey' value.
        $this->customRateLimitKeyUser->notify(new TestNotification());
        Event::assertDispatched(NotificationRateLimitReached::class);

        Log::assertLogged(
            function (LogEntry $log) {
                $expected_key = Str::lower(config('laravel-notification-rate-limit.key_prefix') . '.TestNotification.customKey');
                return $log->context['key'] === $expected_key;
            }
        );
    }
}
