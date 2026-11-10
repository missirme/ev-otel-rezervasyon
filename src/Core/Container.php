<?php

declare(strict_types=1);

namespace App\Core;

use App\Controller\AdminController;
use App\Controller\ApiController;
use App\Controller\AuthController;
use App\Controller\ComplaintController;
use App\Controller\Controller;
use App\Controller\HomeController;
use App\Controller\HostController;
use App\Controller\MailboxController;
use App\Controller\ProfileController;
use App\Controller\PropertyController;
use App\Controller\ReservationController;
use App\Controller\ReviewController;
use App\Repository\AmenityRepositoryInterface;
use App\Repository\AuditLogRepositoryInterface;
use App\Repository\BookableUnitRepositoryInterface;
use App\Repository\CalendarRepositoryInterface;
use App\Repository\ComplaintRepositoryInterface;
use App\Repository\DataSnapshotInterface;
use App\Repository\FavoriteRepositoryInterface;
use App\Repository\Json\JsonAmenityRepository;
use App\Repository\Json\JsonAuditLogRepository;
use App\Repository\Json\JsonBookableUnitRepository;
use App\Repository\Json\JsonCalendarRepository;
use App\Repository\Json\JsonComplaintRepository;
use App\Repository\Json\JsonDataSnapshot;
use App\Repository\Json\JsonDatabase;
use App\Repository\Json\JsonFavoriteRepository;
use App\Repository\Json\JsonLoginAttemptRepository;
use App\Repository\Json\JsonNotificationRepository;
use App\Repository\Json\JsonPasswordResetTokenRepository;
use App\Repository\Json\JsonPaymentRepository;
use App\Repository\Json\JsonPhotoRepository;
use App\Repository\Json\JsonPriceRuleRepository;
use App\Repository\Json\JsonPricingRuleRepository;
use App\Repository\Json\JsonPropertyRepository;
use App\Repository\Json\JsonReservationRepository;
use App\Repository\Json\JsonReviewRepository;
use App\Repository\Json\JsonTransactionManager;
use App\Repository\Json\JsonUserRepository;
use App\Repository\LoginAttemptRepositoryInterface;
use App\Repository\NotificationRepositoryInterface;
use App\Repository\PasswordResetTokenRepositoryInterface;
use App\Repository\PaymentRepositoryInterface;
use App\Repository\PhotoRepositoryInterface;
use App\Repository\PriceRuleRepositoryInterface;
use App\Repository\PricingRuleRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\ReservationRepositoryInterface;
use App\Repository\ReviewRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Repository\UserRepositoryInterface;
use App\Router;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\AvailabilityService;
use App\Service\BackupService;
use App\Service\BookingConflictService;
use App\Service\ComplaintService;
use App\Service\ConsistencyAuditService;
use App\Service\FavoriteService;
use App\Service\Mail\FileMailbox;
use App\Service\Mail\FileMailer;
use App\Service\Mail\MailerInterface;
use App\Service\Mail\SmtpMailer;
use App\Service\NotificationService;
use App\Service\PaymentService;
use App\Service\PhotoService;
use App\Service\PriceRuleService;
use App\Service\PricingService;
use App\Service\PropertyService;
use App\Service\RefundService;
use App\Service\ReportService;
use App\Service\ReservationService;
use App\Service\ReservationStateMachine;
use App\Service\ReviewService;
use App\Service\ScheduledTaskService;
use App\Service\UserService;
use App\Support\ClockInterface;
use App\Support\Logger;
use App\Support\Settings;
use App\Support\SystemClock;
use InvalidArgumentException;

/**
 * Nesnelerin birbirine bağlandığı tek yer (composition root).
 * Depo (repository) uygulaması yalnızca burada seçilir: Aşama 2'de JSON (Aşama 3'te MySQL eklenecek);
 * servisler ve controller'lar değişmez (tasarim.md kontrol sorusu).
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(
        public readonly Settings $settings,
        public readonly ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config, ?ClockInterface $clock = null): self
    {
        $settings = Settings::fromArray($config);
        date_default_timezone_set($settings->timezone);

        return new self($settings, $clock ?? new SystemClock($settings->timezone));
    }

    /**
     * @template T of object
     * @param callable(): T $factory
     * @return T
     */
    private function once(string $key, callable $factory): object
    {
        /** @var T */
        return $this->instances[$key] ??= $factory();
    }

    // ------------------------------------------------------------ altyapı

    /** Aşama 2 veri deposu: storage/data altındaki JSON tablo dosyaları (varsayılan). */
    public function jsonDatabase(): JsonDatabase
    {
        return $this->once('jsonDatabase', fn (): JsonDatabase => new JsonDatabase(
            $this->settings->dataDir,
            null,
            $this->settings->lockTimeoutSeconds,
            $this->settings->lockRetries,
        ));
    }

    public function tx(): TransactionManagerInterface
    {
        return $this->once('tx', fn (): TransactionManagerInterface => new JsonTransactionManager($this->jsonDatabase()));
    }

    /** Tüm kayıtları siler, tablo dosyalarını korur (örnek veri yüklemeden önce ve testlerde). */
    public function resetStorage(): void
    {
        $this->jsonDatabase()->truncateAll();
    }

    /** Sunumda boş veriyle demo yapılmaz: depo boşsa (hiç kullanıcı yoksa) örnek veri yüklenmelidir. */
    public function needsDemoData(): bool
    {
        return !is_file($this->settings->dataDir . '/users.json');
    }

    /**
     * Repository deseni: servisler yalnızca depo arayüzünü bilir. Aşama 2'de her arayüzün JSON uygulaması
     * kullanılır; Aşama 3'te aynı arayüzün MySQL uygulaması eklenip seçim yalnızca burada yapılacaktır.
     *
     * @template T of object
     * @param class-string<T> $interface
     * @param class-string<T> $json
     * @return T
     */
    private function repository(string $interface, string $json): object
    {
        return $this->once($interface, fn (): object => new $json($this->jsonDatabase()));
    }

    public function logger(): Logger
    {
        return $this->once('logger', fn (): Logger => new Logger($this->settings->logFile, $this->clock));
    }

    // ------------------------------------------------------------ depolar (Aşama 2: JSON)

    public function users(): UserRepositoryInterface
    {
        return $this->repository(UserRepositoryInterface::class, JsonUserRepository::class);
    }

    public function loginAttempts(): LoginAttemptRepositoryInterface
    {
        return $this->repository(LoginAttemptRepositoryInterface::class, JsonLoginAttemptRepository::class);
    }

    public function resetTokens(): PasswordResetTokenRepositoryInterface
    {
        return $this->repository(PasswordResetTokenRepositoryInterface::class, JsonPasswordResetTokenRepository::class);
    }

    public function properties(): PropertyRepositoryInterface
    {
        return $this->repository(PropertyRepositoryInterface::class, JsonPropertyRepository::class);
    }

    public function units(): BookableUnitRepositoryInterface
    {
        return $this->repository(BookableUnitRepositoryInterface::class, JsonBookableUnitRepository::class);
    }

    public function amenities(): AmenityRepositoryInterface
    {
        return $this->repository(AmenityRepositoryInterface::class, JsonAmenityRepository::class);
    }

    public function photos(): PhotoRepositoryInterface
    {
        return $this->repository(PhotoRepositoryInterface::class, JsonPhotoRepository::class);
    }

    public function calendar(): CalendarRepositoryInterface
    {
        return $this->repository(CalendarRepositoryInterface::class, JsonCalendarRepository::class);
    }

    public function priceRules(): PriceRuleRepositoryInterface
    {
        return $this->repository(PriceRuleRepositoryInterface::class, JsonPriceRuleRepository::class);
    }

    public function pricingRules(): PricingRuleRepositoryInterface
    {
        return $this->repository(PricingRuleRepositoryInterface::class, JsonPricingRuleRepository::class);
    }

    public function reservations(): ReservationRepositoryInterface
    {
        return $this->repository(ReservationRepositoryInterface::class, JsonReservationRepository::class);
    }

    public function payments(): PaymentRepositoryInterface
    {
        return $this->repository(PaymentRepositoryInterface::class, JsonPaymentRepository::class);
    }

    public function reviews(): ReviewRepositoryInterface
    {
        return $this->repository(ReviewRepositoryInterface::class, JsonReviewRepository::class);
    }

    public function favorites(): FavoriteRepositoryInterface
    {
        return $this->repository(FavoriteRepositoryInterface::class, JsonFavoriteRepository::class);
    }

    public function notifications(): NotificationRepositoryInterface
    {
        return $this->repository(NotificationRepositoryInterface::class, JsonNotificationRepository::class);
    }

    public function complaints(): ComplaintRepositoryInterface
    {
        return $this->repository(ComplaintRepositoryInterface::class, JsonComplaintRepository::class);
    }

    public function auditLogs(): AuditLogRepositoryInterface
    {
        return $this->repository(AuditLogRepositoryInterface::class, JsonAuditLogRepository::class);
    }

    // ------------------------------------------------------------ servisler

    public function auditService(): AuditService
    {
        return $this->once('auditService', fn (): AuditService => new AuditService($this->auditLogs(), $this->clock));
    }

    public function notificationService(): NotificationService
    {
        return $this->once('notificationService', fn (): NotificationService => new NotificationService($this->notifications(), $this->clock));
    }

    public function paymentService(): PaymentService
    {
        return $this->once('paymentService', fn (): PaymentService => new PaymentService($this->payments(), $this->clock));
    }

    public function stateMachine(): ReservationStateMachine
    {
        return $this->once('stateMachine', fn (): ReservationStateMachine => new ReservationStateMachine(
            $this->reservations(),
            $this->auditService(),
            $this->clock,
            $this->logger(),
        ));
    }

    public function bookingConflictService(): BookingConflictService
    {
        return $this->once('bookingConflictService', fn (): BookingConflictService => new BookingConflictService(
            $this->reservations(),
            $this->calendar(),
            $this->stateMachine(),
            $this->paymentService(),
            $this->notificationService(),
            $this->clock,
            $this->settings,
            $this->logger(),
        ));
    }

    public function availabilityService(): AvailabilityService
    {
        return $this->once('availabilityService', fn (): AvailabilityService => new AvailabilityService(
            $this->bookingConflictService(),
            $this->units(),
            $this->properties(),
            $this->calendar(),
            $this->reservations(),
            $this->tx(),
            $this->auditService(),
            $this->clock,
        ));
    }

    public function pricingService(): PricingService
    {
        return $this->once('pricingService', fn (): PricingService => new PricingService($this->priceRules(), $this->pricingRules(), $this->calendar()));
    }

    public function priceRuleService(): PriceRuleService
    {
        return $this->once('priceRuleService', fn (): PriceRuleService => new PriceRuleService(
            $this->properties(),
            $this->units(),
            $this->priceRules(),
            $this->pricingRules(),
            $this->tx(),
            $this->auditService(),
        ));
    }

    public function refundService(): RefundService
    {
        return $this->once('refundService', fn (): RefundService => new RefundService());
    }

    public function reservationService(): ReservationService
    {
        return $this->once('reservationService', fn (): ReservationService => new ReservationService(
            $this->tx(),
            $this->reservations(),
            $this->units(),
            $this->properties(),
            $this->users(),
            $this->reviews(),
            $this->bookingConflictService(),
            $this->pricingService(),
            $this->refundService(),
            $this->paymentService(),
            $this->notificationService(),
            $this->stateMachine(),
            $this->auditService(),
            $this->clock,
            $this->settings,
            $this->logger(),
        ));
    }

    public function propertyService(): PropertyService
    {
        return $this->once('propertyService', fn (): PropertyService => new PropertyService(
            $this->properties(),
            $this->units(),
            $this->amenities(),
            $this->photos(),
            $this->reviews(),
            $this->users(),
            $this->favorites(),
            $this->reservations(),
            $this->availabilityService(),
            $this->bookingConflictService(),
            $this->pricingService(),
            $this->refundService(),
            $this->reservationService(),
            $this->notificationService(),
            $this->auditService(),
            $this->tx(),
            $this->clock,
            $this->settings,
        ));
    }

    /** FR-04 e-postası: varsayılan simüle posta kutusu (storage/mail); 'smtp' seçilirse gerçek gönderim. */
    public function mailer(): MailerInterface
    {
        return $this->once('mailer', fn (): MailerInterface => $this->settings->usesSmtp()
            ? $this->smtpMailer()
            : new FileMailer($this->settings->mailDir, $this->clock));
    }

    public function smtpMailer(): SmtpMailer
    {
        $s = $this->settings;

        return $this->once('smtpMailer', fn (): SmtpMailer => new SmtpMailer(
            $s->smtpHost,
            $s->smtpPort,
            $s->smtpSecure,
            $s->smtpUser,
            $s->smtpPassword,
            $s->mailFrom !== '' ? $s->mailFrom : $s->smtpUser,
            $s->mailFromName,
            $this->clock,
        ));
    }

    /** Geliştirme: simüle iletileri okur (/posta-kutusu). SMTP seçiliyse iletiler gerçek kutuya gittiği için kapalıdır. */
    public function mailbox(): FileMailbox
    {
        return $this->once('mailbox', fn (): FileMailbox => new FileMailbox(
            $this->settings->mailDir,
            $this->settings->mailInbox && !$this->settings->usesSmtp(),
        ));
    }

    public function authService(): AuthService
    {
        return $this->once('authService', fn (): AuthService => new AuthService(
            $this->users(),
            $this->loginAttempts(),
            $this->resetTokens(),
            $this->mailer(),
            $this->tx(),
            $this->clock,
            $this->settings,
            $this->logger(),
        ));
    }

    public function userService(): UserService
    {
        return $this->once('userService', fn (): UserService => new UserService($this->users(), $this->auditService(), $this->tx()));
    }

    public function favoriteService(): FavoriteService
    {
        return $this->once('favoriteService', fn (): FavoriteService => new FavoriteService($this->favorites(), $this->properties(), $this->tx(), $this->clock));
    }

    public function reviewService(): ReviewService
    {
        return $this->once('reviewService', fn (): ReviewService => new ReviewService(
            $this->reviews(),
            $this->reservations(),
            $this->units(),
            $this->properties(),
            $this->users(),
            $this->auditService(),
            $this->tx(),
            $this->clock,
        ));
    }

    public function complaintService(): ComplaintService
    {
        return $this->once('complaintService', fn (): ComplaintService => new ComplaintService(
            $this->complaints(),
            $this->properties(),
            $this->reservations(),
            $this->units(),
            $this->users(),
            $this->auditService(),
            $this->notificationService(),
            $this->tx(),
            $this->clock,
        ));
    }

    public function reportService(): ReportService
    {
        return $this->once('reportService', fn (): ReportService => new ReportService($this->properties(), $this->units(), $this->reservations()));
    }

    public function photoService(): PhotoService
    {
        return $this->once('photoService', fn (): PhotoService => new PhotoService(
            $this->photos(),
            $this->properties(),
            $this->units(),
            $this->tx(),
            $this->settings->uploadDir,
            $this->settings->uploadMaxBytes,
        ));
    }

    /** Tüm tabloların tutarlı anlık görüntüsü (yedekleme). */
    public function dataSnapshot(): DataSnapshotInterface
    {
        return $this->repository(DataSnapshotInterface::class, JsonDataSnapshot::class);
    }

    /** NFR-09: yedek alma / geri yükleme (depodan bağımsız). */
    public function backupService(): BackupService
    {
        $dir = $this->settings->backupDir !== '' ? $this->settings->backupDir : dirname(__DIR__, 2) . '/storage/backups';

        return $this->once('backupService', fn (): BackupService => new BackupService(
            $this->dataSnapshot(),
            $dir,
            'json',
            $this->clock,
            $this->logger(),
        ));
    }

    public function scheduledTaskService(): ScheduledTaskService
    {
        return $this->once('scheduledTaskService', fn (): ScheduledTaskService => new ScheduledTaskService($this->reservationService(), $this->logger()));
    }

    public function consistencyAuditService(): ConsistencyAuditService
    {
        return $this->once('consistencyAuditService', fn (): ConsistencyAuditService => new ConsistencyAuditService($this->reservations(), $this->bookingConflictService()));
    }

    // ------------------------------------------------------------ web katmanı

    public function session(): Session
    {
        return $this->once('session', fn (): Session => new Session($this->settings->sessionTimeoutMinutes));
    }

    public function csrf(): Csrf
    {
        return $this->once('csrf', fn (): Csrf => new Csrf($this->session()));
    }

    public function auth(): Auth
    {
        return $this->once('auth', fn (): Auth => new Auth($this->session(), $this->users()));
    }

    public function view(string $basePath = ''): View
    {
        return $this->once('view', fn (): View => new View($this->settings->viewsDir, $basePath));
    }

    public function router(): Router
    {
        return $this->once('router', function (): Router {
            $router = new Router();
            $register = require dirname(__DIR__, 2) . '/config/routes.php';
            $register($router);

            return $router;
        });
    }

    /** @param class-string $class */
    public function controller(string $class): Controller
    {
        $v = $this->view();
        $s = $this->session();
        $a = $this->auth();

        return match ($class) {
            ApiController::class => new ApiController($v, $s, $a, $this->reservationService(), $this->clock),
            HomeController::class => new HomeController($v, $s, $a, $this->propertyService()),
            AuthController::class => new AuthController($v, $s, $a, $this->authService()),
            MailboxController::class => new MailboxController($v, $s, $a, $this->mailbox()),
            PropertyController::class => new PropertyController(
                $v,
                $s,
                $a,
                $this->propertyService(),
                $this->availabilityService(),
                $this->photoService(),
                $this->clock,
            ),
            ReservationController::class => new ReservationController($v, $s, $a, $this->reservationService(), $this->clock),
            ReviewController::class => new ReviewController($v, $s, $a, $this->reviewService(), $this->reservationService()),
            ProfileController::class => new ProfileController(
                $v,
                $s,
                $a,
                $this->userService(),
                $this->authService(),
                $this->favoriteService(),
                $this->propertyService(),
                $this->notificationService(),
            ),
            ComplaintController::class => new ComplaintController($v, $s, $a, $this->complaintService()),
            HostController::class => new HostController(
                $v,
                $s,
                $a,
                $this->propertyService(),
                $this->availabilityService(),
                $this->priceRuleService(),
                $this->pricingService(),
                $this->photoService(),
                $this->reservationService(),
                $this->reportService(),
                $this->clock,
            ),
            AdminController::class => new AdminController(
                $v,
                $s,
                $a,
                $this->userService(),
                $this->propertyService(),
                $this->complaintService(),
                $this->reviewService(),
                $this->reportService(),
                $this->auditService(),
                $this->scheduledTaskService(),
                $this->consistencyAuditService(),
                $this->clock,
                $this->settings,
            ),
            default => throw new InvalidArgumentException('Bilinmeyen controller: ' . $class),
        };
    }
}
